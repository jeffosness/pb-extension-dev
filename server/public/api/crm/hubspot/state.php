<?php
// server/public/api/crm/hubspot/state.php
//
// Returns connection readiness for this browser client_id:
// - PhoneBurner PAT present?
// - HubSpot OAuth tokens present?
//
// IMPORTANT: This endpoint is consumed by the extension, so it must return
// FLAT keys (pb_ready, hs_ready, etc). We use api_ok_flat() to keep the
// legacy/extension-friendly response shape.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

$pbPat    = load_pb_token($client_id);
$hsTokens = load_hs_tokens($client_id);

api_log('hubspot_state.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ready'       => (bool)$pbPat,
    'hs_ready'       => (bool)$hsTokens,
]);

api_ok_flat([
    'client_id' => $client_id,

    // legacy/top-level flags used by the extension UI
    'pb_ready'  => (bool)$pbPat,
    'hs_ready'  => (bool)$hsTokens,

    // nested structure kept for compatibility
    'phoneburner' => [
        'connected' => (bool)$pbPat,
    ],
    'hubspot' => [
        'connected'  => (bool)$hsTokens,
        'expires_at' => is_array($hsTokens) ? ($hsTokens['expires_at'] ?? null) : null,
        'portal_id'  => is_array($hsTokens) ? ($hsTokens['hub_id'] ?? null)      : null,
    ],
]);
