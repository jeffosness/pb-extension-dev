<?php
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// For now we only support disconnecting HubSpot tokens for this client_id.
$provider = $data['provider'] ?? 'hs';

if ($provider === 'hs') {
    clear_hs_tokens($client_id);
    json_response(['ok' => true, 'provider' => 'hs']);
} else {
    json_response(['ok' => false, 'error' => 'Unsupported provider'], 400);
}
