<?php
// server/public/api/core/track_crm_usage.php
//
// Receives lightweight usage pings from the extension and appends a JSON line
// to server/public/metrics/crm_usage.log for reporting in crm_usage_stats.php.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// ✅ Rate limit: 300 requests per minute (5 per second avg)
rate_limit_or_fail($client_id, 300);

$crmId = isset($data['crm_id']) ? (string)$data['crm_id'] : 'unknown';
$host  = isset($data['host'])   ? (string)$data['host']   : '';
$path  = isset($data['path'])   ? (string)$data['path']   : '';
$level = isset($data['level'])  ? (int)$data['level']     : 0;

$objectType    = isset($data['object_type'])    ? (string)$data['object_type']    : '';
$launchSource  = isset($data['launch_source'])  ? (string)$data['launch_source']  : '';
$selectedCount = isset($data['selected_count']) ? (int)$data['selected_count']    : 0;

// Normalize object_type to plural form (extension sends singular from URL detection)
$normalizeMap = ['contact' => 'contacts', 'company' => 'companies', 'deal' => 'deals'];
$objectType = $normalizeMap[$objectType] ?? $objectType;

// Whitelist launch_source values
$allowedSources = ['selection', 'list', 'scan', ''];
if (!in_array($launchSource, $allowedSources, true)) {
    $launchSource = '';
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// ✅ Correct metrics dir: server/public/metrics
$publicDir  = dirname(__DIR__, 2); // core -> api -> public
$metricsDir = $publicDir . '/metrics';
ensure_dir($metricsDir);

$logFile = $metricsDir . '/crm_usage.log';

$entry = [
    'ts'             => date('c'),
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'crm_id'         => $crmId,
    'host'           => $host,
    'path'           => $path,
    'level'          => $level,
    'object_type'    => $objectType,
    'launch_source'  => $launchSource,
    'selected_count' => $selectedCount,
    'ua'             => $userAgent,
];

$ok = @file_put_contents(
    $logFile,
    json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

if ($ok === false) {
    api_log('track_crm_usage.error.write_failed', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'crm_id'         => $crmId,
        'level'          => $level,
        'log_file'       => $logFile,
    ]);
    api_error('Unable to write CRM usage log', 'server_error', 500);
}

api_log('track_crm_usage.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'crm_id'         => $crmId,
    'level'          => $level,
]);

api_ok([]);
