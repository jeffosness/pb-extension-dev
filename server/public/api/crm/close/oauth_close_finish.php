<?php
// server/public/api/crm/close/oauth_close_finish.php
//
// Close OAuth callback page.
// Close redirects the user here with ?code=...&state=...
// - state is the extension client_id (set in oauth_close_start.php)
// - exchange code -> tokens via Close token endpoint
// - save tokens under TOKENS_DIR/close/<client_id>.json
//
// Close OAuth docs: https://developer.close.com/topics/authentication-oauth2/
//
// IMPORTANT:
// - This is an HTML page (not a JSON endpoint).
// - Do NOT log code or token payloads.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$cfg = cfg();

// Close sends ?code=...&state=... back here.
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

// Security / caching headers for an OAuth callback page
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function close_error_page(string $title, string $message, int $status = 400): void {
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
    api_log('close_oauth_finish.reject.missing_params', [
        'has_code'  => (bool)$code,
        'has_state' => (bool)$state,
    ]);
    close_error_page('Close OAuth error', 'Missing code or state in callback.', 400);
}

// state is the extension client_id we set in oauth_close_start.php
$client_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$state);

if ($client_id === '' || strlen($client_id) > 128) {
    api_log('close_oauth_finish.reject.bad_state', [
        'state_len' => is_string($state) ? strlen($state) : null,
    ]);
    close_error_page('Close OAuth error', 'Invalid state parameter.', 400);
}

$closeClientId     = $cfg['CLOSE_CLIENT_ID'] ?? null;
$closeClientSecret = $cfg['CLOSE_CLIENT_SECRET'] ?? null;
$baseUrl           = $cfg['BASE_URL'] ?? null;

if (!$closeClientId || !$closeClientSecret || !$baseUrl) {
    api_log('close_oauth_finish.error.misconfigured', [
        'client_id_hash'      => substr(hash('sha256', (string)$client_id), 0, 12),
        'has_close_client_id' => (bool)$closeClientId,
        'has_close_secret'    => (bool)$closeClientSecret,
        'has_base_url'        => (bool)$baseUrl,
    ]);
    close_error_page('Close OAuth error', 'Server is missing Close OAuth configuration.', 500);
}

// Must match oauth_close_start.php redirect_uri exactly
$redirect = rtrim($baseUrl, '/') . '/api/crm/close/oauth_close_finish.php';

// Exchange code -> tokens (timed)
$t0 = microtime(true);
list($status, $resp) = http_post_form(
    'https://api.close.com/oauth2/token/',
    [
        'grant_type'    => 'authorization_code',
        'client_id'     => $closeClientId,
        'client_secret' => $closeClientSecret,
        'redirect_uri'  => $redirect,
        'code'          => $code,
    ]
);
$ms = (int) round((microtime(true) - $t0) * 1000);

if ($status < 200 || $status >= 300 || !is_array($resp)) {
    api_log('close_oauth_finish.error.token_exchange_failed', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'status'         => (int)$status,
        'ms'             => $ms,
    ]);
    close_error_page(
        'Close OAuth error',
        'Could not exchange code for tokens. Please close this tab and try reconnecting.',
        500
    );
}

// Normalize token shape & store with soft expiry
$now        = time();
$expires_in = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 3600;

$tokenPayload = $resp;
$tokenPayload['created_at'] = $now;
$tokenPayload['expires_at'] = $now + max(0, $expires_in - 60); // refresh slightly early

// Save under TOKENS_DIR/close/<client_id>.json
save_close_tokens($client_id, $tokenPayload);

api_log('close_oauth_finish.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'organization_id' => $tokenPayload['organization_id'] ?? null,
    'ms'             => $ms,
]);

// Success page
echo '<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Close connected</title>
  </head>
  <body style="font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;">
    <h3>Close is connected.</h3>
    <p>You can close this tab and return to the extension popup.</p>
    <p><a href="javascript:window.close()">Close this tab</a></p>
  </body>
</html>';
