<?php
// server/public/api/core/crm_usage_stats.php
//
// Reads server/public/metrics/crm_usage.log written by track_crm_usage.php
// and returns aggregated counts (wrapped via api_ok()).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

// Date filtering (same pattern as sse_usage_stats.php)
function safe_date_ymd(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

$start = safe_date_ymd($_GET['start'] ?? null);
$end = safe_date_ymd($_GET['end'] ?? null);

$today = date('Y-m-d');
if (!$start) $start = $today;
if (!$end) $end = $today;

$startTs = strtotime($start . ' 00:00:00');
$endTs = strtotime($end . ' 23:59:59');

// ✅ Correct metrics dir: server/public/metrics
$publicDir  = dirname(__DIR__, 2); // core -> api -> public
$metricsDir = $publicDir . '/metrics';
$logFile    = $metricsDir . '/crm_usage.log';

if (!file_exists($logFile)) {
    api_log('crm_usage_stats.no_log', [
        'log_file' => $logFile,
    ]);

    api_ok([
        'total_events' => 0,
        'by_crm_id'    => (object)[],
        'by_host'      => (object)[],
        'by_level'     => (object)[],
        'note'         => 'No crm_usage.log file yet',
    ]);
}

$byCrm         = [];
$byHost        = [];
$byLevel       = [];
$byObjectType  = [];
$byLaunchSource = [];
$total         = 0;

$fh = fopen($logFile, 'r');
if ($fh === false) {
    api_log('crm_usage_stats.error.open_failed', [
        'log_file' => $logFile,
    ]);
    api_error('Could not open crm_usage log file', 'server_error', 500);
}

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;

    $entry = json_decode($line, true);
    if (!is_array($entry)) continue;

    // Filter by date range
    $logTs = isset($entry['ts']) ? strtotime($entry['ts']) : 0;
    if ($logTs < $startTs || $logTs > $endTs) {
        continue; // Skip events outside date range
    }

    $total++;

    $crm   = $entry['crm_id'] ?? 'unknown';
    $host  = $entry['host']   ?? 'unknown';
    $level = (string)($entry['level'] ?? 'unknown');

    $byCrm[$crm]     = ($byCrm[$crm]     ?? 0) + 1;
    $byHost[$host]   = ($byHost[$host]   ?? 0) + 1;
    $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;

    $objectType   = $entry['object_type']   ?? '';
    $launchSource = $entry['launch_source'] ?? '';

    if ($objectType !== '') {
        $byObjectType[$objectType] = ($byObjectType[$objectType] ?? 0) + 1;
    }
    if ($launchSource !== '') {
        $byLaunchSource[$launchSource] = ($byLaunchSource[$launchSource] ?? 0) + 1;
    }
}

fclose($fh);

api_log('crm_usage_stats.ok', [
    'total_events' => $total,
]);

api_ok([
    'total_events'     => $total,
    'by_crm_id'        => $byCrm,
    'by_host'          => $byHost,
    'by_level'         => $byLevel,
    'by_object_type'   => $byObjectType,
    'by_launch_source' => $byLaunchSource,
]);
