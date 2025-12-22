<?php
// unified_crm/api/core/track_crm_usage.php
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// Basic fields from the extension
$crmId  = $data['crm_id'] ?? 'unknown';
$host   = $data['host']   ?? '';
$path   = $data['path']   ?? '';
$level  = isset($data['level']) ? (int)$data['level'] : 0;

// Optional: user agent for debugging/segmentation
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// Write a line of JSON to metrics/crm_usage.log
$metricsDir = __DIR__ . '/../../metrics';
ensure_dir($metricsDir);
$logFile = $metricsDir . '/crm_usage.log';

$entry = [
    'ts'        => date('c'),
    'client_id' => $client_id,
    'crm_id'    => $crmId,
    'host'      => $host,
    'path'      => $path,
    'level'     => $level,
    'ua'        => $userAgent,
];

file_put_contents($logFile, json_encode($entry) . PHP_EOL, FILE_APPEND);

json_response(['ok' => true]);
