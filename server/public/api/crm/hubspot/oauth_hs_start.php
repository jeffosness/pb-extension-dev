<?php
require_once __DIR__ . '/../../../utils.php';

$cfg  = cfg();
$data = json_input();

// Use the same client_id that the extension uses for PhoneBurner PATs
$client_id = get_client_id_or_fail($data);

$redirect = rtrim($cfg['BASE_URL'], '/')
          . '/api/crm/hubspot/oauth_hs_finish.php';

$params = [
    'client_id'     => $cfg['HS_CLIENT_ID'],
    'redirect_uri'  => $redirect,
    'scope'         => $cfg['HS_SCOPES'],
    'state'         => $client_id, // carries extension client_id through OAuth
    'response_type' => 'code',
];

$url = 'https://app.hubspot.com/oauth/authorize?' . http_build_query($params);

json_response([
    'ok'       => true,
    'auth_url' => $url,
]);
