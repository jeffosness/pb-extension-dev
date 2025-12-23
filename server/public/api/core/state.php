<?php
// server/public/api/core/state.php
//
// Returns basic connection state for the current browser client_id.
// Currently reports whether a PhoneBurner PAT exists for this client_id.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

$pat          = load_pb_token($client_id);
$pb_connected = !empty($pat);

api_log('state.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_connected'   => $pb_connected,
]);

api_ok([
    'phoneburner' => [
        'connected' => $pb_connected,
    ],
]);
