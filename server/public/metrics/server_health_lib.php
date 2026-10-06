<?php
// server/public/metrics/server_health_lib.php
//
// Server health monitoring: CPU / memory / disk / Apache worker / app-level
// samples, plus the alert state machine. Shared by:
//   1) scripts/cron/collect_server_health.php — runs every minute from the
//      repo-managed cron file, appends one sample, evaluates alerts, posts
//      to Slack.
//   2) metrics/api/server_health_stats.php — feeds the dashboard's
//      "Server Health" section.
//
// Functions only — no top-level side effects, so a direct HTTP request to
// this file (already behind /metrics/ Basic Auth) does nothing.
//
// Storage (outside the webroot, next to app.log):
//   {log dir}/health/health-YYYY-MM-DD.jsonl   one JSON sample per line, 30-day retention
//   {log dir}/health/state.json                latest sample + recent ring + alert state
//
// Why worker saturation is the headline metric: Apache runs mpm_prefork with
// mod_php, and every live dial session holds an SSE connection — one whole
// Apache process — for its entire duration. When BusyWorkers hits
// MaxRequestWorkers, new requests queue and hang while CPU looks fine.

const SH_RETENTION_DAYS   = 30;
const SH_RING_SIZE        = 15;      // samples kept in state.json for "sustained" checks
const SH_RENOTIFY_SEC     = 6 * 3600; // repeat a still-firing alert every 6h
const SH_STALE_SEC        = 180;     // dashboard warns if the newest sample is older than this
const SH_SSE_ACTIVE_SEC   = 240;     // presence file touched within this window = live session (matches sse_usage_stats)
const SH_SLACK_URL_PREFIX = 'https://hooks.slack.com/';
const SH_CLEAR_SAMPLES    = 5;       // clean samples needed before an alert resolves/de-escalates (hysteresis)
const SH_GAP_TOLERANCE_SEC = 90;     // slack allowed in a window's span before it's treated as a gap
const SH_OUTBOX_MAX_TRIES = 6;       // failed Slack posts are retried this many times, 5 min apart

/**
 * Alert rules. Thresholds live in code on purpose (reviewed in PRs, no
 * runtime toggles). `sustain` = how many consecutive 1-minute samples must
 * breach before the alert fires, so a single spike doesn't page anyone.
 *
 * `metric` is a key in the flattened sample (see sh_flatten_for_rules()).
 */
function sh_alert_rules(): array {
    return [
        'cpu_load'   => ['label' => 'CPU load (5-min avg, % of cores)', 'metric' => 'load5_pct',        'warn' => 90, 'crit' => 150, 'sustain' => 10, 'unit' => '%'],
        'memory'     => ['label' => 'Memory used',                      'metric' => 'mem_used_pct',     'warn' => 85, 'crit' => 95,  'sustain' => 5,  'unit' => '%'],
        'workers'    => ['label' => 'Apache workers busy',              'metric' => 'workers_busy_pct', 'warn' => 70, 'crit' => 90,  'sustain' => 3,  'unit' => '%'],
        'disk'       => ['label' => 'Disk used (fullest volume)',       'metric' => 'disk_used_pct',    'warn' => 80, 'crit' => 90,  'sustain' => 2,  'unit' => '%'],
        'inodes'     => ['label' => 'Inodes used (fullest volume)',     'metric' => 'inode_used_pct',   'warn' => 80, 'crit' => 90,  'sustain' => 2,  'unit' => '%'],
        // Swap is recorded but not alerted: Linux leaves pages in swap long after
        // pressure ends, so a used-% rule would sit at warning indefinitely.
        // 100 when the localhost server-status request fails, else 0. With
        // prefork, a status page that stops answering usually means every
        // worker is taken, which is the failure the workers rule can't see.
        'apache_down' => ['label' => 'Apache status page not answering (workers likely exhausted)', 'metric' => 'apache_unreachable', 'warn' => 50, 'crit' => 100, 'sustain' => 3, 'unit' => '%'],
    ];
}

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------

/** Directory holding health samples + state. Derived from LOG_FILE so it is outside the webroot. */
function sh_health_dir(array $cfg): string {
    $logFile = (string)($cfg['LOG_FILE'] ?? '');
    $logDir  = $logFile !== '' ? dirname($logFile) : dirname(__DIR__, 3) . '/var/log'; // metrics -> public -> server -> repo
    return rtrim($logDir, '/\\') . '/health';
}

function sh_day_file(string $dir, int $ts): string {
    return $dir . '/health-' . gmdate('Y-m-d', $ts) . '.jsonl';
}

// ---------------------------------------------------------------------------
// Parsers (pure — unit-tested in tests/ServerHealthTest.php)
// ---------------------------------------------------------------------------

/** /proc/meminfo → ['mem_total_mb','mem_avail_mb','mem_used_pct','swap_total_mb','swap_used_mb','swap_used_pct'] */
function sh_parse_meminfo(string $raw): ?array {
    $kb = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
            $kb[$m[1]] = (int)$m[2];
        }
    }
    if (empty($kb['MemTotal']) || !isset($kb['MemAvailable'])) return null;

    $swapTotal = $kb['SwapTotal'] ?? 0;
    $swapUsed  = $swapTotal - ($kb['SwapFree'] ?? $swapTotal);
    return [
        'mem_total_mb'  => (int) round($kb['MemTotal'] / 1024),
        'mem_avail_mb'  => (int) round($kb['MemAvailable'] / 1024),
        'mem_used_pct'  => round(100 * (1 - $kb['MemAvailable'] / $kb['MemTotal']), 1),
        'swap_total_mb' => (int) round($swapTotal / 1024),
        'swap_used_mb'  => (int) round($swapUsed / 1024),
        'swap_used_pct' => $swapTotal > 0 ? round(100 * $swapUsed / $swapTotal, 1) : 0.0,
    ];
}

/** First "cpu " line of /proc/stat → [busy_jiffies, total_jiffies] */
function sh_parse_proc_stat(string $raw): ?array {
    if (!preg_match('/^cpu\s+(.+)$/m', $raw, $m)) return null;
    $f = array_map('intval', preg_split('/\s+/', trim($m[1])));
    if (count($f) < 4) return null;
    // user nice system idle iowait irq softirq steal ...
    $idle  = $f[3] + ($f[4] ?? 0);
    $total = array_sum(array_slice($f, 0, 8));
    return [$total - $idle, $total];
}

function sh_cpu_pct(?array $a, ?array $b): ?float {
    if (!$a || !$b) return null;
    $dTotal = $b[1] - $a[1];
    if ($dTotal <= 0) return null;
    return round(100 * ($b[0] - $a[0]) / $dTotal, 1);
}

/** mod_status `?auto` output → ['busy','idle','req_per_sec'] */
function sh_parse_server_status(string $raw): ?array {
    $v = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        if (preg_match('/^([A-Za-z ]+):\s*(\S+)/', $line, $m)) {
            $v[trim($m[1])] = $m[2];
        }
    }
    if (!isset($v['BusyWorkers'], $v['IdleWorkers'])) return null;
    return [
        'busy'        => (int)$v['BusyWorkers'],
        'idle'        => (int)$v['IdleWorkers'],
        'req_per_sec' => isset($v['ReqPerSec']) ? round((float)$v['ReqPerSec'], 2) : null,
    ];
}

/** mpm_*.conf contents → MaxRequestWorkers (ignores comment lines). */
function sh_parse_max_workers(string $conf): ?int {
    foreach (preg_split('/\R/', $conf) as $line) {
        if (preg_match('/^\s*MaxRequestWorkers\s+(\d+)/i', $line, $m)) return (int)$m[1];
    }
    return null;
}

/** `df -P -i <path>` output → inode used % */
function sh_parse_df_inodes(string $raw): ?float {
    $lines = array_values(array_filter(preg_split('/\R/', trim($raw))));
    if (count($lines) < 2) return null;
    $cols = preg_split('/\s+/', $lines[count($lines) - 1]);
    // Filesystem Inodes IUsed IFree IUse% Mounted
    if (count($cols) < 6 || !ctype_digit($cols[1]) || (int)$cols[1] === 0) return null;
    return round(100 * (int)$cols[2] / (int)$cols[1], 1);
}

// ---------------------------------------------------------------------------
// Collection (touches the real system — runs from the CLI collector)
// ---------------------------------------------------------------------------

function sh_collect_sample(array $cfg, string $env): array {
    $t0 = microtime(true);
    $s  = ['ts' => time(), 'env' => $env];

    // CPU
    $cores = 0;
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if (is_string($cpuinfo)) $cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
    $s['cores'] = $cores ?: null;

    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    if (is_array($load)) {
        [$s['load1'], $s['load5'], $s['load15']] = array_map(fn($x) => round((float)$x, 2), $load);
        $s['load5_pct'] = $cores ? round(100 * $s['load5'] / $cores, 1) : null;
    }

    $a = sh_parse_proc_stat((string)@file_get_contents('/proc/stat'));
    usleep(500000);
    $b = sh_parse_proc_stat((string)@file_get_contents('/proc/stat'));
    $s['cpu_pct'] = sh_cpu_pct($a, $b);

    // Memory
    $mem = sh_parse_meminfo((string)@file_get_contents('/proc/meminfo'));
    if ($mem) $s += $mem;

    // Disks — root, tokens, logs. Deduplicate volumes by device id.
    $publicDir = dirname(__DIR__);
    $paths = array_filter([
        '/'      => '/',
        'tokens' => (string)($cfg['TOKENS_DIR'] ?? ''),
        'logs'   => dirname(sh_health_dir($cfg)),
    ]);
    $seen = [];
    $s['disks'] = [];
    foreach ($paths as $name => $path) {
        if (!is_dir($path)) continue;
        $st = @stat($path);
        $dev = $st ? $st['dev'] : $path;
        if (isset($seen[$dev])) { $seen[$dev]['used_by'][] = $name; continue; }
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if (!$total || $free === false) continue;
        // timeout: a hung mount must not pile up one stuck collector per minute.
        $inodeRaw = @shell_exec('timeout 5 df -P -i -- ' . escapeshellarg($path) . ' 2>/dev/null');
        $seen[$dev] = [
            'path'           => $path,
            'used_by'        => [$name],
            'total_gb'       => round($total / 1e9, 1),
            'free_gb'        => round($free / 1e9, 1),
            'used_pct'       => round(100 * (1 - $free / $total), 1),
            'inode_used_pct' => is_string($inodeRaw) ? sh_parse_df_inodes($inodeRaw) : null,
        ];
    }
    $s['disks'] = array_values($seen);

    // Apache workers (mod_status is `Require local` by default on Ubuntu)
    $s['apache'] = null;
    $max = null;
    foreach (glob('/etc/apache2/mods-enabled/mpm_*.conf') ?: [] as $conf) {
        $max = sh_parse_max_workers((string)@file_get_contents($conf));
        if ($max) break;
    }
    $ch = curl_init('http://127.0.0.1/server-status?auto');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $status = ($code === 200 && is_string($raw)) ? sh_parse_server_status($raw) : null;
    if ($status) {
        $status['max'] = $max;
        $status['busy_pct'] = $max ? round(100 * $status['busy'] / $max, 1) : null;
        $s['apache'] = $status;
    }

    // App-level
    $now = time();
    $presence = glob($publicDir . '/metrics/sse_presence/*.json') ?: [];
    $live = 0;
    foreach ($presence as $f) {
        $m = @filemtime($f);
        if ($m && $now - $m <= SH_SSE_ACTIVE_SEC) $live++;
    }
    $sessionsDir = (string)($cfg['SESSIONS_DIR'] ?? $publicDir . '/sessions');
    $logDirSize = 0;
    foreach (glob(sh_health_dir($cfg) . '/../*') ?: [] as $f) {
        if (is_file($f)) $logDirSize += (int)@filesize($f);
    }
    $s['app'] = [
        'sse_live'       => $live,
        'presence_files' => count($presence),
        'session_files'  => count(glob($sessionsDir . '/*.json') ?: []),
        'log_dir_mb'     => round($logDirSize / 1e6, 1),
    ];

    $s['collect_ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $s;
}

/** Flatten a sample into the scalar metrics the alert rules reference. */
function sh_flatten_for_rules(array $s): array {
    $disk = null; $inode = null;
    foreach ($s['disks'] ?? [] as $d) {
        if (isset($d['used_pct']))       $disk  = max($disk ?? 0, $d['used_pct']);
        if (isset($d['inode_used_pct'])) $inode = max($inode ?? 0, $d['inode_used_pct']);
    }
    return [
        'ts'               => (int)($s['ts'] ?? 0),
        'load5_pct'        => $s['load5_pct'] ?? null,
        'cpu_pct'          => $s['cpu_pct'] ?? null,
        'mem_used_pct'     => $s['mem_used_pct'] ?? null,
        'swap_used_pct'    => $s['swap_used_pct'] ?? null,
        'workers_busy_pct' => $s['apache']['busy_pct'] ?? null,
        'apache_unreachable' => array_key_exists('apache', $s) ? ($s['apache'] === null ? 100 : 0) : null,
        'disk_used_pct'    => $disk,
        'inode_used_pct'   => $inode,
        'sse_live'         => $s['app']['sse_live'] ?? null,
    ];
}

// ---------------------------------------------------------------------------
// Alert state machine (pure — unit-tested)
// ---------------------------------------------------------------------------

/**
 * The last $n ring rows for a rule, or null if there aren't $n of them OR
 * they don't span consecutive-ish minutes. After a cron gap, samples from
 * hours ago must not combine with fresh ones to count as "sustained".
 */
function sh_window(array $ring, int $n): ?array {
    if ($n < 1 || count($ring) < $n) return null;
    $w = array_slice($ring, -$n);
    $span = (int)($w[$n - 1]['ts'] ?? 0) - (int)($w[0]['ts'] ?? 0);
    if ($span > ($n - 1) * 60 + SH_GAP_TOLERANCE_SEC) return null;
    return $w;
}

/**
 * Raising level for one rule: a level counts only if EVERY sample in the
 * window breaches it. Missing data never raises (null = unknown, not bad).
 */
function sh_rule_level(array $rule, array $ring): string {
    $w = sh_window($ring, (int)$rule['sustain']);
    if ($w === null) return 'ok';
    foreach (['crit', 'warn'] as $lvl) {
        $all = true;
        foreach ($w as $row) {
            $v = $row[$rule['metric']] ?? null;
            if ($v === null || $v < $rule[$lvl]) { $all = false; break; }
        }
        if ($all) return $lvl;
    }
    return 'ok';
}

/**
 * Lowering level for a rule currently at $prev: only drop when the last
 * max(sustain, SH_CLEAR_SAMPLES) samples are ALL known and ALL below the
 * current level's threshold (hysteresis: one dip doesn't resolve, one spike
 * after that doesn't re-fire). Unknown readings hold the current level,
 * because worker exhaustion makes the collector's own server-status request
 * time out, nulling the metric exactly when it matters (Codex review).
 */
function sh_rule_cleared_level(array $rule, array $ring, string $prev): string {
    $w = sh_window($ring, max((int)$rule['sustain'], SH_CLEAR_SAMPLES));
    if ($w === null) return $prev;
    $belowWarn = true;
    foreach ($w as $row) {
        $v = $row[$rule['metric']] ?? null;
        if ($v === null || $v >= $rule[$prev]) return $prev;
        if ($v >= $rule['warn']) $belowWarn = false;
    }
    return $belowWarn ? 'ok' : 'warn';
}

/**
 * Advance alert state. Returns [newAlertState, notifications[]].
 * Notifies when an alert starts, escalates warn→crit, is still firing
 * SH_RENOTIFY_SEC after the last notice, or resolves. De-escalation
 * crit→warn is recorded quietly; the resolve message covers the recovery.
 */
function sh_evaluate_alerts(array $ring, array $alertState, int $now, ?array $rules = null): array {
    $rules = $rules ?? sh_alert_rules();
    $latest = $ring ? $ring[count($ring) - 1] : [];
    $out = [];
    $notes = [];
    $rank = ['ok' => 0, 'warn' => 1, 'crit' => 2];

    foreach ($rules as $key => $rule) {
        $prev  = $alertState[$key] ?? ['level' => 'ok'];
        $prevLevel = isset($rank[$prev['level'] ?? null]) ? $prev['level'] : 'ok';
        $value = $latest[$rule['metric']] ?? null;

        $raised = sh_rule_level($rule, $ring);
        if ($rank[$raised] >= $rank[$prevLevel]) {
            $level = $raised;
        } else {
            $level = sh_rule_cleared_level($rule, $ring, $prevLevel);
        }

        if ($level === 'ok') {
            if ($prevLevel !== 'ok') {
                $notes[] = ['key' => $key, 'kind' => 'resolved', 'level' => 'ok', 'from' => $prevLevel, 'value' => $value, 'rule' => $rule, 'since' => $prev['since'] ?? null];
            }
            continue; // ok rules are not stored
        }

        $entry = [
            'level'         => $level,
            'since'         => $prevLevel === 'ok' ? $now : ($prev['since'] ?? $now),
            'last_notified' => $prev['last_notified'] ?? 0,
            'value'         => $value,
        ];
        $kind = null;
        if ($prevLevel === 'ok')                                         $kind = 'firing';
        elseif ($rank[$level] > $rank[$prevLevel])                       $kind = 'escalated';
        elseif ($now - (int)$entry['last_notified'] >= SH_RENOTIFY_SEC)  $kind = 'still_firing';

        if ($kind) {
            $entry['last_notified'] = $now;
            $notes[] = ['key' => $key, 'kind' => $kind, 'level' => $level, 'value' => $value, 'rule' => $rule, 'since' => $entry['since']];
        }
        $out[$key] = $entry;
    }
    return [$out, $notes];
}

/** Human text for one notification (Slack mrkdwn). No secrets, no PII — metrics only. */
function sh_format_notification(array $n, string $env, string $dashboardUrl): string {
    $rule  = $n['rule'];
    $val   = $n['value'] === null ? 'n/a' : $n['value'] . $rule['unit'];
    $tag   = strtoupper($env);
    switch ($n['kind']) {
        case 'resolved':
            $head = ":white_check_mark: *[{$tag}] RESOLVED* — {$rule['label']} back to normal ({$val})";
            break;
        case 'still_firing':
            $mins = $n['since'] ? (int) round((time() - (int)$n['since']) / 60) : null;
            $head = ':rotating_light: *[' . $tag . '] STILL ' . strtoupper($n['level'] === 'crit' ? 'critical' : 'warning') . "* — {$rule['label']} at {$val}" . ($mins ? " for {$mins} min" : '');
            break;
        default:
            $emoji = $n['level'] === 'crit' ? ':red_circle:' : ':warning:';
            $word  = $n['level'] === 'crit' ? 'CRITICAL' : 'WARNING';
            $limit = $rule[$n['level']] . $rule['unit'];
            $head  = "{$emoji} *[{$tag}] {$word}* — {$rule['label']} at {$val} (limit {$limit}, sustained {$rule['sustain']} min)";
    }
    return $head . "\n<{$dashboardUrl}|Open the dashboard>";
}

// ---------------------------------------------------------------------------
// Storage
// ---------------------------------------------------------------------------

function sh_read_state(string $dir): array {
    $raw = @file_get_contents($dir . '/state.json');
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}

/**
 * Read samples newer than $sinceTs across day files, keeping every $stride-th
 * line to bound memory/CPU on the 7-day view.
 */
function sh_read_samples(string $dir, int $sinceTs, int $stride = 1): array {
    $out = [];
    $stride = max(1, $stride);
    for ($day = $sinceTs - ($sinceTs % 86400); $day <= time(); $day += 86400) {
        $fh = @fopen(sh_day_file($dir, $day), 'r');
        if (!$fh) continue;
        $i = 0;
        while (($line = fgets($fh)) !== false) {
            if ($i++ % $stride !== 0) continue;
            $j = json_decode($line, true);
            if (is_array($j) && ($j['ts'] ?? 0) >= $sinceTs) $out[] = $j;
        }
        fclose($fh);
    }
    return $out;
}

function sh_prune_old_files(string $dir, int $now): int {
    $n = 0;
    $cutoff = gmdate('Y-m-d', $now - SH_RETENTION_DAYS * 86400);
    foreach (glob($dir . '/health-*.jsonl') ?: [] as $f) {
        if (preg_match('/health-(\d{4}-\d{2}-\d{2})\.jsonl$/', $f, $m) && $m[1] < $cutoff) {
            if (@unlink($f)) $n++;
        }
    }
    return $n;
}
