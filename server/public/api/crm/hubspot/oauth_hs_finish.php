<?php
require_once __DIR__ . '/../../../utils.php';

$cfg = cfg();

// HubSpot sends ?code=...&state=... back here.
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

if (!$code || !$state) {
    http_response_code(400);
    echo '<h3>HubSpot OAuth error</h3><p>Missing code or state in callback.</p>';
    exit;
}

// state is the extension client_id we set in oauth_hs_start.php
$client_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $state);

$redirect = rtrim($cfg['BASE_URL'], '/')
          . '/api/crm/hubspot/oauth_hs_finish.php';

// Exchange code → tokens
list($status, $resp) = http_post_form(
    'https://api.hubapi.com/oauth/v1/token',
    [
        'grant_type'    => 'authorization_code',
        'client_id'     => $cfg['HS_CLIENT_ID'],
        'client_secret' => $cfg['HS_CLIENT_SECRET'],
        'redirect_uri'  => $redirect,
        'code'          => $code,
    ]
);

if ($status < 200 || $status >= 300 || !is_array($resp)) {
    log_msg('HubSpot oauth_hs_finish token error: status=' . $status . ' body=' . json_encode($resp));
    http_response_code(500);
    echo '<h3>HubSpot OAuth error</h3><p>Could not exchange code for tokens. Please try reconnecting.</p>';
    exit;
}

// Normalise token shape & store with a soft expiry
$now        = time();
$expires_in = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 1800;

$resp['created_at'] = $now;
$resp['expires_at'] = $now + max(0, $expires_in - 60); // refresh slightly early

save_hs_tokens($client_id, $resp);

// Simple "you can close me" page
echo '<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>HubSpot connected</title>
  </head>
  <body>
    <h3>HubSpot is connected.</h3>
    <p>You can close this tab and return to the extension popup.</p>
  </body>
</html>';
