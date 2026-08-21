<?php
// server/public/api/crm/forth/oauth_disconnect.php
//
// Disconnects Forth for this extension client_id (clears the stored credential
// pair + cached api_key). Named oauth_disconnect.php for parity with the other
// providers even though Forth is API-Key, not OAuth.
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

clear_forth_tokens($client_id);

api_log('forth_disconnect.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok_flat([
  'provider' => 'forth',
]);
