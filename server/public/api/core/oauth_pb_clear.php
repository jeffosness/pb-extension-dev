<?php
// generic_crm/api/oauth_pb_clear.php
require_once __DIR__ . '/../../utils.php';

$data = json_input();
$client_id = get_client_id_or_fail($data);

// Remove the stored PAT (if any) for this client_id
clear_pb_token($client_id);

json_response(['ok' => true]);
