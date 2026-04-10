<?php
// server/public/api/crm/apollo/apollo_helpers.php
//
// Shared Apollo API helpers used by multiple endpoints:
//   - pb_dialsession_selection.php  (dial from People selection)
//   - pb_dialsession_from_tasks.php (dial from sequence call tasks)
//   - apollo_sequences.php          (list sequences for dropdown)
//   - apollo_sequence_tasks.php     (get open call tasks)
//   - apollo_call_logger.php        (complete tasks + log calls)
//
// Apollo API base: https://app.apollo.io/api/v1/
// Auth: OAuth 2.0 Bearer tokens
// Docs: https://developer.apollo.io/

// -----------------------------------------------------------------------------
// Token refresh
// -----------------------------------------------------------------------------

function apollo_refresh_access_token_or_fail(string $client_id, array $tokens): array {
  $cfg = cfg();
  $apolloClientId     = $cfg['APOLLO_CLIENT_ID'] ?? null;
  $apolloClientSecret = $cfg['APOLLO_CLIENT_SECRET'] ?? null;

  if (!$apolloClientId || !$apolloClientSecret) {
    api_error('Server missing APOLLO_CLIENT_ID/APOLLO_CLIENT_SECRET for token refresh', 'server_error', 500);
  }

  $refresh = $tokens['refresh_token'] ?? '';
  if (!$refresh) {
    api_error('Apollo token expired and no refresh_token is available. Please reconnect Apollo.', 'unauthorized', 401);
  }

  $t0 = microtime(true);
  list($status, $resp) = http_post_form(
    'https://app.apollo.io/api/v1/oauth/token',
    [
      'grant_type'    => 'refresh_token',
      'client_id'     => $apolloClientId,
      'client_secret' => $apolloClientSecret,
      'refresh_token' => $refresh,
    ]
  );
  $ms = (int) round((microtime(true) - $t0) * 1000);

  if ($status < 200 || $status >= 300 || !is_array($resp)) {
    api_log('apollo_refresh.error', [
      'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
      'status' => (int)$status,
      'ms' => $ms,
    ]);
    api_error('Apollo token refresh failed. Please reconnect Apollo.', 'unauthorized', 401);
  }

  // Keep refresh_token if Apollo doesn't return a new one
  if (empty($resp['refresh_token'])) {
    $resp['refresh_token'] = $refresh;
  }

  $now        = time();
  $expires_in = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 3600;
  $resp['created_at'] = $now;
  $resp['expires_at'] = $now + max(0, $expires_in - 60); // refresh 60s early

  save_apollo_tokens($client_id, $resp);

  api_log('apollo_refresh.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'ms' => $ms,
  ]);

  return $resp;
}

function apollo_token_is_expired(array $tokens): bool {
  $exp = isset($tokens['expires_at']) ? (int)$tokens['expires_at'] : 0;
  return $exp > 0 && time() >= $exp;
}

// -----------------------------------------------------------------------------
// Ensure valid access token (refresh if expired, retry on 401)
// -----------------------------------------------------------------------------

/**
 * Load Apollo tokens, refresh if expired, return access token string.
 * Passes $tokens by reference so callers get the refreshed payload.
 */
function apollo_ensure_access_token(string $client_id, array &$tokens): string {
  if (apollo_token_is_expired($tokens)) {
    $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
  }
  $access = (string)($tokens['access_token'] ?? '');
  if ($access === '') {
    api_error('No Apollo access token available', 'unauthorized', 401);
  }
  return $access;
}

// -----------------------------------------------------------------------------
// HTTP helpers
// -----------------------------------------------------------------------------

/**
 * GET request to Apollo API with Bearer auth.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function apollo_api_get_json($accessToken, $url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $accessToken,
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = null;
  if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
  }
  return [$code, $json, $raw];
}

/**
 * POST JSON to Apollo API with Bearer auth.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function apollo_api_post_json($accessToken, $url, array $body) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer ' . $accessToken,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = null;
  if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
  }
  return [$code, $json, $raw];
}

/**
 * PUT JSON to Apollo API with Bearer auth.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function apollo_api_put_json($accessToken, $url, array $body) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer ' . $accessToken,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = null;
  if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
  }
  return [$code, $json, $raw];
}

// -----------------------------------------------------------------------------
// User identity
// -----------------------------------------------------------------------------

/**
 * Fetch the authenticated user's identity from Apollo.
 * Used to verify token validity.
 */
function apollo_get_me($accessToken) {
  return apollo_api_get_json($accessToken, 'https://app.apollo.io/api/v1/users/me');
}

// -----------------------------------------------------------------------------
// Contact fetching
// -----------------------------------------------------------------------------

/**
 * Fetch contacts by their Apollo contact IDs using the search endpoint.
 * Uses POST /contacts/search with contact_ids filter (works with OAuth contacts_search scope).
 * GET /contacts/{id} requires a different scope that OAuth tokens may not have.
 *
 * Returns normalized array for PhoneBurner dial session creation.
 */
function apollo_fetch_contacts_by_ids($accessToken, array $contactIds, &$diag = []) {
  $contacts = [];
  $diag['contacts_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null, 'last_error' => null];

  if (empty($contactIds)) return $contacts;

  // Batch in groups of 100
  $batches = array_chunk($contactIds, 100);

  foreach ($batches as $batch) {
    // Try multiple endpoints — Apollo OAuth may restrict some
    $endpoints = [
      'https://app.apollo.io/api/v1/mixed_people/search',
      'https://app.apollo.io/api/v1/contacts/search',
      'https://app.apollo.io/api/v1/people/search',
    ];

    $code = 0;
    $json = null;
    $raw = null;

    foreach ($endpoints as $endpoint) {
      $searchBody = [
        'contact_ids' => array_values($batch),
        'per_page' => count($batch),
      ];

      list($code, $json, $raw) = apollo_api_post_json(
        $accessToken,
        $endpoint,
        $searchBody
      );

      // If not 403, this endpoint works (or gave a real error)
      if ($code !== 403) break;
    }
    $diag['contacts_fetch']['last_http'] = $code;
    $diag['contacts_fetch']['endpoint_used'] = $endpoint ?? 'none';

    if ($code !== 200 || !is_array($json)) {
      $diag['contacts_fetch']['fail'] += count($batch);
      $diag['contacts_fetch']['last_error'] = is_string($raw) ? substr($raw, 0, 500) : null;
      $diag['contacts_fetch']['all_403'] = ($code === 403);
      continue;
    }

    // Apollo search returns contacts in a 'contacts' array
    $results = $json['contacts'] ?? $json['people'] ?? $json['data'] ?? [];
    if (!is_array($results)) {
      // Maybe the response is flat (array of contacts at top level)
      $results = isset($json[0]) ? $json : [];
    }

    foreach ($results as $c) {
      if (!is_array($c) || empty($c['id'])) {
        $diag['contacts_fetch']['fail']++;
        continue;
      }
      $contacts[] = apollo_normalize_contact($c);
      $diag['contacts_fetch']['ok']++;
    }

    $diag['contacts_fetch']['fail'] += count($batch) - count($results);
  }

  return $contacts;
}

/**
 * Fetch contacts with automatic token refresh retry on 401.
 */
function apollo_fetch_contacts_with_refresh_retry(string $client_id, array &$tokens, string &$accessToken, array $contactIds, array &$diag = []) {
  $contacts = apollo_fetch_contacts_by_ids($accessToken, $contactIds, $diag);

  $lastHttp = $diag['contacts_fetch']['last_http'] ?? null;
  if (empty($contacts) && $lastHttp === 401) {
    $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
    $accessToken = (string)($tokens['access_token'] ?? '');
    $contacts = apollo_fetch_contacts_by_ids($accessToken, $contactIds, $diag);
  }

  return $contacts;
}

// -----------------------------------------------------------------------------
// Contact normalization
// -----------------------------------------------------------------------------

/**
 * Normalize an Apollo contact object to our standard format.
 * Apollo phone priority: direct_phone > mobile_phone > corporate_phone > phone_numbers[0]
 */
function apollo_normalize_contact(array $c): array {
  $firstName = trim((string)($c['first_name'] ?? ''));
  $lastName  = trim((string)($c['last_name'] ?? ''));

  // Phone priority: direct > mobile > corporate > phone_numbers array
  $phone = trim((string)($c['direct_phone'] ?? ''));
  if ($phone === '') $phone = trim((string)($c['mobile_phone'] ?? ''));
  if ($phone === '') $phone = trim((string)($c['corporate_phone'] ?? ''));
  if ($phone === '' && !empty($c['phone_numbers']) && is_array($c['phone_numbers'])) {
    foreach ($c['phone_numbers'] as $pn) {
      $num = trim((string)($pn['sanitized_number'] ?? $pn['raw_number'] ?? ''));
      if ($num !== '') { $phone = $num; break; }
    }
  }

  // Additional phones beyond the primary
  $additionalPhones = [];
  $allPhones = [];
  if (!empty($c['direct_phone']))    $allPhones[] = ['number' => $c['direct_phone'],    'type' => 'direct'];
  if (!empty($c['mobile_phone']))    $allPhones[] = ['number' => $c['mobile_phone'],    'type' => 'mobile'];
  if (!empty($c['corporate_phone'])) $allPhones[] = ['number' => $c['corporate_phone'], 'type' => 'work'];
  if (!empty($c['phone_numbers']) && is_array($c['phone_numbers'])) {
    foreach ($c['phone_numbers'] as $pn) {
      $num = trim((string)($pn['sanitized_number'] ?? $pn['raw_number'] ?? ''));
      if ($num !== '') $allPhones[] = ['number' => $num, 'type' => (string)($pn['type'] ?? 'other')];
    }
  }
  // Dedupe and skip primary
  $seen = [$phone => true];
  foreach ($allPhones as $ap) {
    $num = trim((string)($ap['number'] ?? ''));
    if ($num === '' || isset($seen[$num])) continue;
    $seen[$num] = true;
    $type = strtolower(trim((string)($ap['type'] ?? '')));
    $phoneType = '2'; // default Work
    if ($type === 'mobile') $phoneType = '3';
    elseif ($type === 'home') $phoneType = '1';
    $additionalPhones[] = [
      'number'      => $num,
      'phone_type'  => $phoneType,
      'phone_label' => ucfirst($type ?: 'Phone'),
    ];
  }

  $email = trim((string)($c['email'] ?? ''));

  return [
    'apollo_id'         => (string)($c['id'] ?? ''),
    'first_name'        => $firstName,
    'last_name'         => $lastName,
    'email'             => $email,
    'phone'             => $phone,
    'additional_phones' => $additionalPhones,
    'title'             => trim((string)($c['title'] ?? '')),
    'organization_name' => trim((string)($c['organization_name'] ?? '')),
  ];
}
