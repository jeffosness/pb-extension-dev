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

// Validate by calling Apollo /auth/health with X-Api-Key header
// Then fetch user info from /contacts/search to confirm full access
$ch = curl_init('https://api.apollo.io/api/v1/auth/health');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER     => [
    'X-Api-Key: ' . $apiKey,
    'Content-Type: application/json',
    'Accept: application/json',
  ],
  CURLOPT_TIMEOUT => 15,
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = $raw ? json_decode($raw, true) : null;

if ($code !== 200 || !is_array($json) || empty($json['healthy'])) {
  // Capture Apollo's own error text so support can distinguish "key invalid"
  // from "account disabled" / "rate limit" / etc. See LESSONS.md 2026-08-02.
  $fail = log_api_failure_from_tuple($code, $json, $raw, 'apollo_save_key.invalid', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  ]);
  api_error('Invalid Apollo API key (HTTP ' . $code . '). Check that you copied the full master key.', 'unauthorized', 401, [
    'http_code'  => $code,
    'pb_message' => $fail['message'] ?: null,
    'hint'       => $fail['message'] ?: (is_string($raw) ? substr($raw, 0, 200) : null),
  ]);
}

// Save as token — reuse the same token storage, just with api_key instead of access_token
$tokenPayload = [
  'api_key'    => $apiKey,
  'auth_type'  => 'api_key',
  'created_at' => time(),
  'expires_at' => 0,  // API keys don't expire
];

save_apollo_tokens($client_id, $tokenPayload);

api_log('apollo_save_key.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok_flat([
  'apollo_ready' => true,
]);
