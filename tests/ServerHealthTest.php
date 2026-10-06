<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/../server/public/metrics/server_health_lib.php';

/**
 * Tests for the server health parsers and alert state machine in
 * server/public/metrics/server_health_lib.php.
 *
 * The parsers read Linux/Apache text formats we can't exercise in CI, so the
 * fixtures below are copied from real output (Ubuntu 24.04, Apache 2.4.58
 * prefork). The alert machine decides when Jeff gets paged, and a bug there
 * shows up as either silence during an outage or spam every minute. Both
 * failure modes are covered.
 */
final class ServerHealthTest extends TestCase
{
    private const MEMINFO = "MemTotal:        8208384 kB\nMemFree:          221184 kB\nMemAvailable:    6614016 kB\nBuffers:          102400 kB\nSwapTotal:       2097148 kB\nSwapFree:        2097148 kB\n";

    #[Test]
    public function parses_meminfo_using_mem_available(): void
    {
        $m = sh_parse_meminfo(self::MEMINFO);
        $this->assertSame(8016, $m['mem_total_mb']);
        $this->assertSame(6459, $m['mem_avail_mb']);
        // Used = 1 - Available/Total, NOT 1 - Free/Total (page cache is reclaimable).
        $this->assertEqualsWithDelta(19.4, $m['mem_used_pct'], 0.1);
        $this->assertSame(0.0, $m['swap_used_pct']);
    }

    #[Test]
    public function meminfo_without_mem_available_returns_null(): void
    {
        $this->assertNull(sh_parse_meminfo("MemTotal: 1000 kB\n"));
        $this->assertNull(sh_parse_meminfo(''));
    }

    #[Test]
    public function cpu_pct_from_two_proc_stat_reads(): void
    {
        $a = sh_parse_proc_stat("cpu  100 0 100 800 0 0 0 0 0 0\ncpu0 1 2 3 4\n");
        $b = sh_parse_proc_stat("cpu  150 0 150 900 0 0 0 0 0 0\n");
        // busy +100, total +200
        $this->assertSame(50.0, sh_cpu_pct($a, $b));
        $this->assertNull(sh_cpu_pct($a, $a)); // no elapsed jiffies
        $this->assertNull(sh_cpu_pct(null, $b));
    }

    #[Test]
    public function parses_mod_status_auto(): void
    {
        $raw = "localhost\nServerVersion: Apache/2.4.58 (Ubuntu)\nServerMPM: prefork\nTotal Accesses: 5123\nReqPerSec: .412345\nBusyWorkers: 12\nIdleWorkers: 8\nScoreboard: __WWK_...\n";
        $s = sh_parse_server_status($raw);
        $this->assertSame(12, $s['busy']);
        $this->assertSame(8, $s['idle']);
        $this->assertSame(0.41, $s['req_per_sec']);
        $this->assertNull(sh_parse_server_status('<html>403 Forbidden</html>'));
    }

    #[Test]
    public function max_workers_ignores_comment_lines(): void
    {
        $conf = "# MaxRequestWorkers: maximum number of server processes allowed to start\n<IfModule mpm_prefork_module>\n\tStartServers 5\n\tMaxRequestWorkers       150\n</IfModule>\n";
        $this->assertSame(150, sh_parse_max_workers($conf));
        $this->assertNull(sh_parse_max_workers("# MaxRequestWorkers 400\n"));
    }

    #[Test]
    public function parses_df_inodes(): void
    {
        $raw = "Filesystem     Inodes IUsed    IFree IUse% Mounted on\n/dev/vda1    31457280 845824 30611456    3% /\n";
        $this->assertEqualsWithDelta(2.7, sh_parse_df_inodes($raw), 0.05);
        $this->assertNull(sh_parse_df_inodes("garbage"));
    }

    #[Test]
    public function flatten_picks_fullest_disk(): void
    {
        $f = sh_flatten_for_rules([
            'ts' => 1, 'disks' => [['used_pct' => 6.0, 'inode_used_pct' => 3.0], ['used_pct' => 81.5, 'inode_used_pct' => null]],
            'apache' => ['busy_pct' => 40.0], 'app' => ['sse_live' => 9],
        ]);
        $this->assertSame(81.5, $f['disk_used_pct']);
        $this->assertSame(3.0, $f['inode_used_pct']);
        $this->assertSame(40.0, $f['workers_busy_pct']);
        $this->assertNull($f['mem_used_pct']);
    }

    // --- Alert state machine -------------------------------------------------

    private function rules(): array
    {
        return ['disk' => ['label' => 'Disk', 'metric' => 'disk_used_pct', 'warn' => 80, 'crit' => 90, 'sustain' => 3, 'unit' => '%']];
    }

    /** One sample per minute, newest last, ending at $endTs. */
    private function ring(array $values, int $endTs = 10000): array
    {
        $n = count($values);
        $out = [];
        foreach (array_values($values) as $i => $v) {
            $out[] = ['ts' => $endTs - ($n - 1 - $i) * 60, 'disk_used_pct' => $v];
        }
        return $out;
    }

    #[Test]
    public function single_spike_does_not_fire(): void
    {
        [$state, $notes] = sh_evaluate_alerts($this->ring([50, 50, 95]), [], 1000, $this->rules());
        $this->assertSame([], $state);
        $this->assertSame([], $notes);
    }

    #[Test]
    public function sustained_breach_fires_once_then_stays_quiet(): void
    {
        [$state, $notes] = sh_evaluate_alerts($this->ring([85, 85, 85]), [], 1000, $this->rules());
        $this->assertSame('warn', $state['disk']['level']);
        $this->assertCount(1, $notes);
        $this->assertSame('firing', $notes[0]['kind']);

        // One minute later, same level: no repeat.
        [$state2, $notes2] = sh_evaluate_alerts($this->ring([85, 85, 85]), $state, 1060, $this->rules());
        $this->assertSame([], $notes2);
        $this->assertSame(1000, $state2['disk']['since']);
    }

    #[Test]
    public function still_firing_renotifies_after_six_hours(): void
    {
        [$state] = sh_evaluate_alerts($this->ring([85, 85, 85]), [], 1000, $this->rules());
        [, $notes] = sh_evaluate_alerts($this->ring([85, 85, 85]), $state, 1000 + SH_RENOTIFY_SEC, $this->rules());
        $this->assertSame('still_firing', $notes[0]['kind']);
    }

    #[Test]
    public function escalation_from_warn_to_crit_notifies(): void
    {
        [$state] = sh_evaluate_alerts($this->ring([85, 85, 85]), [], 1000, $this->rules());
        [$state2, $notes] = sh_evaluate_alerts($this->ring([92, 92, 92]), $state, 1060, $this->rules());
        $this->assertSame('crit', $state2['disk']['level']);
        $this->assertSame('escalated', $notes[0]['kind']);
    }

    #[Test]
    public function one_dip_does_not_resolve_hysteresis(): void
    {
        [$state] = sh_evaluate_alerts($this->ring([85, 85, 85]), [], 1000, $this->rules());
        [$state2, $notes] = sh_evaluate_alerts($this->ring([85, 85, 85, 85, 60]), $state, 1060, $this->rules());
        $this->assertSame([], $notes);
        $this->assertSame('warn', $state2['disk']['level']);
    }

    #[Test]
    public function recovery_needs_clear_samples_then_resolves(): void
    {
        [$state] = sh_evaluate_alerts($this->ring([85, 85, 85]), [], 1000, $this->rules());
        $clean = array_fill(0, SH_CLEAR_SAMPLES, 60);
        [$state2, $notes] = sh_evaluate_alerts($this->ring($clean), $state, 1300, $this->rules());
        $this->assertSame([], $state2);
        $this->assertSame('resolved', $notes[0]['kind']);
    }

    #[Test]
    public function hovering_near_crit_does_not_flap_escalations(): void
    {
        // crit -> one sample at 89 -> back to 91: no de-escalate, no second ESCALATED.
        [$state] = sh_evaluate_alerts($this->ring([92, 92, 92]), [], 1000, $this->rules());
        [$state, $n1] = sh_evaluate_alerts($this->ring([92, 92, 92, 89]), $state, 1060, $this->rules());
        [$state, $n2] = sh_evaluate_alerts($this->ring([92, 92, 89, 91]), $state, 1120, $this->rules());
        $this->assertSame([], $n1);
        $this->assertSame([], $n2);
        $this->assertSame('crit', $state['disk']['level']);
    }

    #[Test]
    public function crit_deescalates_quietly_to_warn(): void
    {
        [$state] = sh_evaluate_alerts($this->ring([92, 92, 92]), [], 1000, $this->rules());
        [$state2, $notes] = sh_evaluate_alerts($this->ring(array_fill(0, SH_CLEAR_SAMPLES, 85)), $state, 1300, $this->rules());
        $this->assertSame('warn', $state2['disk']['level']);
        $this->assertSame([], $notes);
    }

    #[Test]
    public function samples_across_a_cron_gap_are_not_sustained(): void
    {
        // Two old breaching samples from an hour ago + one fresh: not 3 consecutive minutes.
        $ring = [['ts' => 1000, 'disk_used_pct' => 95], ['ts' => 1060, 'disk_used_pct' => 95], ['ts' => 4660, 'disk_used_pct' => 95]];
        [, $notes] = sh_evaluate_alerts($ring, [], 4660, $this->rules());
        $this->assertSame([], $notes);
    }

    #[Test]
    public function missing_data_never_fires(): void
    {
        [$state, $notes] = sh_evaluate_alerts($this->ring([95, null, 95]), [], 1000, $this->rules());
        $this->assertSame([], $state);
        $this->assertSame([], $notes);
    }

    #[Test]
    public function unknown_reading_does_not_resolve_an_active_alert(): void
    {
        // Worker exhaustion makes the localhost status request time out, so
        // the workers metric goes null. That must NOT send "RESOLVED".
        [$state] = sh_evaluate_alerts($this->ring([92, 92, 92]), [], 1000, $this->rules());
        [$state2, $notes] = sh_evaluate_alerts($this->ring([92, 92, null]), $state, 1060, $this->rules());
        $this->assertSame([], $notes);
        $this->assertSame('crit', $state2['disk']['level']);
        $this->assertSame(1000, $state2['disk']['since']);

        // Real low readings do resolve it.
        [$state3, $notes3] = sh_evaluate_alerts($this->ring(array_fill(0, SH_CLEAR_SAMPLES, 40)), $state2, 1500, $this->rules());
        $this->assertSame([], $state3);
        $this->assertSame('resolved', $notes3[0]['kind']);
    }

    #[Test]
    public function apache_unreachable_metric_is_derived_from_missing_status(): void
    {
        $this->assertSame(100, sh_flatten_for_rules(['ts' => 1, 'apache' => null])['apache_unreachable']);
        $this->assertSame(0, sh_flatten_for_rules(['ts' => 1, 'apache' => ['busy_pct' => 5.0]])['apache_unreachable']);
        // Old samples without the key at all: unknown, not "down".
        $this->assertNull(sh_flatten_for_rules(['ts' => 1])['apache_unreachable']);
    }

    #[Test]
    public function too_few_samples_never_fires(): void
    {
        // Right after the first deploy the ring is short. It must not fire on partial data.
        [, $notes] = sh_evaluate_alerts($this->ring([99, 99]), [], 1000, $this->rules());
        $this->assertSame([], $notes);
    }

    #[Test]
    public function default_rules_are_well_formed(): void
    {
        foreach (sh_alert_rules() as $key => $r) {
            $this->assertLessThan($r['crit'], $r['warn'], "$key: warn must be below crit");
            $this->assertGreaterThanOrEqual(1, $r['sustain'], "$key: sustain >= 1");
            $this->assertLessThanOrEqual(SH_RING_SIZE, $r['sustain'], "$key: sustain must fit in the ring");
        }
    }

    #[Test]
    public function notification_text_has_env_tag_and_no_secrets(): void
    {
        $n = ['key' => 'disk', 'kind' => 'firing', 'level' => 'crit', 'value' => 91.2, 'rule' => $this->rules()['disk'], 'since' => 1000];
        $txt = sh_format_notification($n, 'prod', 'https://extension.phoneburner.biz/metrics/crm_usage_dashboard.php#server-health');
        $this->assertStringContainsString('[PROD] CRITICAL', $txt);
        $this->assertStringContainsString('91.2%', $txt);
        $this->assertStringContainsString('limit 90%', $txt);
        $this->assertStringNotContainsString('hooks.slack.com', $txt);
    }
}
