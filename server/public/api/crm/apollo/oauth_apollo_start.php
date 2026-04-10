<?php
// server/public/api/crm/apollo/oauth_apollo_start.php
//
// Builds the Apollo OAuth authorization URL for the extension.
// The extension will open auth_url in a new tab/window.
// We pass the extension client_id in the OAuth "state" parameter so we can
// associate the callback tokens with the correct browser instance.
//
// IMPORTANT: Extension-facing endpoint => return FLAT keys. Use api_ok_flat().

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$cfg  = cfg();
$data = json_input();

$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

$apolloClientId = $cfg['APOLLO_CLIENT_ID'] ?? null;
$baseUrl        = $cfg['BASE_URL'] ?? null;

if (!$apolloClientId || !$baseUrl) {
    api_log('apollo_oauth_start.error.misconfigured', [
        'has_apollo_client_id' => (bool)$apolloClientId,
        'has_base_url'         => (bool)$baseUrl,
    ]);
    api_error('Apollo OAuth is not configured on the server', 'server_misconfig', 500);
}

// Must match the actual finish endpoint path
$redirect = rtrim($baseUrl, '/') . '/api/crm/apollo/oauth_apollo_finish.php';

// Request all scopes needed for dial sessions + call logging
$scopes = implode(' ', [
    'read_user_profile',
    'contacts_search', 'contact_read', 'contact_update',
    'emailer_campaigns_search', 'emailer_campaigns_remove_or_stop_contact_ids',
    'tasks_list', 'tasks_create',
]);

// Build URL manually (not http_build_query) to keep redirect_uri unencoded
// Apollo's hash-based SPA may not handle double-encoded URLs correctly
$url = 'https://app.apollo.io/#/oauth/authorize?'
    . 'client_id=' . urlencode($apolloClientId)
    . '&redirect_uri=' . urlencode($redirect)
    . '&state=' . urlencode($client_id)
    . '&response_type=code'
    . '&scope=' . urlencode($scopes);

api_log('apollo_oauth_start.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'redirect_host'  => parse_url($redirect, PHP_URL_HOST),
]);

api_ok_flat([
    'auth_url' => $url,
]);
