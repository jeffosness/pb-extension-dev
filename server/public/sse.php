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
// Read session token
// -------------------------
$session_token =
  $_GET['s']
  ?? $_GET['session']
  ?? $_GET['sessionToken']
  ?? '';

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

  $logFile = $metricsDir . '/sse_usage-' . date('Y-m-d') . '.log';

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

  $file = $presenceDir . '/' . $sessionHash . '.json';
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
  $file = __DIR__ . '/metrics/sse_presence/' . $sessionHash . '.json';
  if (is_file($file)) {
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

// Avoid double-disconnect logging
$didFinalize = false;

// Ensure we log disconnect + cleanup even if PHP exits unexpectedly
register_shutdown_function(function () use (&$didFinalize, $sessionHash, $connectionStart) {
  if ($didFinalize) return;

  // Best-effort: log disconnect + cleanup presence
  sse_log_activity('sse.disconnect', [
    'session_token_hash' => $sessionHash,
    'duration_sec'       => time() - $connectionStart,
    'shutdown'           => true,
  ]);
  sse_presence_delete($sessionHash);

  $didFinalize = true;
});

// -------------------------
// Session state file
// -------------------------
$path       = session_file_path($session_token);
$last_mtime = 0;

// Log connect + initial presence
sse_log_activity('sse.connect', [
  'session_token_hash' => $sessionHash,
  'ip_hash'            => $ipHash,
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
      }

      echo "event: update\n";
      echo "id: {$mtime}\n";
      echo "data: {$data}\n\n";
      @ob_flush(); @flush();
    }
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

// Normal disconnect path (also handled by shutdown fallback)
sse_log_activity('sse.disconnect', [
  'session_token_hash' => $sessionHash,
  'duration_sec'       => time() - $connectionStart,
  'shutdown'           => false,
]);

sse_presence_delete($sessionHash);
$didFinalize = true;
