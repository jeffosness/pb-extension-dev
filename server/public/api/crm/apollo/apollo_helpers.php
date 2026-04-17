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
    'https://api.apollo.io/api/v1/oauth/token',
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
 * Load Apollo tokens, refresh if expired, return access token/key string.
 * Supports both OAuth (access_token) and API key (api_key) auth types.
 * Passes $tokens by reference so callers get the refreshed payload.
 */
function apollo_ensure_access_token(string $client_id, array &$tokens): string {
  if (($tokens['auth_type'] ?? '') === 'api_key') {
    $key = (string)($tokens['api_key'] ?? '');
    if ($key === '') {
      api_error('No Apollo API key available', 'unauthorized', 401);
    }
    return $key;
  }

  if (apollo_token_is_expired($tokens)) {
    $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
  }
  $access = (string)($tokens['access_token'] ?? '');
  if ($access === '') {
    api_error('No Apollo access token available', 'unauthorized', 401);
  }
  return $access;
}

/** Get the auth type from a tokens array. */
function apollo_auth_type(array $tokens): string {
  return ($tokens['auth_type'] ?? '') === 'api_key' ? 'api_key' : 'oauth';
}

// -----------------------------------------------------------------------------
// HTTP helpers
// -----------------------------------------------------------------------------

/**
 * Build auth headers for Apollo API calls.
 * API key uses X-Api-Key, OAuth uses Authorization: Bearer.
 */
function _apollo_auth_headers($tokenOrKey, $authType = 'oauth') {
  if ($authType === 'api_key') {
    return ['X-Api-Key: ' . $tokenOrKey, 'Cache-Control: no-cache'];
  }
  return ['Authorization: Bearer ' . $tokenOrKey, 'Cache-Control: no-cache'];
}

/**
 * GET request to Apollo API.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function apollo_api_get_json($accessToken, $url, $authType = 'oauth') {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array_merge(
      _apollo_auth_headers($accessToken, $authType),
      ['Accept: application/json']
    ),
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
 * POST JSON to Apollo API.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function apollo_api_post_json($accessToken, $url, array $body, $authType = 'oauth') {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => array_merge(
      _apollo_auth_headers($accessToken, $authType),
      ['Content-Type: application/json', 'Accept: application/json']
    ),
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
 * PUT JSON to Apollo API.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function apollo_api_put_json($accessToken, $url, array $body, $authType = 'oauth') {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => array_merge(
      _apollo_auth_headers($accessToken, $authType),
      ['Content-Type: application/json', 'Accept: application/json']
    ),
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
  return apollo_api_get_json($accessToken, 'https://api.apollo.io/api/v1/users/me');
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
function apollo_fetch_contacts_by_ids($accessToken, array $contactIds, &$diag = [], $authType = 'oauth', $preferredPhoneField = '') {
  $contacts = [];
  $diag['contacts_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null, 'last_error' => null];

  if (empty($contactIds)) return $contacts;

  if ($authType === 'api_key') {
    foreach ($contactIds as $cid) {
      $url = 'https://api.apollo.io/api/v1/contacts/' . rawurlencode($cid);
      list($code, $json, $raw) = apollo_api_get_json($accessToken, $url, $authType);
      $diag['contacts_fetch']['last_http'] = $code;

      if ($code !== 200 || !is_array($json)) {
        $diag['contacts_fetch']['fail']++;
        $diag['contacts_fetch']['last_error'] = is_string($raw) ? substr($raw, 0, 500) : null;
        continue;
      }

      $c = isset($json['contact']) && is_array($json['contact']) ? $json['contact'] : $json;
      if (empty($c['id'])) { $diag['contacts_fetch']['fail']++; continue; }

      $contacts[] = apollo_normalize_contact($c, $preferredPhoneField);
      $diag['contacts_fetch']['ok']++;
    }
  } else {
    // OAuth/default: batch via POST /contacts/search
    $batches = array_chunk($contactIds, 100);
    foreach ($batches as $batch) {
      list($code, $json, $raw) = apollo_api_post_json(
        $accessToken,
        'https://api.apollo.io/api/v1/contacts/search',
        ['contact_ids' => array_values($batch), 'per_page' => count($batch)],
        $authType
      );
      $diag['contacts_fetch']['last_http'] = $code;

      if ($code !== 200 || !is_array($json)) {
        $diag['contacts_fetch']['fail'] += count($batch);
        $diag['contacts_fetch']['last_error'] = is_string($raw) ? substr($raw, 0, 500) : null;
        continue;
      }

      $results = $json['contacts'] ?? $json['people'] ?? $json['data'] ?? [];
      foreach ($results as $c) {
        if (!is_array($c) || empty($c['id'])) { $diag['contacts_fetch']['fail']++; continue; }
        $contacts[] = apollo_normalize_contact($c);
        $diag['contacts_fetch']['ok']++;
      }
    }
  }

  return $contacts;
}

/**
 * Fetch contacts with automatic token refresh retry on 401.
 */
function apollo_fetch_contacts_with_refresh_retry(string $client_id, array &$tokens, string &$accessToken, array $contactIds, array &$diag = [], string $preferredPhoneField = '') {
  $authType = apollo_auth_type($tokens);
  $contacts = apollo_fetch_contacts_by_ids($accessToken, $contactIds, $diag, $authType, $preferredPhoneField);

  $lastHttp = $diag['contacts_fetch']['last_http'] ?? null;
  if (empty($contacts) && $lastHttp === 401) {
    $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
    $accessToken = (string)($tokens['access_token'] ?? '');
    $contacts = apollo_fetch_contacts_by_ids($accessToken, $contactIds, $diag, $authType, $preferredPhoneField);
  }

  return $contacts;
}

// -----------------------------------------------------------------------------
// Contact normalization
// -----------------------------------------------------------------------------

/**
 * Normalize an Apollo contact object to our standard format.
 * @param string $preferredPhoneField Optional: 'direct_phone', 'mobile_phone', or 'corporate_phone'
 *        When set, that field is checked first for the primary number.
 *        Default priority: direct_phone > mobile_phone > corporate_phone > phone_numbers[0]
 */
function apollo_normalize_contact(array $c, string $preferredPhoneField = ''): array {
  $firstName = trim((string)($c['first_name'] ?? ''));
  $lastName  = trim((string)($c['last_name'] ?? ''));

  // Build all available phones in order
  $allPhones = [];
  if (!empty($c['direct_phone']))    $allPhones[] = ['number' => trim((string)$c['direct_phone']),    'type' => 'direct',  'field' => 'direct_phone'];
  if (!empty($c['mobile_phone']))    $allPhones[] = ['number' => trim((string)$c['mobile_phone']),    'type' => 'mobile',  'field' => 'mobile_phone'];
  if (!empty($c['corporate_phone'])) $allPhones[] = ['number' => trim((string)$c['corporate_phone']), 'type' => 'work',    'field' => 'corporate_phone'];
  if (!empty($c['phone_numbers']) && is_array($c['phone_numbers'])) {
    foreach ($c['phone_numbers'] as $pn) {
      $num = trim((string)($pn['sanitized_number'] ?? $pn['raw_number'] ?? ''));
      if ($num !== '') $allPhones[] = ['number' => $num, 'type' => (string)($pn['type'] ?? 'other'), 'field' => 'phone_numbers'];
    }
  }

  // Pick primary: preferred field first, then default order
  $phone = '';
  if ($preferredPhoneField !== '') {
    foreach ($allPhones as $ap) {
      if (($ap['field'] ?? '') === $preferredPhoneField && $ap['number'] !== '') {
        $phone = $ap['number'];
        break;
      }
    }
  }
  // Fallback to default priority if preferred not found
  if ($phone === '') {
    foreach ($allPhones as $ap) {
      if ($ap['number'] !== '') { $phone = $ap['number']; break; }
    }
  }

  // Build additional phones (everything except primary, deduped)
  $additionalPhones = [];
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
