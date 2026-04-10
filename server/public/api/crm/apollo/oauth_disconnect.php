<?php
// server/public/api/crm/apollo/oauth_disconnect.php
//
// Disconnects Apollo OAuth tokens for this extension client_id.
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

clear_apollo_tokens($client_id);

api_log('apollo_disconnect.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok_flat([
    'provider' => 'apollo',
]);
