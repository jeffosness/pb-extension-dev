<?php
// server/public/api/crm/close/close_helpers.php
//
// Shared Close API helpers used by multiple endpoints:
//   - pb_dialsession_selection.php  (selection-based dial sessions)
//   - state.php                     (connection status)
//
// Close API base: https://api.close.com/api/v1/
// Auth: OAuth 2.0 Bearer tokens
// Docs: https://developer.close.com/
//
// Note: Token refresh and API call helpers are used by state.php (Phase 1).
// Contact fetching helpers (close_fetch_contacts_by_ids, etc.) are for Phase 2
// (dial sessions from API) and are not yet called by any endpoint.

// -----------------------------------------------------------------------------
// Close API helpers
// -----------------------------------------------------------------------------

function close_refresh_access_token_or_fail(string $client_id, array $tokens): array {
  $cfg = cfg();
  $closeClientId     = $cfg['CLOSE_CLIENT_ID'] ?? null;
  $closeClientSecret = $cfg['CLOSE_CLIENT_SECRET'] ?? null;

  if (!$closeClientId || !$closeClientSecret) {
    api_error('Server missing CLOSE_CLIENT_ID/CLOSE_CLIENT_SECRET for token refresh', 'server_error', 500);
  }

  $refresh = $tokens['refresh_token'] ?? '';
  if (!$refresh) {
    api_error('Close token expired and no refresh_token is available. Please reconnect Close.', 'unauthorized', 401);
  }

  // http_post_form_info + describe_api_failure so a failed refresh captures
  // Close's own error text (e.g. "invalid_grant"), not just an HTTP code.
  // This helper fires on every dial-session launch when the access token is
  // stale — hot path for un-triageable customer reports. See LESSONS.md
  // 2026-07-27 for the class of gap this closes.
  $t0 = microtime(true);
  list($info, $resp) = http_post_form_info(
    'https://api.close.com/oauth2/token/',
    [
      'grant_type'    => 'refresh_token',
      'client_id'     => $closeClientId,
      'client_secret' => $closeClientSecret,
      'refresh_token' => $refresh,
    ]
  );
  $ms = (int) round((microtime(true) - $t0) * 1000);
  $status = (int)($info['http_code'] ?? 0);

  if ($status < 200 || $status >= 300 || !is_array($resp)) {
    $fail = describe_api_failure($info, $resp);
    api_log('close_refresh.error', [
      'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
      'status'         => $fail['status'],
      'ms'             => $ms,
      'provider_msg'   => $fail['message'],
      'response'       => $fail['response'],
      'body_snippet'   => $fail['body_snippet'],
      'curl_error'     => $fail['curl_error'],
    ]);
    api_error('Close token refresh failed. Please reconnect Close.', 'unauthorized', 401);
  }

  // Keep refresh_token if Close doesn't return a new one
  if (empty($resp['refresh_token'])) {
    $resp['refresh_token'] = $refresh;
  }

  $now        = time();
  $expires_in = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 3600;
  $resp['created_at'] = $now;
  $resp['expires_at'] = $now + max(0, $expires_in - 60); // refresh 60s early

  save_close_tokens($client_id, $resp);

  api_log('close_refresh.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'ms' => $ms,
  ]);

  return $resp;
}

function close_token_is_expired(array $tokens): bool {
  $exp = isset($tokens['expires_at']) ? (int)$tokens['expires_at'] : 0;
  return $exp > 0 && time() >= $exp;
}

/**
 * GET request to Close API with Bearer auth.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function close_api_get_json($accessToken, $url) {
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
 * POST JSON to Close API with Bearer auth.
 * Returns [$httpCode, $jsonArray, $rawBody]
 */
function close_api_post_json($accessToken, $url, array $body) {
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
 * Fetch the authenticated user's identity from Close (GET /api/v1/me/).
 * Used to verify token validity and get organization info.
 */
function close_get_me($accessToken) {
  return close_api_get_json($accessToken, 'https://api.close.com/api/v1/me/');
}

/**
 * Normalize a raw Close contact object into the shape the dial-session builder
 * expects (close_id, lead_id, first/last name, primary phone/email, and any
 * additional phones). Shared by both contact-fetch paths:
 *   1. close_fetch_contacts_by_ids()   — per-contact GET /contact/{id}/
 *   2. pb_dialsession_selection.php     — the GET /contact/?lead_id= LIST
 *      response, which already returns FULL contact objects. Reusing this
 *      helper there lets us skip a redundant second round-trip per contact.
 *
 * A Close contact object carries: id, name, phones[], emails[], lead_id.
 */
function close_normalize_contact(array $json): array {
  // Split single name field into first/last
  $fullName = trim((string)($json['name'] ?? ''));
  $parts = preg_split('/\s+/', $fullName, 2);
  $firstName = $parts[0] ?? '';
  $lastName  = $parts[1] ?? '';

  // Primary phone = first in phones array; the rest become additional_phone
  $phones = is_array($json['phones'] ?? null) ? $json['phones'] : [];
  $primaryPhone = '';
  $additionalPhones = [];
  foreach ($phones as $ph) {
    $num = trim((string)($ph['phone'] ?? ''));
    if ($num === '') continue;
    if ($primaryPhone === '') {
      $primaryPhone = $num;
    } else {
      $type = strtolower(trim((string)($ph['type'] ?? '')));
      $phoneType = '2'; // default Work
      if ($type === 'mobile') $phoneType = '3';
      elseif ($type === 'home') $phoneType = '1';
      $additionalPhones[] = [
        'number'      => $num,
        'phone_type'  => $phoneType,
        'phone_label' => ucfirst($type ?: 'Phone'),
      ];
    }
  }

  // Primary email = first in emails array
  $emails = is_array($json['emails'] ?? null) ? $json['emails'] : [];
  $primaryEmail = '';
  foreach ($emails as $em) {
    $addr = trim((string)($em['email'] ?? ''));
    if ($addr !== '') { $primaryEmail = $addr; break; }
  }

  return [
    'close_id'          => (string)($json['id'] ?? ''),
    'lead_id'           => (string)($json['lead_id'] ?? ''),
    'first_name'        => $firstName,
    'last_name'         => $lastName,
    'email'             => $primaryEmail,
    'phone'             => $primaryPhone,
    'additional_phones' => $additionalPhones,
  ];
}

/**
 * Fetch contacts by their Close contact IDs.
 * Close contacts have: id, name, phones[], emails[], lead_id
 *
 * Returns normalized array for PhoneBurner dial session creation.
 */
function close_fetch_contacts_by_ids($accessToken, array $contactIds, &$diag = []) {
  $contacts = [];
  $diag['contacts_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  foreach ($contactIds as $cid) {
    $url = 'https://api.close.com/api/v1/contact/' . rawurlencode($cid) . '/';

    list($code, $json, $raw) = close_api_get_json($accessToken, $url);
    $diag['contacts_fetch']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      // Log the FIRST per-batch failure only (avoid log spam on outage).
      if (($diag['contacts_fetch']['fail'] ?? 0) === 0) {
        log_api_failure_from_tuple($code, $json, $raw, 'close_fetch_contacts.per_record_failed', [
          'contact_id_sample' => (string)$cid,
        ]);
      }
      $diag['contacts_fetch']['fail']++;
      continue;
    }

    $normalized = close_normalize_contact($json);
    // Preserve the requested contact ID: the by-ID endpoint echoes it back in
    // json['id'], but pin it explicitly so a missing/mismatched field can
    // never silently drop the record from contacts_map.
    $normalized['close_id'] = (string)$cid;
    $contacts[] = $normalized;
    $diag['contacts_fetch']['ok']++;
  }

  if (($diag['contacts_fetch']['fail'] ?? 0) > 0) {
    _pb_write_api_log('close_fetch_contacts.batch_summary', [
      'ok'        => $diag['contacts_fetch']['ok'],
      'fail'      => $diag['contacts_fetch']['fail'],
      'last_http' => $diag['contacts_fetch']['last_http'],
      'total'     => count($contactIds),
    ]);
  }

  return $contacts;
}

/**
 * Fetch contacts with automatic token refresh retry on 401.
 */
function close_fetch_contacts_with_refresh_retry(string $client_id, array &$tokens, string &$accessToken, array $contactIds, array &$diag = []) {
  $contacts = close_fetch_contacts_by_ids($accessToken, $contactIds, $diag);

  $lastHttp = $diag['contacts_fetch']['last_http'] ?? null;
  if (empty($contacts) && $lastHttp === 401) {
    $tokens = close_refresh_access_token_or_fail($client_id, $tokens);
    $accessToken = (string)($tokens['access_token'] ?? '');
    $contacts = close_fetch_contacts_by_ids($accessToken, $contactIds, $diag);
  }

  return $contacts;
}
