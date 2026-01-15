<?php
// server/public/api/core/sse_usage_stats.php
//
// Returns lightweight SSE usage stats from:
// - server/public/metrics/sse_usage-YYYY-MM-DD.log  (daily JSONL connect/disconnect events)
// - server/public/metrics/sse_presence/*.json       ("active now" presence files)
//
// Uses core/bootstrap.php for shared hardening/CORS/OPTIONS behavior.
// JSON endpoint.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

function safe_date_ymd(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

function date_range_ymd(string $start, string $end): array {
    $out = [];
    $startTs = strtotime($start . ' 00:00:00');
    $endTs   = strtotime($end   . ' 00:00:00');
    if ($startTs === false || $endTs === false) return $out;
    if ($startTs > $endTs) return $out;

    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $out[] = date('Y-m-d', $ts);
    }
    return $out;
}

function read_presence_active_now(int $activeWindowSec = 180): array {
    $presenceDir = __DIR__ . '/../../metrics/sse_presence';
    // __DIR__ here is server/public/api/core, so ../../metrics = server/public/metrics
    // (core -> api -> public) is 2 up, then /metrics

    // However, since we're in api/core, easier to compute public dir like other files:
    $publicDir = dirname(__DIR__, 2); // core -> api -> public
    $presenceDir = $publicDir . '/metrics/sse_presence';

    $now = time();
    $active = 0;
    $totalFiles = 0;

    if (!is_dir($presenceDir)) {
        return [
            'active_now' => 0,
            'presence_files' => 0,
            'active_window_sec' => $activeWindowSec,
        ];
    }

    $files = @glob($presenceDir . '/*.json') ?: [];
    $totalFiles = count($files);

    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') continue;

        $j = json_decode($raw, true);
        if (!is_array($j)) continue;

        $last = isset($j['last_seen_unix']) ? (int)$j['last_seen_unix'] : 0;
        if ($last > 0 && ($now - $last) <= $activeWindowSec) {
            $active++;
        }
    }

    return [
        'active_now' => $active,
        'presence_files' => $totalFiles,
        'active_window_sec' => $activeWindowSec,
    ];
}

function read_daily_sse_log(string $dateYmd): array {
    $publicDir = dirname(__DIR__, 2); // core -> api -> public
    $metricsDir = $publicDir . '/metrics';
    $logFile = $metricsDir . '/sse_usage-' . $dateYmd . '.log';

    $stats = [
        'date' => $dateYmd,
        'file_present' => false,
        'connects' => 0,
        'disconnects' => 0,
        'unique_sessions' => 0,
        'avg_duration_sec' => 0,
        'p95_duration_sec' => 0,
        'max_concurrent_est' => 0,
    ];

    if (!is_file($logFile)) {
        return $stats;
    }

    $fh = @fopen($logFile, 'rb');
    if (!$fh) {
        return $stats;
    }

    $stats['file_present'] = true;

    $connectSeen = [];
    $disconnectSeen = [];
    $durations = [];

    // Simple sweep-line for "max concurrent estimate" from connect/disconnect ordering.
    $current = 0;
    $max = 0;

    while (!feof($fh)) {
        $line = fgets($fh);
        if ($line === false) break;
        $line = trim($line);
        if ($line === '') continue;

        $j = json_decode($line, true);
        if (!is_array($j)) continue;

        $event = isset($j['event']) ? (string)$j['event'] : '';
        $session = isset($j['session_token_hash']) ? (string)$j['session_token_hash'] : '';
        if ($session === '') continue;

        if ($event === 'sse.connect') {
            if (!isset($connectSeen[$session])) {
                $connectSeen[$session] = true;
                $stats['connects']++;

                $current++;
                if ($current > $max) $max = $current;
            }
        } elseif ($event === 'sse.disconnect') {
            if (!isset($disconnectSeen[$session])) {
                $disconnectSeen[$session] = true;
                $stats['disconnects']++;

                $dur = isset($j['duration_sec']) ? (int)$j['duration_sec'] : 0;
                if ($dur > 0) $durations[] = $dur;

                // Only decrement if we previously counted a connect for this session,
                // and ensure we don't go negative.
                if (isset($connectSeen[$session]) && $current > 0) {
                    $current--;
                }
            }
        }
    }

    fclose($fh);

    $stats['unique_sessions'] = count($connectSeen);
    $stats['max_concurrent_est'] = $max;

    if (count($durations) > 0) {
        sort($durations);
        $sum = array_sum($durations);
        $stats['avg_duration_sec'] = (int)round($sum / count($durations));

        $idx = (int)floor(0.95 * (count($durations) - 1));
        $stats['p95_duration_sec'] = (int)$durations[max(0, $idx)];
    }

    return $stats;
}

// -------------------------
// Params
// -------------------------
$start = safe_date_ymd($_GET['start'] ?? null);
$end   = safe_date_ymd($_GET['end'] ?? null);

// Default: today only (keeps it fast/light)
$today = date('Y-m-d');
if (!$start) $start = $today;
if (!$end)   $end   = $today;

// Hard safety cap to avoid huge reads accidentally
$dates = date_range_ymd($start, $end);
if (count($dates) === 0) {
    api_error('Invalid date range', 'bad_request', 400);
}
if (count($dates) > 31) {
    api_error('Date range too large (max 31 days)', 'bad_request', 400);
}

// Active-now window (seconds)
$activeWindowSec = (int)($_GET['active_window_sec'] ?? 180);
if ($activeWindowSec < 60) $activeWindowSec = 60;
if ($activeWindowSec > 900) $activeWindowSec = 900;

// -------------------------
// Build response
// -------------------------
$perDay = [];
$totalConnects = 0;
$totalDisconnects = 0;
$totalUnique = 0;

foreach ($dates as $d) {
    $day = read_daily_sse_log($d);
    $perDay[] = $day;

    $totalConnects += (int)$day['connects'];
    $totalDisconnects += (int)$day['disconnects'];
    // Note: unique_sessions is per-day. We do not try to dedupe across days here.
    $totalUnique += (int)$day['unique_sessions'];
}

$activeNow = read_presence_active_now($activeWindowSec);

api_ok([
    'range' => [
        'start' => $start,
        'end' => $end,
        'days' => count($dates),
    ],
    'active_now' => $activeNow,
    'totals' => [
        'connects' => $totalConnects,
        'disconnects' => $totalDisconnects,
        'unique_sessions_sum' => $totalUnique,
    ],
    'per_day' => $perDay,
]);
