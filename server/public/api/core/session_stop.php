<?php
// server/public/api/core/session_stop.php
//
// Explicitly marks a session as stopped when user clicks Stop button.
// Logs session_stopped event and deletes presence file for dashboard tracking.
//
// Uses core/bootstrap.php for shared hardening/CORS/OPTIONS behavior.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

// Rate limit: 10 requests per minute per client_id (shouldn't need more)
$data = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 10);

$session_token = $data['session_token'] ?? null;
if (!$session_token || !is_string($session_token)) {
    api_error('Missing or invalid session_token', 'bad_request', 400);
}

// Verify session exists and belongs to this client
$session_file = session_file_path($session_token);
if (!file_exists($session_file)) {
    api_error('Session not found', 'not_found', 404);
}

$session_state = json_decode(@file_get_contents($session_file), true);
if (!is_array($session_state)) {
    api_error('Invalid session data', 'server_error', 500);
}

// Verify ownership
if (($session_state['client_id'] ?? null) !== $client_id) {
    api_error('Unauthorized', 'forbidden', 403);
}

// Calculate session duration
$created_at = $session_state['created_at'] ?? null;
$duration_sec = 0;
if ($created_at) {
    $created_timestamp = strtotime($created_at);
    if ($created_timestamp !== false) {
        $duration_sec = time() - $created_timestamp;
    }
}

// Hash the session token for logging (12-char hash for privacy)
$session_hash = substr(hash('sha256', $session_token), 0, 12);

// Log session_stopped event to daily usage log
$metrics_dir = __DIR__ . '/../../metrics';
if (!is_dir($metrics_dir)) {
    @mkdir($metrics_dir, 0770, true);
}

$log_file = $metrics_dir . '/sse_usage-' . date('Y-m-d') . '.log';
$log_entry = [
    'event' => 'sse.session_stopped',
    'ts' => date('c'),
    'unix_ts' => time(),
    'session_token_hash' => $session_hash,
    'duration_sec' => $duration_sec,
    'client_id_hash' => substr(hash('sha256', $client_id), 0, 12),
    'reason' => 'user_stop',
];

@file_put_contents(
    $log_file,
    json_encode($log_entry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

// Delete presence file (removes from "active now" count)
$presence_dir = __DIR__ . '/../../metrics/sse_presence';
$presence_file = safe_file_path($presence_dir, $session_hash . '.json');
if ($presence_file && file_exists($presence_file)) {
    @unlink($presence_file);
}

api_log('session_stop', [
    'session_token_hash' => $session_hash,
    'client_id_hash' => substr(hash('sha256', $client_id), 0, 12),
    'duration_sec' => $duration_sec,
]);

api_ok([
    'message' => 'Session stopped successfully',
    'session_token_hash' => $session_hash,
    'duration_sec' => $duration_sec,
]);
