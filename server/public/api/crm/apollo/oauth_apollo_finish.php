<?php
// server/public/api/crm/apollo/oauth_apollo_finish.php
//
// Apollo OAuth callback page.
// Apollo redirects the user here with ?code=...&state=...
// - state is the extension client_id (set in oauth_apollo_start.php)
// - exchange code -> tokens via Apollo token endpoint
// - save tokens under TOKENS_DIR/apollo/<client_id>.json
//
// IMPORTANT:
// - This is an HTML page (not a JSON endpoint).
// - Do NOT log code or token payloads.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$cfg = cfg();

// Apollo sends ?code=...&state=... back here.
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

// Security / caching headers for an OAuth callback page
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

function apollo_error_page(string $title, string $message, int $status = 400): void {
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
    api_log('apollo_oauth_finish.reject.missing_params', [
        'has_code'  => (bool)$code,
        'has_state' => (bool)$state,
    ]);
    apollo_error_page('Apollo OAuth error', 'Missing code or state in callback.', 400);
}

// state is the extension client_id we set in oauth_apollo_start.php
$client_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$state);

if ($client_id === '' || strlen($client_id) > 128) {
    api_log('apollo_oauth_finish.reject.bad_state', [
        'state_len' => is_string($state) ? strlen($state) : null,
    ]);
    apollo_error_page('Apollo OAuth error', 'Invalid state parameter.', 400);
}

$apolloClientId     = $cfg['APOLLO_CLIENT_ID'] ?? null;
$apolloClientSecret = $cfg['APOLLO_CLIENT_SECRET'] ?? null;
$baseUrl            = $cfg['BASE_URL'] ?? null;

if (!$apolloClientId || !$apolloClientSecret || !$baseUrl) {
    api_log('apollo_oauth_finish.error.misconfigured', [
        'client_id_hash'       => substr(hash('sha256', (string)$client_id), 0, 12),
        'has_apollo_client_id' => (bool)$apolloClientId,
        'has_apollo_secret'    => (bool)$apolloClientSecret,
        'has_base_url'         => (bool)$baseUrl,
    ]);
    apollo_error_page('Apollo OAuth error', 'Server is missing Apollo OAuth configuration.', 500);
}

// Must match oauth_apollo_start.php redirect_uri exactly
$redirect = rtrim($baseUrl, '/') . '/api/crm/apollo/oauth_apollo_finish.php';

// Exchange code -> tokens (timed)
$t0 = microtime(true);
list($status, $resp) = http_post_form(
    'https://app.apollo.io/api/v1/oauth/token',
    [
        'grant_type'    => 'authorization_code',
        'client_id'     => $apolloClientId,
        'client_secret' => $apolloClientSecret,
        'redirect_uri'  => $redirect,
        'code'          => $code,
    ]
);
$ms = (int) round((microtime(true) - $t0) * 1000);

if ($status < 200 || $status >= 300 || !is_array($resp)) {
    api_log('apollo_oauth_finish.error.token_exchange_failed', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'status'         => (int)$status,
        'ms'             => $ms,
    ]);
    apollo_error_page(
        'Apollo OAuth error',
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

// Save under TOKENS_DIR/apollo/<client_id>.json
save_apollo_tokens($client_id, $tokenPayload);

api_log('apollo_oauth_finish.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'ms'             => $ms,
]);

// Success page — attempt auto-close, fallback to message
echo '<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Apollo connected</title>
  </head>
  <body style="font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;">
    <h3>Apollo is connected.</h3>
    <p>You can close this tab and return to the extension popup.</p>
    <script>
      // Attempt to close — only works if tab was opened by JS (window.open / chrome.tabs.create)
      try { window.close(); } catch(e) {}
    </script>
  </body>
</html>';
