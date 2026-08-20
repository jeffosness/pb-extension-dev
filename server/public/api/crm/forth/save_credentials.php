<?php
// server/public/api/crm/forth/save_credentials.php
//
// Validates and saves a Forth API credential pair (client_id = Key ID,
// client_secret = Secret) for this extension client_id. Analogous to Apollo's
// save_api_key.php, but Forth uses TWO values which we exchange for a 10-day
// api_key at POST /auth/token.
//
// Accepts: { client_id, forth_client_id, forth_client_secret }
// Returns: { ok: true, forth_ready: true }
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/forth_helpers.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

$forthClientId     = trim((string)($data['forth_client_id'] ?? ''));
$forthClientSecret = trim((string)($data['forth_client_secret'] ?? ''));

if ($forthClientId === '' || $forthClientSecret === '') {
  api_error('Both Forth Key ID and Secret are required', 'bad_request', 400);
}

// Validate AND persist in one step: forth_mint_access_token_or_fail exchanges
// the pair at /auth/token, and on success saves {client_id, client_secret,
// api_key, expires_at} via save_forth_tokens. On bad creds it api_error()s
// (401) with Forth's own error text — nothing is persisted.
$tokens = [
  'client_id'     => $forthClientId,
  'client_secret' => $forthClientSecret,
  'created_at'    => time(),
];
$tokens = forth_mint_access_token_or_fail($client_id, $tokens);

api_log('forth_save_credentials.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok_flat([
  'forth_ready' => true,
]);
