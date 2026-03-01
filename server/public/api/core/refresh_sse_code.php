<?php
// server/public/api/core/refresh_sse_code.php
//
// Generates a fresh single-use temp code for SSE reconnection.
// The extension calls this when its SSE connection drops and it needs
// a new ?code= parameter to reconnect to sse.php.
//
// Request:  POST { "client_id": "...", "session_token": "..." }
// Response: { "ok": true, "temp_code": "..." }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

// Validate session_token
$session_token = trim((string)($data['session_token'] ?? ''));
if ($session_token === '') {
    api_error('session_token is required', 'bad_request', 400);
}

// Sanitize: session tokens are 32-char hex strings
$session_token = preg_replace('/[^a-fA-F0-9]/', '', $session_token);
if ($session_token === '') {
    api_error('Invalid session_token format', 'bad_request', 400);
}

// Load session and verify it exists
$state = load_session_state($session_token);
if (!is_array($state)) {
    api_error('Session not found', 'not_found', 404);
}

// Verify the session belongs to this client
if (($state['client_id'] ?? '') !== $client_id) {
    api_error('Session does not belong to this client', 'forbidden', 403);
}

// Generate a new temp code (5-minute TTL, single-use)
$tempCode = temp_code_store($session_token, 300);

api_log('refresh_sse_code.ok', [
    'client_id_hash'   => substr(hash('sha256', (string)$client_id), 0, 12),
    'session_hash'     => substr(hash('sha256', $session_token), 0, 12),
]);

api_ok_flat(['temp_code' => $tempCode]);
