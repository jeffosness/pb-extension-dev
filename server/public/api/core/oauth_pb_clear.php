<?php
// server/public/api/core/oauth_pb_clear.php
//
// Clears the stored PhoneBurner PAT for this browser client_id.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// Remove the stored PAT (if any) for this client_id
clear_pb_token($client_id);

api_log('oauth_pb_clear.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok([]);
