<?php
// server/public/api/core/bootstrap.php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Request correlation + timing
// -----------------------------------------------------------------------------
$REQUEST_ID = bin2hex(random_bytes(8));
$START_TS   = microtime(true);

// -----------------------------------------------------------------------------
// Timezone (set once globally for consistent "day" boundaries + timestamps)
// - Defaults to America/Denver to match your operating timezone.
// - If you later want to make it configurable, define PB_TIMEZONE in config.php.
// -----------------------------------------------------------------------------
if (function_exists('date_default_timezone_set')) {
  $tz = defined('PB_TIMEZONE') ? (string)PB_TIMEZONE : 'America/Denver';
  // Suppress warnings if timezone string is invalid; fallback to UTC.
  if (!@date_default_timezone_set($tz)) {
    @date_default_timezone_set('UTC');
  }
}

// -----------------------------------------------------------------------------
// Basic hardening headers
// -----------------------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// -----------------------------------------------------------------------------
// CORS (whitelist allowed origins)
// - Only allow requests from known PhoneBurner domains
// - Credentials are only sent if origin matches whitelist
// - Configure via PB_CORS_ORIGINS in config.php if needed
// -----------------------------------------------------------------------------
$corsAllowedOrigins = defined('PB_CORS_ORIGINS')
  ? PB_CORS_ORIGINS
  : [
      'https://extension-dev.phoneburner.biz',
      'https://extension.phoneburner.biz',
      'https://webhooktest.phoneburner.biz',
    ];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $corsAllowedOrigins, true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Headers: Content-Type, X-Client-Id');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

// Handle OPTIONS preflight quickly
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// -----------------------------------------------------------------------------
// Default to JSON for API endpoints that include this file,
// but allow opt-out (e.g., HTML OAuth finish pages, SSE endpoints).
// Usage in an endpoint BEFORE requiring bootstrap.php:
//   define('PB_BOOTSTRAP_NO_JSON', true);
// -----------------------------------------------------------------------------
if (!defined('PB_BOOTSTRAP_NO_JSON') || PB_BOOTSTRAP_NO_JSON !== true) {
  header('Content-Type: application/json; charset=utf-8');
}

// -----------------------------------------------------------------------------
// Load server-only config (ignored by git)
// NOTE: confirmed on your server this resolves to server/public/config.php
// -----------------------------------------------------------------------------
$configPath = __DIR__ . '/../../config.php'; // core -> api -> public
if (file_exists($configPath)) {
  require_once $configPath;
}

// -----------------------------------------------------------------------------
// Simple JSON responders (standard shape)
// -----------------------------------------------------------------------------
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

function api_ok_flat(array $data = [], int $status = 200): void {
  global $REQUEST_ID, $START_TS;
  http_response_code($status);

  // Merge payload keys at the top-level (legacy/extension-friendly)
  echo json_encode(array_merge([
    'ok' => true,
    'request_id' => $REQUEST_ID,
    'duration_ms' => (int) round((microtime(true) - $START_TS) * 1000),
  ], $data), JSON_UNESCAPED_SLASHES);

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

// -----------------------------------------------------------------------------
// Safe logger: logs metadata, never raw tokens/PII by default
// -----------------------------------------------------------------------------
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
