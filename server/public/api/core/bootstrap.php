<?php
// server/public/api/core/bootstrap.php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// Request correlation + timing
// -----------------------------------------------------------------------------
$REQUEST_ID = bin2hex(random_bytes(8));
$START_TS   = microtime(true);

// -----------------------------------------------------------------------------
// PHP-level error / exception / fatal handlers → route through api_log()
//
// Before this, PHP-native errors landed in php_errors.log (from #225) while
// our own structured entries went to api.log — support triage had to grep two
// files in two formats. These handlers make every PHP error surface in
// api.log as a structured 'php.error' / 'php.exception' / 'php.fatal' event.
//
// Installed EARLY (before headers, config load, timezone init) so a fatal
// during any of those steps is still caught by the shutdown handler. Each
// handler guards `function_exists('api_log')` and no-ops if the failure
// happens before api_log itself is defined; php_errors.log remains our
// belt-and-suspenders capture for that narrow "bootstrap.php failed to load"
// window.
//
// Re-entry protection: the static $inside flag prevents infinite loops if
// api_log itself trips a warning (e.g. failed file write). One error is
// captured per invocation; anything raised during handling falls through to
// PHP's default output.
//
// @-silenced expressions (via error_reporting()) are correctly skipped by the
// error handler — matches PHP's normal semantics, and bootstrap.php + utils.php
// use @ liberally on best-effort file operations.
// -----------------------------------------------------------------------------
// Scrub + truncate free-form text before it lands in api.log. Exception
// messages and stack-trace strings can carry OAuth tokens (embedded in
// provider error text OR as function arguments in `getTraceAsString()`),
// and `redact_pii_recursive` in api_log() matches by KEY name only — so
// `message`/`trace` values pass through unscrubbed without this helper.
// Uses _pb_scrub_tokens() from utils.php when it's loaded (every endpoint
// requires utils.php after bootstrap); falls back to a raw truncate.
function _pb_scrub_and_truncate(string $text, int $maxLen): string {
  if ($text === '') return '';
  if (function_exists('_pb_scrub_tokens')) $text = _pb_scrub_tokens($text);
  if (strlen($text) > $maxLen) $text = substr($text, 0, $maxLen) . '…[truncated]';
  return $text;
}

function _pb_php_error_handler(int $severity, string $message, string $file, int $line): bool {
  static $inside = false;
  if ($inside) return false;
  if (!(error_reporting() & $severity)) return false; // respects @-silenced
  $inside = true;
  try {
    if (function_exists('api_log')) {
      api_log('php.error', [
        'severity' => $severity,
        'message'  => _pb_scrub_and_truncate($message, 1000),
        'file'     => $file,
        'line'     => $line,
      ]);
    }
  } finally {
    $inside = false;
  }
  return false; // let PHP's default chain continue (still hits php_errors.log)
}

function _pb_php_exception_handler(\Throwable $e): void {
  static $inside = false;
  if ($inside) return;
  $inside = true;
  try {
    if (function_exists('api_log')) {
      api_log('php.exception', [
        'class'   => get_class($e),
        'message' => _pb_scrub_and_truncate($e->getMessage(), 1000),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => _pb_scrub_and_truncate($e->getTraceAsString(), 4000),
      ]);
    }
    // Emit a structured 500, but only for JSON endpoints and only if headers
    // haven't already gone out. HTML pages (OAuth callbacks) and SSE streams
    // opt out via PB_BOOTSTRAP_NO_JSON — for them, sending a JSON body would
    // be worse than falling back to PHP's default handler.
    $jsonOn = !defined('PB_BOOTSTRAP_NO_JSON') || PB_BOOTSTRAP_NO_JSON !== true;
    if ($jsonOn && function_exists('api_error') && !headers_sent()) {
      api_error('Internal server error', 'server_error', 500, [
        'exception_class' => get_class($e),
      ]);
    }
  } finally {
    $inside = false;
  }
}

function _pb_php_shutdown_handler(): void {
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array($err['type'], $fatalTypes, true)) return;
  if (function_exists('api_log')) {
    api_log('php.fatal', [
      'type'    => $err['type'],
      'message' => _pb_scrub_and_truncate($err['message'], 1000),
      'file'    => $err['file'],
      'line'    => $err['line'],
    ]);
  }
}

set_error_handler('_pb_php_error_handler');
set_exception_handler('_pb_php_exception_handler');
register_shutdown_function('_pb_php_shutdown_handler');

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
// PII Redaction: recursive filtering for sensitive data
// - Handles nested arrays, pattern-based key matching, and exact key matches
// - Runs before logging to prevent sensitive data leakage
// - Safe to call on any array; non-matching data passes through unchanged
// -----------------------------------------------------------------------------
function redact_pii_recursive(array $data): array {
  // Pattern-based key matching (case-insensitive)
  $denyPatterns = [
    '/^.*email.*$/i',           // email, user_email, email_address, etc.
    '/^.*phone.*$/i',           // phone, phone_number, primary_phone, etc.
    '/^.*token.*$/i',           // token, access_token, bearer_token, session_token, etc.
    '/^.*password.*$/i',        // password, user_password, pwd, etc.
    '/^.*secret.*$/i',          // secret, client_secret, api_secret, etc.
    '/^.*auth.*$/i',            // authorization, auth_header, auth_token, etc.
    '/^.*(paypal|credit|card).*$/i', // payment methods
    '/^.*(first_name|last_name|full_name|contact_name)$/i', // personal names
    '/^.*crm_identifier.*$/i',  // CRM record identifiers
    '/^.*note.*$/i',            // agent-typed call notes / note bodies
    '/^.*content.*$/i',         // note content echoed in provider errors
    '/^.*(ssn|social.?security).*$/i', // SSNs (debt-settlement CRM fields)
  ];
  
  // Exact key matches (for known bulk data fields)
  $denyKeys = ['payload', 'contacts', 'response_body', 'request_body'];
  
  // Recursive array walk to find and redact all matching keys
  array_walk_recursive($data, function(&$value, $key) use ($denyPatterns, $denyKeys) {
    // Exact key match (fast path)
    if (in_array($key, $denyKeys, true)) {
      $value = '[REDACTED]';
      return;
    }
    
    // Pattern match (case-insensitive, for flexible key naming)
    foreach ($denyPatterns as $pattern) {
      if (preg_match($pattern, (string)$key)) {
        $value = '[REDACTED]';
        return;
      }
    }
  });
  
  return $data;
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

  // Recursively redact any sensitive keys (including nested data)
  $fields = redact_pii_recursive($fields);

  $line = json_encode($base + $fields, JSON_UNESCAPED_SLASHES) . PHP_EOL;
  @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
