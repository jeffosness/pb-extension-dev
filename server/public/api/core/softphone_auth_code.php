<?php
// server/public/api/core/softphone_auth_code.php
//
// Mints a single-use, short-TTL code that the hosted softphone page
// (softphone.php) exchanges — server-side — for the user's PhoneBurner bearer
// token. The token itself is NEVER returned to the browser or placed in a URL
// the extension controls; only this opaque single-use code travels in the open.
// (CLAUDE.md golden rule: no credentials in URLs — use temp codes.)
//
// The code stores the client_id (not the PAT), so the PAT never lands in the
// temp-code cache either — softphone.php resolves it from TOKENS_DIR.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

$pat = load_pb_token($client_id);
if (empty($pat)) {
    api_error('No PhoneBurner connection for this client', 'not_connected', 400);
}

// Single-use, 5-minute code → client_id. softphone.php exchanges it once.
$code = temp_code_store($client_id, 300);

api_log('softphone_auth_code.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok_flat(['code' => $code]);
