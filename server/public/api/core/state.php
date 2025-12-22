<?php
// generic_crm/api/state.php
require_once __DIR__ . '/../../utils.php';

$data = json_input();
$client_id = get_client_id_or_fail($data);

$pat = load_pb_token($client_id);
$pb_connected = !empty($pat);

json_response([
    'ok' => true,
    'phoneburner' => [
        'connected' => $pb_connected,
    ],
]);
