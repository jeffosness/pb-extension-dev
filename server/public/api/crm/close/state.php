<?php
// server/public/api/crm/close/state.php
//
// Returns connection readiness for this browser client_id:
// - PhoneBurner PAT present?
// - Close OAuth tokens present?
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

$pbPat       = load_pb_token($client_id);
$closeTokens = load_close_tokens($client_id);

api_log('close_state.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ready'       => (bool)$pbPat,
    'close_ready'    => (bool)$closeTokens,
]);

api_ok_flat([
    'client_id' => $client_id,

    'pb_ready'    => (bool)$pbPat,
    'close_ready' => (bool)$closeTokens,

    'phoneburner' => [
        'connected' => (bool)$pbPat,
    ],
    'close' => [
        'connected'       => (bool)$closeTokens,
        'expires_at'      => is_array($closeTokens) ? ($closeTokens['expires_at'] ?? null) : null,
        'organization_id' => is_array($closeTokens) ? ($closeTokens['organization_id'] ?? null) : null,
    ],
]);
