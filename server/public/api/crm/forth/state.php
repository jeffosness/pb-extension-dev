<?php
// server/public/api/crm/forth/state.php
//
// Returns connection readiness for this browser client_id:
// - PhoneBurner PAT present?
// - Forth credentials present?
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

$pbPat       = load_pb_token($client_id);
$forthTokens = load_forth_tokens($client_id);

// "Connected" = we hold the durable credential pair. The api_key is a derived
// cache we can always re-mint, so its presence/expiry doesn't gate readiness.
$connected = is_array($forthTokens)
  && !empty($forthTokens['client_id'])
  && !empty($forthTokens['client_secret']);

api_log('forth_state.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  'pb_ready'       => (bool)$pbPat,
  'forth_ready'    => $connected,
]);

api_ok_flat([
  'client_id'   => $client_id,
  'pb_ready'    => (bool)$pbPat,
  'forth_ready' => $connected,
  'phoneburner' => [
    'connected' => (bool)$pbPat,
  ],
  'forth' => [
    'connected' => $connected,
  ],
]);
