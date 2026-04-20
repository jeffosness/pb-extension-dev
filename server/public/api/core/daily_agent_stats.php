<?php
// server/public/api/core/daily_agent_stats.php
//
// Aggregates per-agent daily stats files from server/public/daily_stats/
// and returns call productivity metrics (total calls, connections, appointments,
// call outcomes) without exposing agent identities.
//
// Files written by call_done.php: daily_stats/{date}_{agentId}.json

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

// Rate limit: dashboard-only endpoint
$data      = json_input();
$client_id = $data ? ($data['client_id'] ?? null) : null;
if (!$client_id) {
    $client_id = 'dashboard:' . $_SERVER['REMOTE_ADDR'];
}
rate_limit_or_fail($client_id, 60);

// Date filtering (same pattern as other stats endpoints)
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

$start = safe_date_ymd($_GET['start'] ?? null);
$end   = safe_date_ymd($_GET['end'] ?? null);

$today = date('Y-m-d');
if (!$start) $start = $today;
if (!$end)   $end   = $today;

$dates = date_range_ymd($start, $end);
if (count($dates) === 0) {
    api_error('Invalid date range', 'bad_request', 400);
}
if (count($dates) > 31) {
    api_error('Date range too large (max 31 days)', 'bad_request', 400);
}

$publicDir     = dirname(__DIR__, 2); // core -> api -> public
$dailyStatsDir = $publicDir . '/daily_stats';

$totalCalls        = 0;
$totalConnected    = 0;
$totalAppointments = 0;
$byStatus          = [];
$allUniqueAgents   = [];
$perDay            = [];

foreach ($dates as $d) {
    $dayStats = [
        'date'         => $d,
        'total_calls'  => 0,
        'connected'    => 0,
        'appointments' => 0,
        'agents'       => 0,
    ];
    $dayAgents = [];

    if (!is_dir($dailyStatsDir)) {
        $perDay[] = $dayStats;
        continue;
    }

    // Scan for files matching pattern: {date}_{agentId}.json
    $pattern = $dailyStatsDir . '/' . $d . '_*.json';
    $files = @glob($pattern) ?: [];

    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') continue;

        $fileData = json_decode($raw, true);
        if (!is_array($fileData) || !isset($fileData['stats'])) continue;

        $stats = $fileData['stats'];
        $agentHash = isset($fileData['agent_id'])
            ? substr(hash('sha256', (string)$fileData['agent_id']), 0, 12)
            : 'unknown';

        $allUniqueAgents[$agentHash] = true;
        $dayAgents[$agentHash] = true;

        $calls = (int)($stats['total_calls'] ?? 0);
        $conn  = (int)($stats['connected'] ?? 0);
        $appt  = (int)($stats['appointments'] ?? 0);

        $totalCalls        += $calls;
        $totalConnected    += $conn;
        $totalAppointments += $appt;

        $dayStats['total_calls']  += $calls;
        $dayStats['connected']    += $conn;
        $dayStats['appointments'] += $appt;

        foreach (($stats['by_status'] ?? []) as $status => $count) {
            $byStatus[$status] = ($byStatus[$status] ?? 0) + (int)$count;
        }
    }

    $dayStats['agents'] = count($dayAgents);
    $perDay[] = $dayStats;
}

api_log('daily_agent_stats.ok', [
    'total_calls'   => $totalCalls,
    'unique_agents' => count($allUniqueAgents),
    'days'          => count($dates),
]);

api_ok([
    'total_calls'        => $totalCalls,
    'total_connected'    => $totalConnected,
    'total_appointments' => $totalAppointments,
    'connect_rate'       => $totalCalls > 0
        ? round(($totalConnected / $totalCalls) * 100, 1) : 0,
    'unique_agents'      => count($allUniqueAgents),
    'by_status'          => $byStatus ?: (object)[],
    'per_day'            => $perDay,
]);
