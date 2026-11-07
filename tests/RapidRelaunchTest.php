<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__ . '/../server/public/metrics/rapid_relaunch_lib.php';

/**
 * Tests for the "Rapid re-launches" dashboard panel (GH #261) in
 * server/public/metrics/rapid_relaunch_lib.php.
 *
 * The panel exists to tell us how often a user double-launches and the
 * follow-me widget ends up on the session PhoneBurner isn't using. A false
 * "mismatch" would send us building an extension fix we don't need; a missed
 * one hides the real problem. Both directions are covered.
 */
final class RapidRelaunchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rr_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/sessions', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/sessions/*') ?: [] as $f) @unlink($f);
        foreach (glob($this->dir . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($this->dir . '/sessions');
        @rmdir($this->dir);
    }

    private function session(string $token, string $client, int $created, array $extra = []): void
    {
        $state = array_merge([
            'session_token' => $token,
            'client_id'     => $client,
            'created_at'    => date('c', $created),
            'crm_name'      => 'hubspot',
            'current'       => null,
            'last_call'     => null,
            'stats'         => ['total_calls' => 0],
            'contacts_map'  => ['123' => ['name' => 'Jane Doe', 'phone' => '5551234567']],
        ], $extra);
        file_put_contents($this->dir . "/sessions/$token.json", json_encode($state));
    }

    private function widgetConnected(string $token, string $date): void
    {
        $line = json_encode(['event' => 'sse.connect', 'session_token_hash' => rr_hash($token)]);
        file_put_contents($this->dir . "/sse_usage-$date.log", $line . "\n", FILE_APPEND);
    }

    private function pairs(int $windowSec = 60): array
    {
        $rows = rr_read_sessions($this->dir . '/sessions', 0)['rows'];
        $seen = rr_read_widget_sessions($this->dir, [date('Y-m-d')]);
        return rr_find_pairs($rows, $seen, $windowSec);
    }

    #[Test]
    public function flags_the_widget_following_the_session_pb_did_not_use(): void
    {
        $t = time() - 3600;
        // Launch A: PB used it (calls landed), widget never connected.
        $this->session('aaa111', 'client-1', $t, ['last_call' => ['status' => 'No Answer'], 'stats' => ['total_calls' => 4]]);
        // Launch B, 5s later: widget connected to it, PB never sent anything.
        $this->session('bbb222', 'client-1', $t + 5);
        $this->widgetConnected('bbb222', date('Y-m-d'));

        $p = $this->pairs();
        $this->assertCount(1, $p);
        $this->assertSame(5, $p[0]['gap_sec']);
        $this->assertTrue($p[0]['widget_mismatch']);
        $this->assertTrue($p[0]['first']['got_webhooks']);
        $this->assertFalse($p[0]['first']['widget_connected']);
        $this->assertTrue($p[0]['second']['widget_connected']);
        $this->assertSame(4, $p[0]['first']['calls']);
    }

    #[Test]
    public function double_launch_where_widget_followed_the_right_session_is_listed_but_not_flagged(): void
    {
        $t = time() - 3600;
        $this->session('aaa111', 'client-1', $t);
        $this->session('bbb222', 'client-1', $t + 3, ['current' => ['crm_id' => '1'], 'stats' => ['total_calls' => 2]]);
        $this->widgetConnected('bbb222', date('Y-m-d'));

        $p = $this->pairs();
        $this->assertCount(1, $p);
        $this->assertFalse($p[0]['widget_mismatch']);
    }

    #[Test]
    public function launches_further_apart_than_the_window_are_not_pairs(): void
    {
        $t = time() - 3600;
        $this->session('aaa111', 'client-1', $t);
        $this->session('bbb222', 'client-1', $t + 61);
        $this->assertCount(0, $this->pairs(60));
        $this->assertCount(1, $this->pairs(120));
    }

    #[Test]
    public function different_users_launching_at_the_same_moment_are_not_pairs(): void
    {
        $t = time() - 3600;
        $this->session('aaa111', 'client-1', $t);
        $this->session('bbb222', 'client-2', $t + 1);
        $this->assertCount(0, $this->pairs());
    }

    #[Test]
    public function triple_launch_yields_two_consecutive_pairs(): void
    {
        $t = time() - 3600;
        $this->session('aaa111', 'client-1', $t);
        $this->session('bbb222', 'client-1', $t + 2);
        $this->session('ccc333', 'client-1', $t + 4);
        $p = $this->pairs();
        $this->assertCount(2, $p);
        $this->assertSame(1, rr_summarize($p, 3)['users_affected']);
    }

    #[Test]
    public function splits_double_clicks_from_deliberate_relaunches_at_five_seconds(): void
    {
        $t = time() - 3600;
        $this->session('aaa111', 'client-1', $t);
        $this->session('bbb222', 'client-1', $t + 5);   // boundary: still a double-click
        $this->session('ccc333', 'client-2', $t);
        $this->session('ddd444', 'client-2', $t + 6);   // just over: deliberate re-launch

        $p = $this->pairs();
        $kinds = array_column($p, 'kind', 'client_hash');
        $this->assertSame('double_click', $kinds[rr_hash('client-1')]);
        $this->assertSame('relaunch', $kinds[rr_hash('client-2')]);

        $s = rr_summarize($p, 4);
        $this->assertSame(1, $s['double_click']);
        $this->assertSame(1, $s['relaunch']);
        $this->assertSame(5, $s['double_click_sec']);
    }

    #[Test]
    public function per_crm_breakdown_counts_pairs_users_and_keeps_session_denominator(): void
    {
        $t = time() - 3600;
        $this->session('aaa111', 'client-1', $t, ['crm_name' => 'agencyzoom']);
        $this->session('bbb222', 'client-1', $t + 20, ['crm_name' => 'agencyzoom']);
        $this->session('ccc333', 'client-1', $t + 40, ['crm_name' => 'agencyzoom']);
        $this->session('ddd444', 'client-2', $t, ['crm_name' => 'hubspot']);
        $this->session('eee555', 'client-2', $t + 2, ['crm_name' => 'hubspot']);
        $this->session('fff666', 'client-3', $t, ['crm_name' => 'close']); // no pair

        $rows = rr_read_sessions($this->dir . '/sessions', 0)['rows'];
        $s = rr_summarize($this->pairs(), count($rows), rr_sessions_by_crm($rows));

        $this->assertSame(['agencyzoom', 'hubspot'], array_keys($s['by_crm']), 'sorted by pairs, CRMs with no pairs omitted');
        $az = $s['by_crm']['agencyzoom'];
        $this->assertSame(3, $az['sessions']);
        $this->assertSame(2, $az['pairs']);
        $this->assertSame(2, $az['relaunch']);
        $this->assertSame(1, $az['users']);
        $this->assertSame(1, $s['by_crm']['hubspot']['double_click']);
        $this->assertSame(2, $s['by_crm']['hubspot']['sessions']);
    }

    #[Test]
    public function output_carries_hashes_only_never_raw_ids_or_contact_data(): void
    {
        $t = time() - 3600;
        $this->session('aaa111', 'client-secret-id', $t);
        $this->session('bbb222', 'client-secret-id', $t + 1);
        $json = json_encode($this->pairs());
        $this->assertStringNotContainsString('client-secret-id', $json);
        $this->assertStringNotContainsString('aaa111', $json);
        $this->assertStringNotContainsString('Jane Doe', $json);
        $this->assertStringNotContainsString('5551234567', $json);
        $this->assertStringContainsString(rr_hash('client-secret-id'), $json);
    }

    #[Test]
    public function skips_malformed_and_out_of_window_session_files(): void
    {
        $t = time() - 3600;
        file_put_contents($this->dir . '/sessions/broken.json', '{not json');
        file_put_contents($this->dir . '/sessions/nofields.json', json_encode(['stats' => []]));
        $this->session('old111', 'client-1', $t - 10 * 86400);
        touch($this->dir . '/sessions/old111.json', $t - 10 * 86400);
        $this->session('aaa111', 'client-1', $t);

        $r = rr_read_sessions($this->dir . '/sessions', $t - 86400);
        $this->assertCount(1, $r['rows']);
        $this->assertSame(rr_hash('aaa111'), $r['rows'][0]['session_hash']);
    }

    #[Test]
    public function caps_the_number_of_files_read(): void
    {
        $t = time() - 3600;
        for ($i = 0; $i < 5; $i++) $this->session("s$i", 'client-1', $t + $i);
        $r = rr_read_sessions($this->dir . '/sessions', 0, 3);
        $this->assertSame(3, $r['scanned']);
        $this->assertTrue($r['truncated']);
    }
}
