<?php
// server/public/api/crm/hubspot/oauth_disconnect.php
//
// Disconnects CRM provider tokens for this extension client_id.
// Currently supports: HubSpot ("hs") only.
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// For now we only support disconnecting HubSpot tokens for this client_id.
$provider = isset($data['provider']) ? (string)$data['provider'] : 'hs';

if ($provider !== 'hs') {
    api_log('hubspot_disconnect.reject.unsupported_provider', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'provider'       => $provider,
    ]);
    api_error('Unsupported provider', 'bad_request', 400, ['provider' => $provider]);
}

clear_hs_tokens($client_id);

api_log('hubspot_disconnect.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok_flat([
    'provider' => 'hs',
]);
