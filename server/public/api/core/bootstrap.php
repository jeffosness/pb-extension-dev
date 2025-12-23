<?php
// server/public/api/core/bootstrap.php
declare(strict_types=1);

// Always return JSON for API endpoints that include this file
header('Content-Type: application/json; charset=utf-8');

// Load server-only config (ignored by git)
$configPath = __DIR__ . '/../../config.php'; // core -> api -> public
if (file_exists($configPath)) {
  require_once $configPath;
}

// Request correlation
$REQUEST_ID = bin2hex(random_bytes(8));
$START_TS = microtime(true);

// Basic hardening
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// Simple JSON responder (standard shape)
function api_ok(array $data = [], int $status = 200): void {
  global $REQUEST_ID, $START_TS;
  http_response_code($status);
  echo json_encode([
    'ok' => true,
    'request_id' => $REQUEST_ID,
    'duration_ms' => (int) round((microtime(true) - $START_TS) * 1000),
    'data' => $data,
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

function api_error(string $message, string $code = 'error', int $status = 400, array $extra = []): void {
  global $REQUEST_ID, $START_TS;
  http_response_code($status);
  echo json_encode([
    'ok' => false,
    'request_id' => $REQUEST_ID,
    'duration_ms' => (int) round((microtime(true) - $START_TS) * 1000),
    'error' => array_merge([
      'code' => $code,
      'message' => $message,
    ], $extra),
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

// Safe logger: logs metadata, never raw tokens/PII by default
function api_log(string $event, array $fields = []): void {
  global $REQUEST_ID, $START_TS;

  // Prefer explicit config. Fallback: repoRoot/var/log
  $logDir = defined('PB_LOG_DIR')
    ? PB_LOG_DIR
    : (dirname(__DIR__, 4) . '/var/log'); // core->api->public->server->repo

  $logFile = rtrim($logDir, '/\\') . '/api.log';

  if (!is_dir($logDir)) @mkdir($logDir, 0770, true);

  $base = [
    'ts' => date('c'),
    'request_id' => $REQUEST_ID,
    'event' => $event,
    'duration_ms' => (int) round((microtime(true) - $START_TS) * 1000),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'path' => $_SERVER['REQUEST_URI'] ?? null,
  ];

  // Never log these keys if they show up
  $denyKeys = ['token', 'access_token', 'bearer', 'authorization', 'email', 'phone', 'contacts', 'payload'];
  foreach ($denyKeys as $k) {
    if (array_key_exists($k, $fields)) $fields[$k] = '[REDACTED]';
  }

  $line = json_encode($base + $fields, JSON_UNESCAPED_SLASHES) . PHP_EOL;
  @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
