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

// Validate by calling Apollo API with the API key
// Try multiple auth methods — Apollo docs are inconsistent
$authMethods = [
  // Method 1: POST with api_key in body (Apollo's traditional pattern)
  ['method' => 'POST', 'url' => 'https://api.apollo.io/api/v1/users/me', 'body' => json_encode(['api_key' => $apiKey]), 'headers' => ['Content-Type: application/json', 'Accept: application/json']],
  // Method 2: GET with api_key as query param
  ['method' => 'GET', 'url' => 'https://api.apollo.io/api/v1/users/me?api_key=' . urlencode($apiKey), 'body' => null, 'headers' => ['Accept: application/json']],
  // Method 3: Bearer auth header
  ['method' => 'GET', 'url' => 'https://api.apollo.io/api/v1/users/me', 'body' => null, 'headers' => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json']],
];

$code = 0;
$json = null;
$raw  = '';
$methodUsed = '';

foreach ($authMethods as $attempt) {
  $ch = curl_init($attempt['url']);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $attempt['headers'],
    CURLOPT_TIMEOUT        => 15,
  ];
  if ($attempt['method'] === 'POST') {
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_POSTFIELDS] = $attempt['body'];
  }
  curl_setopt_array($ch, $opts);
  $raw  = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = $raw ? json_decode($raw, true) : null;
  $methodUsed = $attempt['method'] . ' ' . parse_url($attempt['url'], PHP_URL_PATH);

  // If we got 200, this method works
  if ($code === 200 && is_array($json)) break;
}

if ($code !== 200 || !is_array($json)) {
  api_log('apollo_save_key.invalid', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'http_code'      => $code,
    'response'       => is_string($raw) ? substr($raw, 0, 300) : null,
  ]);
  api_error('Invalid Apollo API key (HTTP ' . $code . '). Check that you copied the full master key.', 'unauthorized', 401, [
    'http_code'    => $code,
    'method_tried' => $methodUsed,
    'hint'         => is_string($raw) ? substr($raw, 0, 300) : null,
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
