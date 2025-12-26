<?php
// server/public/sse.php
//
// Server-Sent Events stream that watches a session state file and emits updates.
// Uses core/bootstrap.php for shared hardening/CORS/OPTIONS behavior.
// IMPORTANT: This endpoint is SSE, not JSON, so we opt out of JSON headers.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/api/core/bootstrap.php';
require_once __DIR__ . '/utils.php';

// If bootstrap handled an OPTIONS preflight, it may have already exited.
// (bootstrap.php does this now)

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
// CORS: bootstrap may already set these, but re-sending is harmless.
// Keeping this preserves existing behavior.
header('Access-Control-Allow-Origin: *');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Helps proxies (nginx) not buffer SSE (harmless if unused)
header('X-Accel-Buffering: no');

// Make sure output buffering doesn't prevent streaming
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

// For long-running connections
@set_time_limit(0);
@ignore_user_abort(true);

// Optional debug logs (existing behavior)
// Note: keep logs small; no PII / no token contents.
log_msg("SSE REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? ''));
log_msg("SSE QUERY_STRING=" . ($_SERVER['QUERY_STRING'] ?? ''));

// Avoid dumping full $_GET (it includes the session token). Log keys only.
log_msg("SSE _GET keys=" . json_encode(array_keys($_GET ?? [])));

// -------------------------
// Validate token
// -------------------------
if (!$session_token) {
  echo "event: error\n";
  echo 'data: ' . json_encode(['error' => 'Missing session token'], JSON_UNESCAPED_SLASHES) . "\n\n";
  @ob_flush(); @flush();
  exit;
}

$path          = session_file_path($session_token);
$last_mtime    = 0;
$lastKeepalive = time();

// Optional: initial hello event (does not affect clients that only listen for "update")
echo ": connected\n\n";
@ob_flush(); @flush();

while (true) {
  if (connection_aborted()) break;

  clearstatcache(true, $path);

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

  sleep(1);
}
