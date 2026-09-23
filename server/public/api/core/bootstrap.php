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
//
// Scrubbing is inline (does NOT depend on _pb_scrub_tokens from utils.php)
// because bootstrap.php runs before utils.php and a fatal DURING bootstrap
// load (e.g. config.php parse error) would otherwise slip through. Keep
// this regex synchronized with utils.php:_pb_scrub_tokens.
//
// mb_strcut is used instead of substr so a UTF-8 message truncated at the
// byte budget doesn't leave an invalid partial codepoint that then breaks
// json_encode inside api_log.
function _pb_scrub_and_truncate(string $text, int $maxLen): string {
  if ($text === '') return '';
  // JSON-shape "access_token":"..." / "refresh_token":"..." / etc.
  $text = preg_replace(
    '/"(access_token|refresh_token|id_token|token|api_key|client_secret)"\s*:\s*"[^"]*"/i',
    '"$1":"[REDACTED]"',
    $text
  );
  // Bearer / query-string form
  $text = preg_replace(
    '/(?:Bearer\s+|access_token=|refresh_token=|api_key=)[A-Za-z0-9._~+\/=-]{20,}/i',
    '[REDACTED_TOKEN]',
    $text
  );
  if (function_exists('mb_strcut') && strlen($text) > $maxLen) {
    $text = mb_strcut($text, 0, $maxLen, 'UTF-8') . '…[truncated]';
  } elseif (strlen($text) > $maxLen) {
    $text = substr($text, 0, $maxLen) . '…[truncated]';
  }
  return $text;
}

// Build a lean stack trace WITHOUT function arguments. Default
// getTraceAsString() embeds argument previews — routinely a PAT or OAuth
// token because `pb_call_dialsession($pat, ...)` and `pb_api_call($pat, ...)`
// take the credential as their first positional arg. Iterating the array
// form and dropping the `args` key eliminates that leak surface entirely.
function _pb_trace_no_args(\Throwable $e): string {
  $lines = [];
  foreach ($e->getTrace() as $i => $frame) {
    $file = $frame['file'] ?? '[internal]';
    $line = $frame['line'] ?? 0;
    $func = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
    $lines[] = "#$i {$file}({$line}): {$func}()";
  }
  return implode("\n", $lines);
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
  // Signal the shutdown handler that this exception has already been
  // captured so it doesn't re-log via a stale error_get_last() as php.fatal.
  $GLOBALS['_pb_exception_logged'] = true;
  try {
    if (function_exists('api_log')) {
      api_log('php.exception', [
        'class'   => get_class($e),
        'message' => _pb_scrub_and_truncate($e->getMessage(), 1000),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => _pb_scrub_and_truncate(_pb_trace_no_args($e), 4000),
      ]);
    }
    // Emit a structured 500, but only for JSON endpoints and only if headers
    // haven't already gone out. HTML pages (OAuth callbacks) and SSE streams
    // opt out via PB_BOOTSTRAP_NO_JSON — for them, sending a JSON body would
    // be worse than falling back to PHP's default handler. Callers running
    // with display_errors=On (dev only) will see a trace in the browser;
    // production has display_errors=Off, so it lands in Apache's error_log
    // instead — either way, no user-facing token exposure since traces
    // above are logged via _pb_trace_no_args (args stripped).
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
  // Skip if the exception handler already logged this request's failure —
  // avoids double-logging as both php.exception and php.fatal.
  if (!empty($GLOBALS['_pb_exception_logged'])) return;
  $err = error_get_last();
  if (!$err) return;
  // E_USER_ERROR is intentionally excluded: it terminates PHP just like
  // E_ERROR, but the "unrecoverable fatal" set below matches PHP's own
  // is_fatal semantics and the exact list #226 was designed against.
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
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

  // Exact key matches (for known bulk data fields).
  //
  // Added in the #235/#239 sweep (2026-09-23) from PR #236 + #238 adversarial
  // reviews:
  //   - `raw`          — softphone_call_done.debug_raw & scan_debug's full
  //                       request body dumps (DEBUG_MODE-gated).
  //   - `record_url`   — Close CRM record URL, exposes customer subdomain.
  //   - `body_snippet` — first 500 chars of raw provider response when JSON
  //                       decode fails; describe_api_failure only scrubs
  //                       OAuth tokens, not PII.
  //   - `{provider}_error` keys — wrapper for nested provider error bodies
  //                       (close_call_logger $logData['close_error'] etc.).
  //                       array_walk_recursive walks INTO the nested body if
  //                       the wrapper key doesn't match — so provider
  //                       response shapes with keys like `submitted`/`input`
  //                       would echo back raw. Redacting at the wrapper
  //                       stops the walk.
  //
  // NOT added (deliberate — full redaction destroys diagnostic value that
  // motivates the field's existence):
  //   - `provider_msg` — the extracted human-readable provider error text.
  //                       describe_api_failure already runs _pb_scrub_tokens
  //                       on it; the residual "provider echoed a phone
  //                       number in a 4xx" leak surface is smaller than
  //                       losing every diagnostic message would be.
  $denyKeys = [
    'payload', 'contacts', 'response_body', 'request_body',
    'raw', 'record_url', 'body_snippet',
    'close_error', 'apollo_error', 'forth_error', 'hubspot_error',
  ];
  
  // Recursive walk that checks EACH KEY (including array-valued wrapper
  // keys) BEFORE descending. array_walk_recursive() — which we used before —
  // only invokes its callback on non-array LEAVES, so wrapper keys like
  // `payload`/`response_body`/`close_error` pointing at nested arrays were
  // silently walked-through unredacted (Codex caught this on 2026-09-23; see
  // #242 for the systemic-reserved-key-collision follow-up that this fix
  // sits alongside). The rewrite is strict-improvement: nothing that was
  // being redacted before stops being redacted, but array-valued wrappers
  // now get their entire subtree collapsed to '[REDACTED]' as intended.
  $keyMatches = static function ($key) use ($denyPatterns, $denyKeys) {
    if (in_array($key, $denyKeys, true)) return true;
    foreach ($denyPatterns as $pattern) {
      if (preg_match($pattern, (string)$key)) return true;
    }
    return false;
  };

  $walk = static function (array $node) use (&$walk, $keyMatches) {
    foreach ($node as $k => $v) {
      if ($keyMatches($k)) {
        $node[$k] = '[REDACTED]';
        continue;
      }
      if (is_array($v)) {
        $node[$k] = $walk($v);
      }
    }
    return $node;
  };

  return $walk($data);
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

  // Strip values from known-sensitive query params before path lands in the
  // log. Webhooks carry ?s=<session_token> (PhoneBurner backend is trusted,
  // temp codes don't work for multi-fire webhooks — see CLAUDE.md), OAuth
  // finish pages carry ?code=<one-time>&state=<client_id>. redact_pii_recursive
  // only filters $fields (below), not $base — so without this the raw URI with
  // the token/code embedded would land in every api.log entry for that request.
  // This became load-bearing when #226 added php.error handlers that fire on
  // every warning/notice, dramatically expanding how often path is logged.
  $path = $_SERVER['REQUEST_URI'] ?? null;
  if (is_string($path)) {
    $path = preg_replace(
      '/([?&](?:s|code|state|token|access_token|api_key|secret)=)[^&#]*/i',
      '$1[REDACTED]',
      $path
    );
  }

  $base = [
    'ts' => date('c'),
    'request_id' => $REQUEST_ID,
    'event' => $event,
    'duration_ms' => (int) round((microtime(true) - $START_TS) * 1000),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'path' => $path,
  ];

  // Silent-drop protection (#242): $base + $fields uses PHP's array UNION
  // operator, which keeps LEFT-operand values on key collision. A caller
  // passing e.g. ['path' => $filesystemPath] gets their value silently
  // dropped because $base['path'] (the URI) wins. Codex caught 3 real
  // instances of this in PR #241; the reserved-key list grows as $base
  // grows so silent-drop bugs will recur without a guard.
  //
  // Rather than array_merge (which would let callers accidentally
  // overwrite the canonical ts/request_id/event/etc.) or auto-rename
  // (which mangles grep queries and could leak PII the caller didn't
  // opt into logging under the rescued key name), we emit the COLLIDING
  // KEY NAMES under `_field_collisions`. Support triage greps for that
  // marker, finds the call site via request_id + event, and renames the
  // colliding key to a non-reserved name.
  //
  // The caller's colliding VALUES are intentionally NOT rescued — they'd
  // require redaction we can't safely apply generically, and the point
  // of this guard is to make the mistake LOUD, not to silently preserve
  // it under a different name. See LESSONS.md 2026-09-23 for the incident.
  $collisions = array_intersect_key($fields, $base);
  if (!empty($collisions)) {
    $fields['_field_collisions'] = array_keys($collisions);
  }

  // Recursively redact any sensitive keys (including nested data)
  $fields = redact_pii_recursive($fields);

  $line = json_encode($base + $fields, JSON_UNESCAPED_SLASHES) . PHP_EOL;
  @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
