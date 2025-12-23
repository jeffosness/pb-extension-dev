<?php
// server/public/api/core/crm_usage_stats.php
//
// Reads metrics/crm_usage.log written by track_crm_usage.php and returns aggregated stats.
//
// Response data includes:
// - total_events
// - by_crm_id
// - by_host
// - by_level
//
// NOTE: This endpoint does not expose client_id values.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$cfg = cfg();

// IMPORTANT: must match track_crm_usage.php.
$metricsDir = $cfg['METRICS_DIR'] ?? (__DIR__ . '/../../metrics');
$logFile    = rtrim($metricsDir, '/\\') . '/crm_usage.log';

if (!file_exists($logFile)) {
    api_log('crm_usage_stats.no_log', [
        'log_exists' => false,
    ]);

    api_ok([
        'total_events' => 0,
        'by_crm_id'    => (object)[],
        'by_host'      => (object)[],
        'by_level'     => (object)[],
        'note'         => 'No crm_usage.log file yet',
    ]);
}

$byCrm   = [];
$byHost  = [];
$byLevel = [];
$total   = 0;

$fh = fopen($logFile, 'r');
if ($fh === false) {
    api_log('crm_usage_stats.error.open_failed', [
        'log_exists' => true,
    ]);
    api_error('Could not open crm_usage log file', 'server_error', 500);
}

while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;

    $entry = json_decode($line, true);
    if (!is_array($entry)) continue;

    $total++;

    $crm   = $entry['crm_id'] ?? 'unknown';
    $host  = $entry['host']   ?? 'unknown';
    $level = (string)($entry['level'] ?? 'unknown');

    $byCrm[$crm]     = ($byCrm[$crm]     ?? 0) + 1;
    $byHost[$host]   = ($byHost[$host]   ?? 0) + 1;
    $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
}

fclose($fh);

api_log('crm_usage_stats.ok', [
    'total_events' => $total,
]);

api_ok([
    'total_events' => $total,
    'by_crm_id'    => $byCrm,
    'by_host'      => $byHost,
    'by_level'     => $byLevel,
]);
