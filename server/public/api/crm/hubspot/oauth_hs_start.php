<?php
// server/public/api/crm/hubspot/oauth_hs_start.php
//
// Builds the HubSpot OAuth authorization URL for the extension.
// The extension will open auth_url in a new tab/window.
// The OAuth "state" is a one-time code that maps to the extension client_id
// server-side (oauth_state_create in utils.php). It is never the client_id
// itself. See utils.php "OAuth state codes".
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$cfg  = cfg();
$data = json_input();

$client_id = get_client_id_or_fail($data);
// Each call now writes a state file, so cap it like the Close/Apollo starts.
rate_limit_or_fail($client_id, 30);

$hsClientId = $cfg['HS_CLIENT_ID'] ?? null;
$hsScopes   = $cfg['HS_SCOPES'] ?? null;
$baseUrl    = $cfg['BASE_URL'] ?? null;

if (!$hsClientId || !$hsScopes || !$baseUrl) {
    api_log('hubspot_oauth_start.error.misconfigured', [
        'has_hs_client_id' => (bool)$hsClientId,
        'has_hs_scopes'    => (bool)$hsScopes,
        'has_base_url'     => (bool)$baseUrl,
    ]);
    api_error('HubSpot OAuth is not configured on the server', 'server_misconfig', 500);
}

try {
    $oauthState = oauth_state_create($client_id, 'hubspot');
} catch (\InvalidArgumentException $e) {
    api_log('hubspot_oauth_start.reject.bad_client_id', []);
    api_error('Invalid client_id', 'bad_request', 400);
} catch (\Throwable $e) {
    api_log('hubspot_oauth_start.error.state_store', ['exception' => get_class($e)]);
    api_error('Unable to start HubSpot connect. Please try again.', 'server_error', 500);
}

// Must match the actual finish endpoint path
$redirect = rtrim($baseUrl, '/') . '/api/crm/hubspot/oauth_hs_finish.php';

$params = [
    'client_id'     => $hsClientId,
    'redirect_uri'  => $redirect,
    'scope'         => $hsScopes,
    'state'         => $oauthState, // one-time code, NOT the client_id
    'response_type' => 'code',
];

$url = 'https://app.hubspot.com/oauth/authorize?' . http_build_query($params);

// Log without leaking state/client_id directly
api_log('hubspot_oauth_start.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'redirect_host'  => parse_url($redirect, PHP_URL_HOST),
    'scope_count'    => is_string($hsScopes) ? count(preg_split('/\s+/', trim($hsScopes))) : null,
]);

api_ok_flat([
    'auth_url' => $url,
]);
