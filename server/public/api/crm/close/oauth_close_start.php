<?php
// server/public/api/crm/close/oauth_close_start.php
//
// Builds the Close OAuth authorization URL for the extension.
// The extension will open auth_url in a new tab/window.
// The OAuth "state" is a one-time code that maps to the extension client_id
// server-side (oauth_state_create in utils.php). It is never the client_id
// itself. See utils.php "OAuth state codes".
//
// Close OAuth docs: https://developer.close.com/topics/authentication-oauth2/
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$cfg  = cfg();
$data = json_input();

$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

$closeClientId = $cfg['CLOSE_CLIENT_ID'] ?? null;
$baseUrl       = $cfg['BASE_URL'] ?? null;

if (!$closeClientId || !$baseUrl) {
    api_log('close_oauth_start.error.misconfigured', [
        'has_close_client_id' => (bool)$closeClientId,
        'has_base_url'        => (bool)$baseUrl,
    ]);
    api_error('Close OAuth is not configured on the server', 'server_misconfig', 500);
}

try {
    $oauthState = oauth_state_create($client_id, 'close');
} catch (\InvalidArgumentException $e) {
    api_log('close_oauth_start.reject.bad_client_id', []);
    api_error('Invalid client_id', 'bad_request', 400);
} catch (\Throwable $e) {
    api_log('close_oauth_start.error.state_store', ['exception' => get_class($e)]);
    api_error('Unable to start Close connect. Please try again.', 'server_error', 500);
}

// Must match the actual finish endpoint path
$redirect = rtrim($baseUrl, '/') . '/api/crm/close/oauth_close_finish.php';

$params = [
    'client_id'     => $closeClientId,
    'redirect_uri'  => $redirect,
    'state'         => $oauthState, // one-time code, NOT the client_id
    'response_type' => 'code',
];

$url = 'https://app.close.com/oauth2/authorize/?' . http_build_query($params);

api_log('close_oauth_start.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'redirect_host'  => parse_url($redirect, PHP_URL_HOST),
]);

api_ok_flat([
    'auth_url' => $url,
]);
