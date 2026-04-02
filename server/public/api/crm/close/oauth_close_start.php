<?php
// server/public/api/crm/close/oauth_close_start.php
//
// Builds the Close OAuth authorization URL for the extension.
// The extension will open auth_url in a new tab/window.
// We pass the extension client_id in the OAuth "state" parameter so we can
// associate the callback tokens with the correct browser instance.
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

// Must match the actual finish endpoint path
$redirect = rtrim($baseUrl, '/') . '/api/crm/close/oauth_close_finish.php';

$params = [
    'client_id'     => $closeClientId,
    'redirect_uri'  => $redirect,
    'state'         => $client_id, // carries extension client_id through OAuth
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
