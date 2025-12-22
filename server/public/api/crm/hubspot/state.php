<?php
require_once __DIR__ . '/../../../utils.php';

// We track state per extension client_id, same as PhoneBurner PATs.
$data      = json_input();
$client_id = get_client_id_or_fail($data);

$pbPat    = load_pb_token($client_id);
$hsTokens = load_hs_tokens($client_id);

json_response([
    'ok'          => true,
    'client_id'   => $client_id,
    'pb_ready'    => (bool)$pbPat,
    'hs_ready'    => (bool)$hsTokens,
    'phoneburner' => [
        'connected' => (bool)$pbPat,
    ],
    'hubspot'     => [
        'connected'  => (bool)$hsTokens,
        'expires_at' => $hsTokens['expires_at'] ?? null,
        'portal_id'  => $hsTokens['hub_id']    ?? null,
    ],
]);
