<?php
// unified_crm/api/core/crm_usage_stats.php
//
// Read the CRM usage log written by track_crm_usage.php and return
// aggregated stats as JSON:
//
// {
//   "ok": true,
//   "total_events": 1234,
//   "by_crm_id": { "hubspot": 100, "pipedrive": 30, ... },
//   "by_host": { "app.hubspot.com": 80, "phoneburner.pipedrive.com": 20, ... },
//   "by_level": { "1": 500, "2": 300, "3": 434 }
// }

require_once __DIR__ . '/../../utils.php';  // <-- go up two levels, same as other core scripts

header('Content-Type: application/json; charset=utf-8');

$cfg = cfg();

// IMPORTANT: this MUST match track_crm_usage.php.
$metricsDir = $cfg['METRICS_DIR'] ?? (__DIR__ . '/../../metrics');
$logFile    = $metricsDir . '/crm_usage.log';

if (!file_exists($logFile)) {
    echo json_encode([
        'ok'           => true,
        'total_events' => 0,
        'by_crm_id'    => new stdClass(),
        'by_host'      => new stdClass(),
        'by_level'     => new stdClass(),
        'note'         => 'No crm_usage.log file yet',
    ]);
    exit;
}

$byCrm   = [];
$byHost  = [];
$byLevel = [];
$total   = 0;

// Open and stream through the log – each line is a JSON entry
$fh = fopen($logFile, 'r');
if ($fh === false) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Could not open crm_usage log file',
    ]);
    exit;
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

    if (!isset($byCrm[$crm]))       $byCrm[$crm]       = 0;
    if (!isset($byHost[$host]))     $byHost[$host]     = 0;
    if (!isset($byLevel[$level]))   $byLevel[$level]   = 0;

    $byCrm[$crm]++;
    $byHost[$host]++;
    $byLevel[$level]++;
}

fclose($fh);

echo json_encode([
    'ok'           => true,
    'total_events' => $total,
    'by_crm_id'    => $byCrm,
    'by_host'      => $byHost,
    'by_level'     => $byLevel,
]);
