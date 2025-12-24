<?php
// server/public/api/crm/hubspot/oauth_hs_finish.php
//
// HubSpot OAuth callback page.
// HubSpot redirects the user here with ?code=...&state=...
// - state is the extension client_id (set in oauth_hs_start.php)
// - exchange code -> tokens via HubSpot token endpoint
// - save tokens under TOKENS_DIR/hubspot/<client_id>.json
//
// IMPORTANT:
// - This is an HTML page (not a JSON endpoint).
// - Do NOT log code or token payloads.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$cfg = cfg();

// HubSpot sends ?code=...&state=... back here.
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

// Security / caching headers for an OAuth callback page
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function hs_error_page(string $title, string $message, int $status = 400): void {
    http_response_code($status);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title></head><body style="font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;">'
        . '<h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
        . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="javascript:window.close()">Close this tab</a></p>'
        . '</body></html>';
    exit;
}

if (!$code || !$state) {
    api_log('hubspot_oauth_finish.reject.missing_params', [
        'has_code'  => (bool)$code,
        'has_state' => (bool)$state,
    ]);
    hs_error_page('HubSpot OAuth error', 'Missing code or state in callback.', 400);
}

// state is the extension client_id we set in oauth_hs_start.php
$client_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$state);

// sanity check length (UUID is ~36 chars; allow some room but block nonsense)
if ($client_id === '' || strlen($client_id) > 128) {
    api_log('hubspot_oauth_finish.reject.bad_state', [
        'state_len' => is_string($state) ? strlen($state) : null,
    ]);
    hs_error_page('HubSpot OAuth error', 'Invalid state parameter.', 400);
}

$hsClientId     = $cfg['HS_CLIENT_ID'] ?? null;
$hsClientSecret = $cfg['HS_CLIENT_SECRET'] ?? null;
$baseUrl        = $cfg['BASE_URL'] ?? null;

if (!$hsClientId || !$hsClientSecret || !$baseUrl) {
    api_log('hubspot_oauth_finish.error.misconfigured', [
        'client_id_hash'    => substr(hash('sha256', (string)$client_id), 0, 12),
        'has_hs_client_id'  => (bool)$hsClientId,
        'has_hs_secret'     => (bool)$hsClientSecret,
        'has_base_url'      => (bool)$baseUrl,
    ]);
    hs_error_page('HubSpot OAuth error', 'Server is missing HubSpot OAuth configuration.', 500);
}

// Must match oauth_hs_start.php redirect_uri exactly
$redirect = rtrim($baseUrl, '/') . '/api/crm/hubspot/oauth_hs_finish.php';

// Exchange code → tokens (timed)
$t0 = microtime(true);
list($status, $resp) = http_post_form(
    'https://api.hubapi.com/oauth/v1/token',
    [
        'grant_type'    => 'authorization_code',
        'client_id'     => $hsClientId,
        'client_secret' => $hsClientSecret,
        'redirect_uri'  => $redirect,
        'code'          => $code,
    ]
);
$hs_ms = (int) round((microtime(true) - $t0) * 1000);

if ($status < 200 || $status >= 300 || !is_array($resp)) {
    api_log('hubspot_oauth_finish.error.token_exchange_failed', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'status'         => (int)$status,
        'hs_ms'          => $hs_ms,
        // Do NOT log $resp (could contain sensitive info)
    ]);
    hs_error_page(
        'HubSpot OAuth error',
        'Could not exchange code for tokens. Please close this tab and try reconnecting.',
        500
    );
}

// Normalize token shape & store with soft expiry
$now        = time();
$expires_in = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 1800;

// Ensure we preserve common fields but don't assume they exist
$tokenPayload = $resp;
$tokenPayload['created_at'] = $now;
$tokenPayload['expires_at'] = $now + max(0, $expires_in - 60); // refresh slightly early

// Save under TOKENS_DIR/hubspot/<client_id>.json
save_hs_tokens($client_id, $tokenPayload);

api_log('hubspot_oauth_finish.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'hub_id'         => $tokenPayload['hub_id'] ?? null,
    'hs_ms'          => $hs_ms,
]);

// Success page
echo '<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>HubSpot connected</title>
  </head>
  <body style="font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;">
    <h3>HubSpot is connected.</h3>
    <p>You can close this tab and return to the extension popup.</p>
    <p><a href="javascript:window.close()">Close this tab</a></p>
  </body>
</html>';
