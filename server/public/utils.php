<?php
// generic_crm/utils.php

function cfg()
{
    static $cfg;
    if (!$cfg) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function ensure_dir($dir)
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function log_msg($msg)
{
    $cfg = cfg();
    if (!empty($cfg['LOG_FILE'])) {
        $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
        file_put_contents($cfg['LOG_FILE'], $line, FILE_APPEND);
    }
}

function json_input()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    return $data;
}

function json_response($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *'); 
    echo json_encode($data);
    exit;
}

/**
 * We’ll have the extension send a client_id with every API call.
 */
function get_client_id_or_fail($from = null)
{
    $client_id = null;

    // 1) Prefer explicit value from parsed JSON/body
    if ($from && isset($from['client_id'])) {
        $client_id = $from['client_id'];
    }
    // 2) Fallback: URL ?client_id=...
    elseif (isset($_GET['client_id'])) {
        $client_id = $_GET['client_id'];
    }
    // 3) Fallback: X-Client-Id header (used by HubSpot popup hsPost)
    elseif (!empty($_SERVER['HTTP_X_CLIENT_ID'])) {
        $client_id = $_SERVER['HTTP_X_CLIENT_ID'];
    }

    if (!$client_id) {
        json_response(['ok' => false, 'error' => 'Missing client_id'], 400);
    }

    // Keep it filesystem-safe
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $client_id);
}

// -------------------------
// Temporary session code management (for secure dial session URLs)
// -------------------------
// Instead of embedding session tokens in URLs (?s=token), we use temporary codes (?code=CODE)
// The code is exchanged for the token once, then deleted.
// TTL: 5 minutes (300 seconds)

function temp_code_file_path(string $code): string {
    $cacheDir = cfg()['CACHE_DIR'] ?? (__DIR__ . '/cache');
    ensure_dir($cacheDir);
    // Sanitize code to prevent directory traversal
    $safe_code = preg_replace('/[^a-zA-Z0-9_-]/', '', $code);
    return $cacheDir . '/temp_code_' . $safe_code . '.json';
}

function temp_code_store(string $sessionToken, int $ttlSec = 300): string {
    $code = bin2hex(random_bytes(16));
    $data = [
        'session_token' => $sessionToken,
        'created_at' => time(),
        'expires_at' => time() + $ttlSec,
    ];
    $path = temp_code_file_path($code);
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    // Set file permissions restrictive
    @chmod($path, 0600);
    return $code;
}

function temp_code_retrieve_and_delete(string $code): ?string {
    $path = temp_code_file_path($code);
    if (!is_file($path)) return null;
    
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    
    if (!is_array($data)) {
        @unlink($path);
        return null;
    }
    
    // Check expiration
    $expires = isset($data['expires_at']) ? (int)$data['expires_at'] : 0;
    if ($expires < time()) {
        @unlink($path);
        return null;
    }
    
    $token = $data['session_token'] ?? null;
    @unlink($path);
    return $token;
}

// -------------------------
// Safe file path validation (defensive path traversal protection)
// -------------------------
// Ensures a constructed file path stays within the intended base directory.
// Uses realpath() to resolve symlinks and relative path components (../).
function safe_file_path(string $baseDir, string $relativePath): ?string {
    // Canonicalize base directory
    $base = @realpath($baseDir);
    if ($base === false) {
        return null;  // Base dir doesn't exist or isn't readable
    }
    
    // Construct and canonicalize the full path
    $full = @realpath($base . DIRECTORY_SEPARATOR . $relativePath);
    if ($full === false) {
        // File might not exist yet; resolve the parent directory instead
        $candidate = $base . DIRECTORY_SEPARATOR . $relativePath;
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);

        $parentDir = @realpath(dirname($candidate));
        if ($parentDir === false) {
            return null;  // Parent directory doesn't exist
        }
        $full = $parentDir . DIRECTORY_SEPARATOR . basename($candidate);
    }
    
    // Verify the resolved path is within base directory
    $base = realpath($base);  // Re-canonicalize for comparison
    if ($base === false || strpos($full, $base) !== 0) {
        return null;  // Path escapes base directory
    }
    
    return $full;
}

// -------------------------
// Token storage helpers
// -------------------------

/**
 * Create directory securely (0700) if missing.
 * We do NOT use ensure_dir() for tokens because it creates 0775.
 */
function ensure_dir_secure(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    @chmod($dir, 0700);
}

/**
 * Atomic write with safe permissions (0600).
 */
function atomic_write_json(string $path, array $data): void
{
    $dir = dirname($path);
    ensure_dir_secure($dir);

    $tmp = tempnam($dir, 'tmp_');
    if ($tmp === false) {
        throw new Exception('Unable to create temp file for token write');
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        @unlink($tmp);
        throw new Exception('Unable to encode token JSON');
    }

    file_put_contents($tmp, $json, LOCK_EX);
    @chmod($tmp, 0600);

    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new Exception('Unable to write token file');
    }

    @chmod($path, 0600);
}

/**
 * Return the new base tokens dir from config.
 */
function tokens_base_dir(): string
{
    $dir = cfg()['TOKENS_DIR'] ?? (__DIR__ . '/tokens');
    return rtrim($dir, '/\\');
}

/**
 * PB token file path.
 */
function pb_token_path(string $client_id): string
{
    $dir = tokens_base_dir() . '/pb';
    ensure_dir_secure($dir);
    return $dir . '/' . $client_id . '.json';
}

/**
 * HubSpot token file path.
 */
function hs_token_path(string $client_id): string
{
    $dir = tokens_base_dir() . '/hubspot';
    ensure_dir_secure($dir);
    return $dir . '/' . $client_id . '.json';
}

// -------------------------------------------------------------------------
// Token audit log
//
// Every token read/write/delete logs a structured event to a SEPARATE log
// file (NOT app.log) so we can keep it on a different retention schedule.
// Token VALUES are never logged — only the event metadata. The client_id is
// hashed before logging so the log itself isn't a customer-identifier index.
//
// The dashboard at /metrics/crm_usage_dashboard.php reads this file to
// surface a 24-hour Token Security section + anomaly detection. See
// SECURITY.md for the full security model.
// -------------------------------------------------------------------------

/**
 * Return the path to the token-audit log file, co-located with app.log
 * but distinct so retention/rotation policy can differ.
 */
function audit_token_log_path(): string
{
    $cfg = cfg();
    $appLog = (string)($cfg['LOG_FILE'] ?? '/tmp/app.log');
    return dirname($appLog) . '/token-audit.log';
}

/**
 * Log a token event.
 *
 * @param string $event   One of 'read', 'write', 'delete'.
 * @param string $provider One of 'pb', 'hubspot', 'close', 'apollo'.
 * @param string $client_id Customer client_id — will be hashed before logging.
 * @param string $result  'ok' (success), 'missing' (file not found), 'error' (parse/IO failure).
 */
function audit_token_event(string $event, string $provider, string $client_id, string $result = 'ok'): void
{
    $record = [
        't'    => date('c'),
        'evt'  => $event,
        'prov' => $provider,
        'cid'  => substr(hash('sha256', (string)$client_id), 0, 12),
        'ep'   => basename($_SERVER['SCRIPT_FILENAME'] ?? 'cli', '.php'),
        'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
        'res'  => $result,
    ];
    @file_put_contents(
        audit_token_log_path(),
        json_encode($record) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// -------------------------
// PhoneBurner PAT helpers
// -------------------------

function save_pb_token($client_id, $pat)
{
    $client_id = (string)$client_id;
    $path = pb_token_path($client_id);

    $data = [
        'pat'      => $pat,
        'saved_at' => date('c'),
    ];

    atomic_write_json($path, $data);
    audit_token_event('write', 'pb', $client_id, 'ok');
}

function load_pb_token($client_id)
{
    $client_id = (string)$client_id;
    $path = pb_token_path($client_id);

    if (!is_file($path)) {
        audit_token_event('read', 'pb', $client_id, 'missing');
        return null;
    }

    $data = json_decode(@file_get_contents($path), true);
    $pat = is_array($data) ? ($data['pat'] ?? null) : null;
    audit_token_event('read', 'pb', $client_id, $pat ? 'ok' : 'error');
    return $pat;
}

function clear_pb_token($client_id)
{
    $client_id = (string)$client_id;
    $path = pb_token_path($client_id);

    $existed = is_file($path);
    if ($existed) {
        @unlink($path);
    }
    audit_token_event('delete', 'pb', $client_id, $existed ? 'ok' : 'missing');
}

// -------------------------------------------------------------------------
// HubSpot token helpers (per client_id)
// -------------------------------------------------------------------------

function save_hs_tokens($client_id, array $tokens)
{
    $client_id = (string)$client_id;
    $path = hs_token_path($client_id);

    $tokens['saved_at'] = date('c');
    atomic_write_json($path, $tokens);
    audit_token_event('write', 'hubspot', $client_id, 'ok');
}

function load_hs_tokens($client_id)
{
    $client_id = (string)$client_id;
    $path = hs_token_path($client_id);

    if (!is_file($path)) {
        audit_token_event('read', 'hubspot', $client_id, 'missing');
        return null;
    }

    $data = json_decode(@file_get_contents($path), true);
    $result = is_array($data) ? $data : null;
    audit_token_event('read', 'hubspot', $client_id, $result ? 'ok' : 'error');
    return $result;
}

function clear_hs_tokens($client_id)
{
    $client_id = (string)$client_id;
    $path = hs_token_path($client_id);

    $existed = is_file($path);
    if ($existed) {
        @unlink($path);
    }
    audit_token_event('delete', 'hubspot', $client_id, $existed ? 'ok' : 'missing');
}

// -------------------------------------------------------------------------
// Close token helpers (per client_id)
// -------------------------------------------------------------------------

function close_token_path(string $client_id): string
{
    $dir = tokens_base_dir() . '/close';
    ensure_dir_secure($dir);
    return $dir . '/' . $client_id . '.json';
}

function save_close_tokens($client_id, array $tokens)
{
    $client_id = (string)$client_id;
    $path = close_token_path($client_id);

    $tokens['saved_at'] = date('c');
    atomic_write_json($path, $tokens);
    audit_token_event('write', 'close', $client_id, 'ok');
}

function load_close_tokens($client_id)
{
    $client_id = (string)$client_id;
    $path = close_token_path($client_id);

    if (!is_file($path)) {
        audit_token_event('read', 'close', $client_id, 'missing');
        return null;
    }

    $data = json_decode(@file_get_contents($path), true);
    $result = is_array($data) ? $data : null;
    audit_token_event('read', 'close', $client_id, $result ? 'ok' : 'error');
    return $result;
}

function clear_close_tokens($client_id)
{
    $client_id = (string)$client_id;
    $path = close_token_path($client_id);

    $existed = is_file($path);
    if ($existed) {
        @unlink($path);
    }
    audit_token_event('delete', 'close', $client_id, $existed ? 'ok' : 'missing');
}



// -------------------------------------------------------------------------
// Apollo token helpers (per client_id)
// -------------------------------------------------------------------------

function apollo_token_path(string $client_id): string
{
    $dir = tokens_base_dir() . '/apollo';
    ensure_dir_secure($dir);
    return $dir . '/' . $client_id . '.json';
}

function save_apollo_tokens($client_id, array $tokens)
{
    $client_id = (string)$client_id;
    $path = apollo_token_path($client_id);

    $tokens['saved_at'] = date('c');
    atomic_write_json($path, $tokens);
    audit_token_event('write', 'apollo', $client_id, 'ok');
}

function load_apollo_tokens($client_id)
{
    $client_id = (string)$client_id;
    $path = apollo_token_path($client_id);

    if (!is_file($path)) {
        audit_token_event('read', 'apollo', $client_id, 'missing');
        return null;
    }

    $data = json_decode(@file_get_contents($path), true);
    $result = is_array($data) ? $data : null;
    audit_token_event('read', 'apollo', $client_id, $result ? 'ok' : 'error');
    return $result;
}

function clear_apollo_tokens($client_id)
{
    $client_id = (string)$client_id;
    $path = apollo_token_path($client_id);

    $existed = is_file($path);
    if ($existed) {
        @unlink($path);
    }
    audit_token_event('delete', 'apollo', $client_id, $existed ? 'ok' : 'missing');
}


// -------------------------------------------------------------------------
// PhoneBurner API: create dial session (shared by all L3 providers)
// -------------------------------------------------------------------------

function pb_call_dialsession($pat, array $payload) {
  if (function_exists('pb_api_call')) {
    return pb_api_call($pat, 'POST', '/dialsession', $payload);
  }
  if (function_exists('pb_api')) {
    $resp = pb_api($pat, 'POST', '/dialsession', $payload);
    return [['http_code' => is_array($resp) && isset($resp['_http_code']) ? (int)$resp['_http_code'] : 200], $resp];
  }
  api_error('PhoneBurner API helper not found (pb_api_call/pb_api)', 'server_error', 500);
}

/**
 * Build a structured description of a failed external API call so callers can
 * log/surface it consistently. Pure function — no side effects.
 *
 * REQUIRED for every external API call site (PB, HubSpot, Close, Apollo, OAuth
 * exchanges, token refreshes) — see CRMS.md "Error handling requirements" and
 * CLAUDE.md Security Checklist. The point is that when an integration fails,
 * "what did the provider actually say?" must always be captured — not just the
 * HTTP code. See LESSONS.md 2026-07-27 for the customer report (Kimberly /
 * Supervest) where the diagnostic gap cost us a triage cycle.
 *
 * $info: curl_getinfo output augmented with 'raw_body' + optionally 'curl_error'.
 * $decoded: JSON-decoded body if it parsed, or null.
 *
 * Returns:
 *   [
 *     'status'       => int HTTP code,
 *     'message'      => string provider's own error message (from common shapes),
 *     'response'     => array PII-redacted decoded body, or null if unparseable,
 *     'body_snippet' => string first 500 chars of raw body, WITH OAuth token
 *                       patterns scrubbed (only populated when decode failed),
 *     'curl_error'   => string network-level error, if any,
 *   ]
 */
function describe_api_failure(?array $info, $decoded): array {
    $info = $info ?: [];
    $rawStr = (string)($info['raw_body'] ?? '');
    $bodySnippet = $rawStr !== '' ? substr($rawStr, 0, 500) : '';

    // Belt-and-suspenders scrub of OAuth/API-key patterns in the raw snippet.
    // redact_pii_recursive() only sees keys after JSON decode; when decode
    // FAILS (BOM prefix, HTML wrapper, mid-stream truncation on a CDN), the
    // raw string reaches Loggly. If a 200-OK-with-tokens body happens to fail
    // decode, we don't want the tokens on disk. Scrubs both JSON-string ("k":"v")
    // and query-string / bearer-header shapes.
    if ($bodySnippet !== '') {
        $bodySnippet = preg_replace(
            '/"(access_token|refresh_token|id_token|token|api_key|client_secret)"\s*:\s*"[^"]*"/i',
            '"$1":"[REDACTED]"',
            $bodySnippet
        );
        $bodySnippet = preg_replace(
            '/(?:Bearer\s+|access_token=|refresh_token=|api_key=)[A-Za-z0-9._~+\/=-]{20,}/i',
            '[REDACTED_TOKEN]',
            $bodySnippet
        );
    }

    // Provider error text — try common shapes in order of specificity.
    //   {"error":{"message":"..."}}                 — some HubSpot endpoints
    //   {"error_description":"..."}                 — OAuth 2.0 spec (HS/Close/Apollo OAuth)
    //   {"message":"..."}                           — HubSpot general
    //   {"error":"..."}                             — Close, some OAuth error responses
    //   {"debug_codes":[{"reason":"..."}]}          — PhoneBurner API (see LESSONS.md 2026-08-02)
    // Trimmed to 200 chars + control chars stripped before surfacing back to
    // the customer's popup via api_error extras — provider text is untrusted.
    $message = '';
    if (is_array($decoded)) {
        $message = (string)(
            $decoded['error']['message']
            ?? $decoded['error_description']
            ?? $decoded['message']
            ?? (is_string($decoded['error'] ?? null) ? $decoded['error'] : '')
            ?? ''
        );
        // PhoneBurner shape: {"status":"error","debug_codes":[{"code":N,"reason":"..."}]}
        // Only checked if nothing above matched — belt-and-suspenders for other
        // providers that might use `debug_codes` differently.
        if ($message === '' && isset($decoded['debug_codes'][0]['reason'])) {
            $message = (string)$decoded['debug_codes'][0]['reason'];
        }
    }
    if ($message !== '') {
        // Scrub OAuth token patterns from the provider message before it
        // surfaces to the customer's popup (via api_error extras -> getErrorMessage).
        // Providers occasionally echo tokens back in error text ("Invalid token:
        // pat-na1-XXXX..."), and a customer screenshot of that alert could leak
        // the token to support tickets or Loggly. Same regex applied to
        // $bodySnippet above — kept in sync. See LESSONS.md 2026-08-03
        // adversarial review finding #1.
        $message = preg_replace(
            '/"(access_token|refresh_token|id_token|token|api_key|client_secret)"\s*:\s*"[^"]*"/i',
            '"$1":"[REDACTED]"',
            $message
        );
        $message = preg_replace(
            '/(?:Bearer\s+|access_token=|refresh_token=|api_key=)[A-Za-z0-9._~+\/=-]{20,}/i',
            '[REDACTED_TOKEN]',
            $message
        );
        $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message);
        if (strlen($message) > 200) {
            $message = substr($message, 0, 200) . '…';
        }
    }

    // redact_pii_recursive lives in api/core/bootstrap.php. Guarded so unit
    // tests that only load utils.php still resolve the symbol.
    $responseRedacted = null;
    if (is_array($decoded) && function_exists('redact_pii_recursive')) {
        $responseRedacted = redact_pii_recursive($decoded);
    } elseif (is_array($decoded)) {
        $responseRedacted = $decoded;
    }

    return [
        'status'       => (int)($info['http_code'] ?? 0),
        'message'      => $message,
        'response'     => $responseRedacted,
        'body_snippet' => is_array($decoded) ? '' : $bodySnippet,
        'curl_error'   => (string)($info['curl_error'] ?? ''),
    ];
}

/**
 * PII-redaction fallback for the webhook context. bootstrap.php defines the
 * canonical redact_pii_recursive; when webhooks (which don't load bootstrap)
 * need to scrub before logging, they use this. Kept in sync with bootstrap's
 * version — if you extend the deny lists, extend BOTH. See LESSONS.md
 * 2026-08-02 for the incident: adversarial re-review caught that the webhook
 * fallback of `_pb_write_api_log` was writing unredacted data to Loggly.
 *
 * Guarded by `function_exists` so endpoints that already loaded bootstrap
 * (endpoint context) keep their canonical version. Webhook-only loads get
 * this fallback defined instead.
 */
if (!function_exists('redact_pii_recursive')) {
    function redact_pii_recursive(array $data): array {
        $denyPatterns = [
            '/^.*email.*$/i',
            '/^.*phone.*$/i',
            '/^.*token.*$/i',
            '/^.*password.*$/i',
            '/^.*secret.*$/i',
            '/^.*auth.*$/i',
            '/^.*(paypal|credit|card).*$/i',
            '/^.*(first_name|last_name|full_name|contact_name)$/i',
            '/^.*crm_identifier.*$/i',
        ];
        $denyKeys = ['payload', 'contacts', 'response_body', 'request_body'];

        array_walk_recursive($data, function (&$value, $key) use ($denyPatterns, $denyKeys) {
            if (in_array($key, $denyKeys, true)) {
                $value = '[REDACTED]';
                return;
            }
            foreach ($denyPatterns as $pattern) {
                if (preg_match($pattern, (string)$key)) {
                    $value = '[REDACTED]';
                    return;
                }
            }
        });
        return $data;
    }
}

/**
 * Webhook-safe api_log fallback. Writes a JSON line to the same api.log file
 * bootstrap.php's api_log writes to, so support can grep by Support ID from
 * ANY context (endpoint or webhook). Callable when bootstrap.php isn't loaded
 * — webhooks (call_done.php, softphone_call_done.php) intentionally avoid
 * bootstrap.php's HTTP-response setup, so functions defined there (api_log,
 * redact_pii_recursive) aren't available.
 *
 * See LESSONS.md 2026-08-02 for the incident that surfaced this — direct
 * api_log() calls in the call-logger token-refresh path (PR #190) would have
 * fatal-errored the moment a webhook fired during token expiry. The bug lay
 * dormant because no Close/Apollo token happened to need refresh during a
 * webhook fire in the dev soak window. Caught by adversarial review before
 * prod, but the class needs the fallback below to actually close.
 */
function _pb_write_api_log(string $event, array $fields): void {
    // Prefer bootstrap.php's api_log if loaded (endpoint context) — same
    // shape, same file, plus the request_id/duration wired via bootstrap's
    // globals. bootstrap.php's api_log redacts internally.
    if (function_exists('api_log')) {
        api_log($event, $fields);
        return;
    }

    // Fallback path — no bootstrap loaded. Mirror bootstrap.php's file
    // destination, JSON shape, AND redaction so entries land uniformly for
    // Support-ID grep. redact_pii_recursive is conditionally defined above
    // for exactly this case. Path calc: utils.php is at server/public/,
    // dirname(__DIR__, 2) = repo root, matches bootstrap.php's dirname(__DIR__, 4).
    $fields = redact_pii_recursive($fields);

    $logDir = defined('PB_LOG_DIR')
        ? PB_LOG_DIR
        : (dirname(__DIR__, 2) . '/var/log');
    $logFile = rtrim($logDir, '/\\') . '/api.log';
    if (!is_dir($logDir)) @mkdir($logDir, 0770, true);

    global $REQUEST_ID, $START_TS;
    $base = [
        'ts'          => date('c'),
        'request_id'  => $REQUEST_ID ?? null,
        'event'       => $event,
        'duration_ms' => isset($START_TS) ? (int) round((microtime(true) - $START_TS) * 1000) : null,
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        'method'      => $_SERVER['REQUEST_METHOD'] ?? null,
        'path'        => $_SERVER['REQUEST_URI'] ?? null,
    ];
    $line = json_encode($base + $fields, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Log a structured api_log entry for a failed external-API call, given the
 * [$code, $json, $raw] tuple shape that provider helpers like hs_api_get_json,
 * close_api_get_json, and apollo_api_post_json return. Thin wrapper around
 * describe_api_failure that constructs the $info shape it expects.
 *
 * Use at any callsite that receives a [$code, $json, $raw] tuple from a
 * provider helper — e.g.:
 *
 *   list($code, $json, $raw) = hs_api_get_json($access, $url);
 *   if ($code !== 200) {
 *     log_api_failure_from_tuple($code, $json, $raw, 'hs_lists.fetch_failed', [
 *       'client_id_hash' => $hash,
 *       'list_id'        => $listId,
 *     ]);
 *     api_error('Failed to load HubSpot list', 'hs_error', 502);
 *   }
 *
 * The api_log entry always includes status + provider_msg + response +
 * body_snippet + curl_error alongside any per-callsite context in $extra.
 * See LESSONS.md 2026-07-27 and 2026-08-02 for the class of gap this closes.
 */
function log_api_failure_from_tuple(int $code, $json, $raw, string $event, array $extra = []): array {
    $info = [
        'http_code' => $code,
        'raw_body'  => is_string($raw) ? $raw : '',
    ];
    $fail = describe_api_failure($info, $json);
    _pb_write_api_log($event, array_merge([
        'status'       => $fail['status'],
        'provider_msg' => $fail['message'],
        'response'     => $fail['response'],
        'body_snippet' => $fail['body_snippet'],
        'curl_error'   => $fail['curl_error'],
    ], $extra));
    // Return $fail so callers can reuse it for api_error extras
    // (pb_message) without re-invoking describe_api_failure.
    return $fail;
}

/**
 * Call PhoneBurner /dialsession and, on failure, log + api_error with enough
 * detail to actually diagnose. On success returns a tuple:
 *
 *     ['response' => array, 'pb_ms' => int, 'pb_http' => int]
 *
 * Callers unpack it — the helper exposes pb_ms + pb_http so downstream
 * api_log / api_ok_flat calls can still surface the timing and status
 * breadcrumbs that used to live inline. On failure calls api_error (which
 * exits), so callers don't need to handle the failure return.
 *
 * $endpointLabel: short slug identifying the calling endpoint (e.g.
 * "hs_selection", "close_selection", "generic_scan"). Surfaced in the log
 * line so a customer report can be correlated to the exact code path.
 *
 * $extraLog: additional per-endpoint context (client_id_hash, contact count,
 * etc.) merged into the api_log entry. Runs through PII redaction — safe to
 * include names/emails/phones if the caller wants them for triage.
 *
 * Why this exists: on 2026-07-27 a customer (Kimberly, Supervest) reported
 * "PhoneBurner dialsession failed" with no way to diagnose — we'd stripped
 * PB's error body before logging anything, and 6 endpoints had inlined the
 * same shallow failure handler independently. Centralizing that handler
 * fixes both the diagnostic gap and the drift-across-copies risk.
 */
function pb_dialsession_or_fail(string $pat, array $payload, string $endpointLabel, array $extraLog = []): array {
    $t0 = microtime(true);
    list($info, $resp) = pb_call_dialsession($pat, $payload);
    $pb_ms = (int) round((microtime(true) - $t0) * 1000);

    $httpCode = (int)($info['http_code'] ?? 0);
    if ($httpCode >= 200 && $httpCode < 400 && is_array($resp)) {
        return [
            'response' => $resp,
            'pb_ms'    => $pb_ms,
            'pb_http'  => $httpCode,
        ];
    }

    // Failure path — use the shared descriptor so every provider-integration
    // failure logs the same structured shape (see describe_api_failure).
    $fail = describe_api_failure($info, $resp);

    $logFields = array_merge([
        'endpoint'        => $endpointLabel,
        'pb_http'         => $fail['status'],
        'pb_ms'           => $pb_ms,
        'pb_message'      => $fail['message'],
        'pb_response'     => $fail['response'],
        'pb_body_snippet' => $fail['body_snippet'],
        'curl_error'      => $fail['curl_error'],
    ], $extraLog);

    if (function_exists('api_log')) {
        api_log('pb_dialsession.error', $logFields);
    }

    if (function_exists('api_error')) {
        api_error('PhoneBurner dialsession failed', 'pb_error', 502, [
            'pb_http'    => $fail['status'],
            'pb_ms'      => $pb_ms,
            'pb_message' => $fail['message'] ?: null,
        ]);
    }

    // Only reachable if api_error is missing (test context). Return the same
    // shape the success path uses so a strict-mode caller doesn't hit a type
    // error — response is an empty array (falsy) so callers can still
    // detect the anomaly.
    return [
        'response' => [],
        'pb_ms'    => $pb_ms,
        'pb_http'  => $fail['status'],
    ];
}

function get_user_settings_dir()
{
    $cfg = cfg();
    return isset($cfg['USER_SETTINGS_DIR'])
        ? $cfg['USER_SETTINGS_DIR']
        : __DIR__ . '/user_settings';
}

/**
 * Path to the client_id -> member_user_id index file.
 * Lives inside the user_settings dir but is filtered out of file scans
 * by the leading underscore.
 */
function client_index_file_path()
{
    $dir = get_user_settings_dir();
    ensure_dir($dir);
    return $dir . '/_client_index.json';
}

/**
 * Load the client_id -> member_user_id index. Returns [] if missing/unreadable.
 */
function load_client_index()
{
    $path = client_index_file_path();
    if (!file_exists($path)) {
        return [];
    }
    $json = @file_get_contents($path);
    if ($json === false || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Insert/update a single client_id -> member_user_id mapping in the index.
 * Uses flock for read-modify-write safety. Best-effort: failures are logged
 * but never thrown (lookups self-heal via fallback scan).
 */
function update_client_index($client_id, $memberUserId)
{
    $client_id = (string)$client_id;
    $memberUserId = (string)$memberUserId;
    if ($client_id === '' || $memberUserId === '') {
        return;
    }

    $path = client_index_file_path();
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        log_msg("update_client_index: failed to open $path");
        return;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        log_msg("update_client_index: failed to lock $path");
        return;
    }

    $raw = stream_get_contents($fh);
    $idx = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
    if (!is_array($idx)) {
        $idx = [];
    }

    if (!isset($idx[$client_id]) || $idx[$client_id] !== $memberUserId) {
        $idx[$client_id] = $memberUserId;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($idx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Find the PhoneBurner member_user_id for a given client_id.
 *
 * Strategy:
 *   1. O(1) lookup in _client_index.json (the cache).
 *   2. On miss, fall back to scanning user_settings/*.json (legacy / first call
 *      before the index is populated) and self-heal the index with the result.
 *
 * Returns member_user_id (string) or null if not found.
 */
function resolve_member_user_id_for_client($client_id)
{
    $client_id = (string)$client_id;
    if ($client_id === '') {
        return null;
    }

    // 1) Fast path: index lookup
    $idx = load_client_index();
    if (isset($idx[$client_id]) && $idx[$client_id] !== '') {
        return (string)$idx[$client_id];
    }

    // 2) Fallback: scan user_settings files
    $dir = get_user_settings_dir();
    if (!is_dir($dir)) {
        return null;
    }

    $files = glob($dir . '/*.json');
    if (!$files) {
        return null;
    }

    foreach ($files as $file) {
        // Skip internal files (e.g. _client_index.json)
        if (strpos(basename($file), '_') === 0) {
            continue;
        }

        $json = file_get_contents($file);
        if ($json === false || $json === '') {
            continue;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            continue;
        }

        $clientIds = $data['client_ids'] ?? [];
        if (!is_array($clientIds)) {
            continue;
        }

        if (in_array($client_id, $clientIds, true)) {
            $memberUserId = isset($data['member_user_id'])
                ? (string)$data['member_user_id']
                : null;

            // Self-heal the index so the next call is O(1)
            if ($memberUserId !== null && $memberUserId !== '') {
                update_client_index($client_id, $memberUserId);
            }
            return $memberUserId;
        }
    }

    return null;
}

/**
 * Save user settings back to disk (full overwrite for that member_user_id).
 */
function save_user_settings($memberUserId, array $settings)
{
    $path = user_settings_file_path($memberUserId);
    $result = file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX);
    if ($result === false) {
        log_msg("save_user_settings: failed to write $path");
    }
}



function user_settings_file_path($memberUserId)
{
    // Use the configured USER_SETTINGS_DIR if present; fall back to ./user_settings
    $cfg = cfg();
    $dir = isset($cfg['USER_SETTINGS_DIR'])
        ? $cfg['USER_SETTINGS_DIR']
        : __DIR__ . '/user_settings';

    ensure_dir($dir);

    // Keep filenames safe
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$memberUserId);

    return $dir . '/' . $safeId . '.json';
}

function load_user_settings($memberUserId)
{
    $path = user_settings_file_path($memberUserId);
    if (!file_exists($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Save or update the per-user settings file when a PAT is saved.
 *
 * - Keyed by PhoneBurner member_user_id (agent id)
 * - Tracks which browser client_ids are associated with this PB user
 * - Stores a "profile" section from /members/me
 * - Leaves room for crm_patterns and goals later
 */
function save_user_profile($client_id, $memberUserId, $memberData)
{
    $path = user_settings_file_path($memberUserId);

    // Load existing settings if present
    $existing = load_user_settings($memberUserId);

    $now = date('c');

    $settings = [
        'member_user_id' => (string)$memberUserId,
        'client_ids'     => [],
        'profile'        => [],
        // Placeholders for future config:
        'crm_patterns'   => [],
        'goals'          => [],
        'created_at'     => $now,
        'updated_at'     => $now,
    ];

    if (is_array($existing)) {
        // Preserve existing data (patterns, goals, timestamps)
        $settings['crm_patterns'] = $existing['crm_patterns'] ?? [];
        $settings['goals']        = $existing['goals']        ?? [];
        $settings['created_at']   = $existing['created_at']   ?? $now;
        $settings['client_ids']   = $existing['client_ids']   ?? [];
    }

    // Merge this client_id into the list (avoid duplicates)
    if (!in_array($client_id, $settings['client_ids'], true)) {
        $settings['client_ids'][] = $client_id;
    }

    // Store some basic profile info from /members/me
    $settings['profile'] = [
        'username'      => $memberData['username']      ?? null,
        'first_name'    => $memberData['first_name']    ?? null,
        'last_name'     => $memberData['last_name']     ?? null,
        'email_address' => $memberData['email_address'] ?? null,
        'display_name'  => $memberData['display_name']  ?? null,
    ];

    $settings['updated_at'] = $now;

    file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT));

    // Maintain the client_id -> member_user_id index for O(1) reverse lookups.
    update_client_index($client_id, $memberUserId);
}


function session_file_path($session_token)
{
    $safe = preg_replace('/[^a-zA-Z0-9]/', '', $session_token);
    return __DIR__ . '/sessions/' . $safe . '.json';
}

function save_session_state($session_token, array $state)
{
    $path = session_file_path($session_token);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0770, true);
    }
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    file_put_contents($path, $json, LOCK_EX);
    @chmod($path, 0660);
}

function load_session_state($session_token)
{
    $path = session_file_path($session_token);
    if (!file_exists($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}



/**
 * form-urlencoded POST → JSON response, preserving curl $info + raw response
 * body so callers can pass the tuple to describe_api_failure() on the failure
 * path. Same URL/timeout/decode behavior as http_post_form; the only
 * difference is the return shape:
 *
 *   [$info, $decoded]   where $info includes ['raw_body' => "..."] and,
 *                       on network failure, ['curl_error' => "..."] + http_code=0
 *
 * Use this for any OAuth token exchange or refresh call — describe_api_failure
 * pulls the provider's own error text out of the body so a bad_client_id vs
 * expired_code vs network_timeout is diagnosable from one Loggly line.
 */
function http_post_form_info($url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($err) {
        log_msg("http_post_form_info error: $err");
        return [['curl_error' => $err, 'http_code' => 0, 'raw_body' => ''], null];
    }

    $info['raw_body'] = is_string($resp) ? $resp : '';
    $data = json_decode($resp, true);

    return [$info, $data];
}



/**
 * Basic PhoneBurner API call helper using PAT.
 * $method: 'GET', 'POST', 'PUT', etc.
 * $path: e.g. '/dialsession' (we’ll assemble with PB_API_BASE).
 */
function pb_api_call($pat, $method, $path, $body = null)
{
    $url = rtrim(cfg()['PB_API_BASE'], '/') . '/' . ltrim($path, '/');

    $headers = [
        'Authorization: Bearer ' . $pat,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if (!is_null($body)) {
        $payload = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        log_msg("pb_api_call error: $err");
        // Return an $info array (not null) so callers can uniformly read
        // ['curl_error'] / ['http_code'] without null-guarding the tuple.
        return [['curl_error' => $err, 'http_code' => 0], ['error' => $err]];
    }

    // Preserve the raw response body in $info so callers on the failure path
    // can log/surface PB's own error text. Existing callers ignore this key
    // and see no behavior change; new callers (pb_dialsession_or_fail) rely
    // on it for diagnostic breadcrumbs. See LESSONS.md 2026-07-27 for the
    // customer report (Kimberly / Supervest) that motivated the capture.
    $info['raw_body'] = is_string($resp) ? $resp : '';

    $data = json_decode($resp, true);
    return [$info, $data];
}
/**
 * Rate limiting per client_id using file-based rolling window.
 *
 * Tracks request timestamps in a cache file. Returns true if under limit.
 * File format: comma-separated unix timestamps within the last 60 seconds.
 *
 * Each endpoint gets its own counter (scoped by script filename) so that
 * high-frequency endpoints like track_crm_usage or refresh_sse_code don't
 * eat into the budget for user-facing operations like dial session launches.
 */
function rate_limit_check(string $client_id, int $maxPerMinute = 60): bool {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0770, true);
    }

    // Scope rate limit per endpoint so counters don't collide
    $scope = basename($_SERVER['SCRIPT_FILENAME'] ?? 'global', '.php');
    $cacheFile = $cacheDir . '/rl_' . hash('sha256', $client_id . ':' . $scope) . '.txt';
    $now = time();
    $window = $now - 60;  // 60-second rolling window
    
    // Read existing timestamps
    $data = @file_get_contents($cacheFile);
    $times = $data ? array_map('intval', explode(',', trim($data))) : [];
    
    // Filter to timestamps within the rolling window
    $times = array_filter($times, fn($t) => $t > $window);
    
    // Check if under limit
    $underLimit = count($times) < $maxPerMinute;
    
    // Add current timestamp and write back
    $times[] = $now;
    @file_put_contents($cacheFile, implode(',', $times), LOCK_EX);
    
    return $underLimit;
}

/**
 * Enforce rate limit or fail with 429 Too Many Requests.
 * 
 * Call this at the start of endpoints that need rate limiting.
 */
function rate_limit_or_fail(string $client_id, int $maxPerMinute = 60): void {
    if (!rate_limit_check($client_id, $maxPerMinute)) {
        header('Retry-After: 60');
        api_error('Rate limit exceeded', 'rate_limited', 429);
    }
}

/**
 * Clean up stale rate limit cache files older than 1 hour.
 *
 * Call this periodically (e.g., daily cron) to prevent cache bloat.
 */
function cleanup_rate_limit_cache(): void {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        return;
    }

    $cutoff = time() - 3600;  // 1 hour ago

    foreach (glob($cacheDir . '/rl_*.txt') as $file) {
        if (@filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

// -------------------------------------------------------------------------
// Click-to-Call intent bridge
//
// The problem: on the softphone_call_done webhook, PhoneBurner gives us the
// agent's pb_user_id and the dialed phone number, but NOT the extension's
// client_id (needed to load HubSpot tokens) or the HubSpot task_id (needed
// to complete the task on disposition). PhoneBurner drops arbitrary
// custom_data we try to pass through — confirmed empirically 2026-07-08.
//
// The bridge: at CTC-click time, softphone_auth_code.php writes an "intent"
// record to disk keyed by (pb_user_id, normalized phone). When the webhook
// fires, softphone_call_done.php looks up the record, pulls out client_id
// + task_id, completes the task, and consumes the record.
//
// FIFO queue on the same key:
//   Same (pb_user_id, phone) pair can have multiple pending intents if a
//   customer clicks CTC on two tasks that share a phone number back-to-
//   back. Since PhoneBurner's softphone is single-call-per-agent, calls
//   disposition in the order they were dialed — we consume the OLDEST
//   pending intent on each webhook. Correct by construction.
//
// Cleanup without cron:
//   1. Consume path — the webhook removes the consumed entry and deletes
//      the file when empty.
//   2. Write path — before appending, prune stale entries (> 24h old) from
//      the same file.
//   3. Sweep-on-write — every N writes, do a directory sweep to catch
//      files with only stale entries (customer clicked CTC but never
//      completed the call).
//
// Audit log:
//   Consumed intents append event_type=ctc_task_completed to the existing
//   metrics/crm_usage-YYYY-MM-DD.log — inherits the daily-rotation +
//   logrotate retention that's already in place. No new log surface.
// -------------------------------------------------------------------------

/**
 * Directory for CTC intent files. Co-located with tokens because they
 * share sensitivity (bridge between the customer's PB identity and their
 * extension client_id).
 */
function ctc_intents_dir(): string
{
    $dir = tokens_base_dir() . '/ctc_intents';
    ensure_dir_secure($dir);
    return $dir;
}

/**
 * Normalize a phone number for use as a lookup key. Strips everything
 * except digits — the same input format the softphone sees and the same
 * shape softphone_call_done receives.
 */
function ctc_normalize_phone(string $phone): string
{
    return preg_replace('/\D/', '', $phone);
}

/**
 * Build the intent file path for a given (pb_user_id, phone) pair.
 * Returns null if the sanitized inputs would produce an invalid path.
 */
function ctc_intent_file_path(string $pb_user_id, string $phone): ?string
{
    $normalizedPhone = ctc_normalize_phone($phone);
    if ($pb_user_id === '' || $normalizedPhone === '') {
        return null;
    }
    $userHash = substr(hash('sha256', (string)$pb_user_id), 0, 12);
    $filename = $userHash . '_' . $normalizedPhone . '.json';
    return ctc_intents_dir() . '/' . $filename;
}

/**
 * Read + decode the JSON array at the intent path. Returns [] if the file
 * doesn't exist or is unparseable (never null — callers append to the result).
 */
function ctc_intent_read_file(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Prune entries older than TTL from an intent list. Called on every write
 * and consume so stale entries don't accumulate on a busy shared phone.
 */
function ctc_intent_prune_stale(array $intents, int $ttlSec = 86400): array
{
    $cutoff = time() - $ttlSec;
    return array_values(array_filter($intents, function ($entry) use ($cutoff) {
        $mintedAt = isset($entry['minted_at']) ? (int)$entry['minted_at'] : 0;
        return $mintedAt >= $cutoff;
    }));
}

/**
 * Write a new CTC intent onto the FIFO queue at (pb_user_id, phone).
 *
 * The intent record carries `crm_name` so the webhook consumer can
 * dispatch to the right provider's task-completer without inferring
 * from context. Currently only "hubspot" is implemented on the consume
 * side, but the storage layer is CRM-agnostic — adding Close / Apollo
 * task completion later means adding a new dispatch case in
 * softphone_call_done.php + a new completer helper, not changing the
 * intent shape or key structure. See CRMS.md for the walkthrough.
 *
 * The (pb_user_id, phone) key is CRM-agnostic by construction — pb_user_id
 * is the PhoneBurner agent identity and stays stable across CRMs, so a
 * single customer with HubSpot + Close both connected would land distinct
 * intents at the same key correctly (FIFO consume, each dispatched to its
 * own provider).
 *
 * @return bool true on write success, false on missing inputs / IO failure.
 */
function ctc_intent_write(
    string $pb_user_id,
    string $phone,
    string $client_id,
    string $task_id,
    string $crm_name
): bool {
    $path = ctc_intent_file_path($pb_user_id, $phone);
    if ($path === null || $client_id === '' || $task_id === '' || $crm_name === '') {
        return false;
    }

    $existing = ctc_intent_read_file($path);
    $existing = ctc_intent_prune_stale($existing);

    $existing[] = [
        'client_id'  => $client_id,
        'task_id'    => $task_id,
        'crm_name'   => $crm_name,
        'minted_at'  => time(),
    ];

    // atomic_write_json enforces 0600 file perms. Directory is 0700 via
    // ensure_dir_secure in ctc_intents_dir().
    try {
        atomic_write_json($path, $existing);
    } catch (\Throwable $e) {
        log_msg('ctc_intent_write_error: ' . $e->getMessage());
        return false;
    }

    // Rate-limited directory sweep. Every ~20 writes, scan the whole
    // directory for files whose youngest entry is stale and delete them.
    // Catches the "customer clicked CTC then closed the tab" long tail
    // without needing cron. Cheap: bounded by intent-file count, which
    // stays small even at 1000+ CTC clicks/day (files are consumed by
    // webhooks or pruned by same-key writes).
    if (random_int(0, 19) === 0) {
        ctc_intent_sweep_stale_files();
    }

    return true;
}

/**
 * Consume the oldest pending intent at (pb_user_id, phone).
 *
 * Returns the popped intent (with client_id + task_id) on hit, null on miss.
 * Deletes the file when the queue is empty; otherwise rewrites it.
 * Prunes stale entries as a side effect.
 */
function ctc_intent_consume(string $pb_user_id, string $phone): ?array
{
    $path = ctc_intent_file_path($pb_user_id, $phone);
    if ($path === null) return null;

    $intents = ctc_intent_read_file($path);
    $intents = ctc_intent_prune_stale($intents);

    if (empty($intents)) {
        // File exists but all entries stale — clean it up.
        if (is_file($path)) @unlink($path);
        return null;
    }

    $popped = array_shift($intents);

    if (empty($intents)) {
        // Last entry consumed — delete the file rather than write an empty array.
        @unlink($path);
    } else {
        try {
            atomic_write_json($path, $intents);
        } catch (\Throwable $e) {
            // Consume already computed; if the rewrite fails, the file is left
            // with the OLD contents including the just-consumed entry. That
            // would let the same webhook double-fire task completion on a
            // retry. Log loud so we notice.
            log_msg('ctc_intent_consume_rewrite_error: ' . $e->getMessage());
        }
    }

    return $popped;
}

/**
 * Directory-wide sweep. Deletes any intent file whose newest entry is
 * older than the TTL. Called opportunistically from ctc_intent_write to
 * clean up orphaned intents without a cron.
 */
function ctc_intent_sweep_stale_files(int $ttlSec = 86400): void
{
    $dir = ctc_intents_dir();
    $files = @glob($dir . '/*.json') ?: [];
    $cutoff = time() - $ttlSec;

    foreach ($files as $file) {
        $intents = ctc_intent_read_file($file);
        if (empty($intents)) {
            @unlink($file); // empty file — nothing to protect
            continue;
        }
        // Find newest minted_at in the file.
        $newest = 0;
        foreach ($intents as $entry) {
            $ts = isset($entry['minted_at']) ? (int)$entry['minted_at'] : 0;
            if ($ts > $newest) $newest = $ts;
        }
        if ($newest < $cutoff) {
            @unlink($file);
        }
    }
}