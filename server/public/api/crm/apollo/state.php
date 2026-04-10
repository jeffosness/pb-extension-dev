<?php
// server/public/api/crm/apollo/state.php
//
// Returns connection readiness for this browser client_id:
// - PhoneBurner PAT present?
// - Apollo OAuth tokens present?
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

$pbPat        = load_pb_token($client_id);
$apolloTokens = load_apollo_tokens($client_id);

api_log('apollo_state.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ready'       => (bool)$pbPat,
    'apollo_ready'   => (bool)$apolloTokens,
]);

api_ok_flat([
    'client_id' => $client_id,

    'pb_ready'     => (bool)$pbPat,
    'apollo_ready' => (bool)$apolloTokens,

    'phoneburner' => [
        'connected' => (bool)$pbPat,
    ],
    'apollo' => [
        'connected'  => (bool)$apolloTokens,
        'expires_at' => is_array($apolloTokens) ? ($apolloTokens['expires_at'] ?? null) : null,
    ],
]);
