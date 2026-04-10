<?php
// server/public/api/crm/apollo/apollo_sequences.php
//
// Returns the user's Apollo sequences for the extension popup dropdown.
// Similar to hs_lists.php for HubSpot.
//
// Accepts: { client_id }
// Returns: { ok: true, sequences: [{ id, name, active_count, status }] }

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/apollo_helpers.php';

// -----------------------------------------------------------------------------
// Input + auth
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

$tokens = load_apollo_tokens($client_id);
if (!is_array($tokens)) {
  api_error('No Apollo tokens saved for this client_id', 'unauthorized', 401);
}

$accessToken = apollo_ensure_access_token($client_id, $tokens);

// -----------------------------------------------------------------------------
// Fetch sequences from Apollo
// POST /v1/emailer_campaigns/search
// -----------------------------------------------------------------------------
$searchBody = [
  'per_page' => 50,
  'sort_by_key' => 'last_used_at',
  'sort_ascending' => false,
];

list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://app.apollo.io/api/v1/emailer_campaigns/search', $searchBody);

// Retry once on 401
if ($code === 401) {
  $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
  $accessToken = (string)($tokens['access_token'] ?? '');
  list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://app.apollo.io/api/v1/emailer_campaigns/search', $searchBody);
}

if ($code !== 200 || !is_array($json)) {
  api_log('apollo_sequences.search_fail', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'http_code'      => $code,
  ]);
  api_error('Failed to fetch Apollo sequences', 'api_error', 502);
}

// Normalize results
$sequences = [];
$campaigns = $json['emailer_campaigns'] ?? $json['data'] ?? [];

if (is_array($campaigns)) {
  foreach ($campaigns as $seq) {
    if (!is_array($seq)) continue;

    $sequences[] = [
      'id'           => (string)($seq['id'] ?? ''),
      'name'         => (string)($seq['name'] ?? ''),
      'active_count' => (int)($seq['active_count'] ?? $seq['num_steps'] ?? 0),
      'status'       => (bool)($seq['active'] ?? false) ? 'active' : 'inactive',
      'created_at'   => (string)($seq['created_at'] ?? ''),
    ];
  }
}

api_log('apollo_sequences.ok', [
  'client_id_hash'  => substr(hash('sha256', (string)$client_id), 0, 12),
  'total_sequences' => count($sequences),
]);

api_ok(['sequences' => $sequences]);
