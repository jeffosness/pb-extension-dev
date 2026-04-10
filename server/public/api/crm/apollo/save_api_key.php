<?php
// server/public/api/crm/apollo/save_api_key.php
//
// Validates and saves an Apollo API key (master key) for this client_id.
// Similar to PhoneBurner PAT save flow.
//
// Accepts: { client_id, api_key }
// Returns: { ok: true, apollo_ready: true }

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

$apiKey = trim((string)($data['api_key'] ?? ''));
if ($apiKey === '') {
  api_error('No API key provided', 'bad_request', 400);
}

// Validate by calling Apollo /users/me with the API key
// Apollo uses Authorization: Bearer for API keys (same as OAuth)
$ch = curl_init('https://api.apollo.io/api/v1/users/me');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER     => [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
    'Accept: application/json',
    'Cache-Control: no-cache',
  ],
  CURLOPT_TIMEOUT => 15,
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = $raw ? json_decode($raw, true) : null;

if ($code !== 200 || !is_array($json)) {
  api_log('apollo_save_key.invalid', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'http_code'      => $code,
    'response'       => is_string($raw) ? substr($raw, 0, 300) : null,
  ]);
  api_error('Invalid Apollo API key (HTTP ' . $code . '). Check that you copied the full master key.', 'unauthorized', 401, [
    'http_code' => $code,
    'hint'      => is_string($raw) ? substr($raw, 0, 200) : null,
  ]);
}

// Save as token — reuse the same token storage, just with api_key instead of access_token
$tokenPayload = [
  'api_key'    => $apiKey,
  'auth_type'  => 'api_key',
  'created_at' => time(),
  'expires_at' => 0,  // API keys don't expire
  'user_name'  => trim((string)($json['first_name'] ?? '') . ' ' . (string)($json['last_name'] ?? '')),
  'user_email' => (string)($json['email'] ?? ''),
  'team_id'    => (string)($json['team_id'] ?? ''),
];

save_apollo_tokens($client_id, $tokenPayload);

api_log('apollo_save_key.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok_flat([
  'apollo_ready' => true,
  'user_name'    => $tokenPayload['user_name'],
  'user_email'   => $tokenPayload['user_email'],
]);
