<?php
// server/public/api/core/track_crm_usage.php
//
// Receives lightweight usage pings from the extension and appends a JSON line
// to metrics/crm_usage.log for aggregate reporting in crm_usage_stats.php.
//
// Expected input (JSON):
// - client_id (required)
// - crm_id (string, optional)
// - host (string, optional)
// - path (string, optional)
// - level (int, optional)
//
// NOTE: We log a hashed client_id only (not the raw client_id).

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// Basic fields from the extension
$crmId = isset($data['crm_id']) ? (string)$data['crm_id'] : 'unknown';
$host  = isset($data['host'])   ? (string)$data['host']   : '';
$path  = isset($data['path'])   ? (string)$data['path']   : '';
$level = isset($data['level'])  ? (int)$data['level']     : 0;

// Optional: user agent for segmentation/debug (safe-ish, but still metadata)
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// Write a line of JSON to metrics/crm_usage.log
$cfg        = cfg();
$metricsDir = $cfg['METRICS_DIR'] ?? (__DIR__ . '/../../metrics');
ensure_dir($metricsDir);

$logFile = rtrim($metricsDir, '/\\') . '/crm_usage.log';

$entry = [
    'ts'             => date('c'),
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'crm_id'         => $crmId,
    'host'           => $host,
    'path'           => $path,
    'level'          => $level,
    'ua'             => $userAgent,
];

$ok = @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

if ($ok === false) {
    api_log('track_crm_usage.error.write_failed', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'crm_id'         => $crmId,
        'level'          => $level,
    ]);
    api_error('Unable to write CRM usage log', 'server_error', 500);
}

api_log('track_crm_usage.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'crm_id'         => $crmId,
    'level'          => $level,
]);

api_ok([]);
