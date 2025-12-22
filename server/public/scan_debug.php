<?php
// generic_crm/scan_debug.php

require __DIR__ . '/utils.php';

// --- CORS headers so content scripts on docs.google.com (and others) can POST here ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS quickly
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Read raw JSON body from the extension
$raw = file_get_contents('php://input');
$now = date('c');

// Log via your existing log system
log_msg('scan_debug: ' . $raw);

// Also append to a dedicated scan_debug.log for easier viewing
$logFile = __DIR__ . '/scan_debug.log';
file_put_contents($logFile, $now . ' ' . $raw . PHP_EOL, FILE_APPEND);

// Try to decode JSON (not strictly required)
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = ['raw' => $raw];
}

header('Content-Type: application/json');
echo json_encode([
    'ok'          => true,
    'received_at' => $now,
]);
