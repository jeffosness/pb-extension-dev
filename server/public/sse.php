<?php
// server/public/sse.php
//
// Server-Sent Events stream that watches a session state file and emits updates.
// Uses core/bootstrap.php for shared hardening/CORS/OPTIONS behavior.
// IMPORTANT: This endpoint is SSE, not JSON, so we opt out of JSON headers.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/api/core/bootstrap.php';
require_once __DIR__ . '/utils.php';

// -------------------------
// Read session token (via temporary code or direct token)
// -------------------------
// For security, session tokens should be passed via temporary codes (?code=CODE)
// which are single-use and expire after 5 minutes.
// Direct token query params (?s=...) are deprecated but still supported for compatibility.
$session_token = '';

// Try temp code first (preferred method)
$code = $_GET['code'] ?? $_GET['temp_code'] ?? '';
if ($code) {
    $session_token = temp_code_retrieve_and_delete($code);
    if (!$session_token) {
        echo "event: error\n";
        echo 'data: ' . json_encode(['error' => 'Invalid or expired session code'], JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush(); @flush();
        exit;
    }
}

// Fallback: direct token (deprecated, for backward compatibility)
if (!$session_token) {
    $session_token =
      $_GET['s']
      ?? $_GET['session']
      ?? $_GET['sessionToken']
      ?? '';
}

// -------------------------
// SSE headers
// -------------------------
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

@set_time_limit(0);
@ignore_user_abort(true);

// Optional debug logs (keep small; avoid token contents)
log_msg("SSE PATH=" . parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
log_msg("SSE _GET keys=" . json_encode(array_keys($_GET ?? [])));

// -------------------------
// Helpers
// -------------------------
function hash12(string $value): string {
  return substr(hash('sha256', $value), 0, 12);
}

function ensure_dir_local(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0770, true);
  }
}

/**
 * Append a single JSON line to the daily SSE usage log.
 * Path: server/public/metrics/sse_usage-YYYY-MM-DD.log
 */
function sse_log_activity(string $event, array $data = []): void {
  $metricsDir = __DIR__ . '/metrics';
  ensure_dir_local($metricsDir);

  // Use safe_file_path for defensive path traversal protection
  $logFile = safe_file_path($metricsDir, 'sse_usage-' . date('Y-m-d') . '.log');
  if (!$logFile) {
    return;  // Path validation failed; skip logging
  }

  $record = array_merge([
    'event'   => $event,
    'ts'      => date('c'),
    'unix_ts' => time(),
  ], $data);

  @file_put_contents(
    $logFile,
    json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND | LOCK_EX
  );
}

/**
 * Presence file (overwritten periodically) to track "active now" without scanning logs.
 * Path: server/public/metrics/sse_presence/{session_hash}.json
 */
function sse_presence_write(string $sessionHash, int $connectUnix, int $lastSeenUnix): void {
  $presenceDir = __DIR__ . '/metrics/sse_presence';
  ensure_dir_local($presenceDir);

  // Use safe_file_path for defensive path traversal protection
  $file = safe_file_path($presenceDir, $sessionHash . '.json');
  if (!$file) {
    return;  // Path validation failed; skip write
  }

  $payload = [
    'session'        => $sessionHash,
    'connect_unix'   => $connectUnix,
    'last_seen_unix' => $lastSeenUnix,
  ];

  @file_put_contents(
    $file,
    json_encode($payload, JSON_UNESCAPED_SLASHES),
    LOCK_EX
  );
}

function sse_presence_delete(string $sessionHash): void {
  $presenceDir = __DIR__ . '/metrics/sse_presence';
  // Use safe_file_path for defensive path traversal protection
  $file = safe_file_path($presenceDir, $sessionHash . '.json');
  if ($file && is_file($file)) {
    @unlink($file);
  }
}

// -------------------------
// Validate token
// -------------------------
if (!$session_token) {
  echo "event: error\n";
  echo 'data: ' . json_encode(['error' => 'Missing session token'], JSON_UNESCAPED_SLASHES) . "\n\n";
  @ob_flush(); @flush();
  exit;
}

$sessionHash      = hash12((string)$session_token);
$ipHash           = hash12((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$connectionStart  = time();
$lastKeepalive    = $connectionStart;

// Presence write cadence (overwrites file)
$presenceIntervalSec = 120;
$lastPresenceWrite   = 0;

// Inactivity timeout: close SSE if no webhook activity for 60 minutes
$inactivityTimeoutSec = 3600; // 60 minutes
$lastWebhookActivity  = $connectionStart;

// Avoid double-disconnect logging
$didFinalize = false;

// Ensure we log disconnect even if PHP exits unexpectedly
// NOTE: We do NOT delete presence files here because SSE reconnects
// during CRM navigation should not clear the "active now" status.
// Stale presence files are cleaned up by cron (see CLAUDE.md).
register_shutdown_function(function () use (&$didFinalize, $sessionHash, $connectionStart) {
  if ($didFinalize) return;

  // Best-effort: log disconnect (but keep presence file)
  sse_log_activity('sse.disconnect', [
    'session_token_hash' => $sessionHash,
    'duration_sec'       => time() - $connectionStart,
    'shutdown'           => true,
  ]);

  $didFinalize = true;
});

// -------------------------
// Session state file
// -------------------------
$path       = session_file_path($session_token);
$last_mtime = 0;

// Resolve member_user_id from session state for unique user tracking
$sessionState = load_session_state($session_token);
$memberUserIdHash = null;
if (is_array($sessionState) && !empty($sessionState['member_user_id'])) {
    $memberUserIdHash = substr(hash('sha256', (string)$sessionState['member_user_id']), 0, 12);
}

// Log connect + initial presence
sse_log_activity('sse.connect', [
  'session_token_hash'   => $sessionHash,
  'ip_hash'              => $ipHash,
  'member_user_id_hash'  => $memberUserIdHash,
]);

sse_presence_write($sessionHash, $connectionStart, $connectionStart);
$lastPresenceWrite = $connectionStart;

// Optional: initial hello event (does not affect clients that only listen for "update")
echo ": connected\n\n";
@ob_flush(); @flush();

while (true) {
  if (connection_aborted()) break;

  clearstatcache(true, $path);

  // Emit update when session state file changes
  if (file_exists($path)) {
    $mtime = @filemtime($path);
    if ($mtime && $mtime !== $last_mtime) {
      $last_mtime = $mtime;

      $data = @file_get_contents($path);
      if ($data === false || $data === '') {
        $data = json_encode(['error' => 'empty session state'], JSON_UNESCAPED_SLASHES);
      } else {
        // Check if webhooks have updated last_activity_unix
        $sessionState = json_decode($data, true);
        if (is_array($sessionState) && isset($sessionState['last_activity_unix'])) {
          $lastWebhookActivity = (int)$sessionState['last_activity_unix'];
        }
      }

      echo "event: update\n";
      echo "id: {$mtime}\n";
      echo "data: {$data}\n\n";
      @ob_flush(); @flush();
    }
  }

  // ✅ INACTIVITY TIMEOUT: Close connection if no webhook activity for 60 minutes
  $inactiveSec = time() - $lastWebhookActivity;
  if ($inactiveSec > $inactivityTimeoutSec) {
    sse_log_activity('sse.timeout_inactive', [
      'session_token_hash' => $sessionHash,
      'duration_sec' => time() - $connectionStart,
      'inactive_sec' => $inactiveSec,
    ]);
    // Delete presence file on true timeout (session ended)
    sse_presence_delete($sessionHash);
    $didFinalize = true;
    break;
  }

  // Keepalive comment line (existing behavior)
  if (time() - $lastKeepalive >= 20) {
    echo ": keepalive\n\n";
    @ob_flush(); @flush();
    $lastKeepalive = time();
  }

  // Presence update (overwrites presence file; no JSONL heartbeat spam)
  if (time() - $lastPresenceWrite >= $presenceIntervalSec) {
    sse_presence_write($sessionHash, $connectionStart, time());
    $lastPresenceWrite = time();
  }

  sleep(1);
}

// Normal disconnect path (SSE reconnect during navigation)
// NOTE: We do NOT delete presence files on normal disconnects because
// the extension reconnects SSE when navigating to new contacts.
// Presence files remain active and are cleaned up by cron when stale.
sse_log_activity('sse.disconnect', [
  'session_token_hash' => $sessionHash,
  'duration_sec'       => time() - $connectionStart,
  'shutdown'           => false,
]);

$didFinalize = true;
