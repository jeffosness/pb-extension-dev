<?php
// server/public/api/crm/forth/forth_helpers.php
//
// Shared Forth CRM API helpers used by the (bootstrap-context) endpoints:
//   - state.php                     (connection status)          [TODO]
//   - save_credentials.php          (store client_id/secret)     [TODO]
//   - pb_dialsession_selection.php  (dial sessions from API)     [TODO — needs
//                                    contact/list endpoint shapes]
//
// Forth API base: https://api.forthcrm.com/v1/
// Auth: durable client_id (Key ID) + client_secret (Secret) are exchanged at
//       POST /auth/token for a 10-day `api_key`, sent as the `Api-Key:` header.
//       There is NO refresh_token — re-minting re-posts the durable creds.
// Docs: https://developer.setforth.com/api/forth-crm
//
// NOTE: this file is loaded by endpoints that DO have bootstrap.php (api_log /
// api_error are available). The call logger (webhook context, no bootstrap)
// re-implements token minting inline via _pb_write_api_log — mirrors the
// close_helpers.php / close_call_logger.php split. See close_call_logger.php.

if (!defined('FORTH_API_BASE')) {
  define('FORTH_API_BASE', 'https://api.forthcrm.com/v1/');
}

/**
 * True when the cached api_key is missing or past its early-refresh window.
 */
function forth_token_is_expired(array $tokens): bool {
  // Missing api_key OR missing expires_at both count as expired so a partially
  // written / legacy token file self-heals via a fresh mint instead of using a
  // stale key forever (which would 401 on every call with no recovery).
  if (empty($tokens['api_key']) || empty($tokens['expires_at'])) return true;
  return time() >= (int)$tokens['expires_at'];
}

/**
 * Exchange the durable client_id/client_secret for a fresh api_key access token.
 * Persists the new api_key + expires_at back into the token file and returns the
 * updated $tokens array. Bootstrap context only (uses api_log / api_error).
 *
 * Forth response shape (200):
 *   { "status": {"code":200,"message":"Success"},
 *     "response": { "api_key": "xxxx-...", "expires_in": 864000 } }
 */
function forth_mint_access_token_or_fail(string $client_id, array $tokens): array {
  $forthClientId     = (string)($tokens['client_id'] ?? '');
  $forthClientSecret = (string)($tokens['client_secret'] ?? '');

  if ($forthClientId === '' || $forthClientSecret === '') {
    api_error('Forth credentials missing. Please reconnect Forth (enter your API Key ID + Secret).', 'unauthorized', 401);
  }

  // JSON body — Forth's /auth/token expects application/json (see docs curl).
  $t0 = microtime(true);
  $ch = curl_init(FORTH_API_BASE . 'auth/token');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
      'client_id'     => $forthClientId,
      'client_secret' => $forthClientSecret,
    ]),
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw  = curl_exec($ch);
  $info = curl_getinfo($ch);
  $err  = curl_error($ch);
  curl_close($ch);
  $info['raw_body'] = is_string($raw) ? $raw : '';
  if ($err !== '') $info['curl_error'] = $err;

  $ms     = (int) round((microtime(true) - $t0) * 1000);
  $status = (int)($info['http_code'] ?? 0);
  $resp   = ($status >= 200 && $status < 300 && is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;

  // Forth nests the token under `response`; also tolerate a flat shape.
  $inner  = (is_array($resp) && isset($resp['response']) && is_array($resp['response'])) ? $resp['response'] : $resp;
  $apiKey = is_array($inner) ? (string)($inner['api_key'] ?? '') : '';

  if ($status < 200 || $status >= 300 || $apiKey === '') {
    $fail = describe_api_failure($info, $resp);
    api_log('forth_token.error', [
      'client_id_hash' => substr(hash('sha256', $client_id), 0, 12),
      'status'         => $fail['status'],
      'ms'             => $ms,
      'provider_msg'   => $fail['message'],
      'response'       => $fail['response'],
      'body_snippet'   => $fail['body_snippet'],
      'curl_error'     => $fail['curl_error'],
    ]);
    api_error('Forth token request failed. Please verify your API credentials and reconnect Forth.', 'unauthorized', 401);
  }

  $now        = time();
  $expiresIn  = isset($inner['expires_in']) ? (int)$inner['expires_in'] : 864000; // 10 days default
  $tokens['api_key']    = $apiKey;
  $tokens['created_at'] = $now;
  // Refresh 1 hour early — dial sessions are short but the 10-day window means
  // a token minted near expiry could lapse mid-session otherwise.
  $tokens['expires_at'] = $now + max(0, $expiresIn - 3600);

  save_forth_tokens($client_id, $tokens);

  api_log('forth_token.ok', [
    'client_id_hash' => substr(hash('sha256', $client_id), 0, 12),
    'ms' => $ms,
  ]);

  return $tokens;
}

/**
 * Return a valid api_key, minting a fresh one if missing/expired.
 * Mutates $tokens in place so callers keep the refreshed values.
 */
function forth_get_access_token_or_fail(string $client_id, array &$tokens): string {
  if (forth_token_is_expired($tokens)) {
    $tokens = forth_mint_access_token_or_fail($client_id, $tokens);
  }
  return (string)($tokens['api_key'] ?? '');
}

/**
 * GET request to the Forth API with Api-Key auth.
 * Returns [$httpCode, $jsonArray, $rawBody].
 */
function forth_api_get_json(string $apiKey, string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Api-Key: ' . $apiKey,     // NOTE: Forth uses `Api-Key`, NOT `Authorization: Bearer`
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw  = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $json = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
  return [$code, $json, $raw];
}

/**
 * POST JSON to the Forth API with Api-Key auth.
 * Returns [$httpCode, $jsonArray, $rawBody].
 */
function forth_api_post_json(string $apiKey, string $url, array $body): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
      'Api-Key: ' . $apiKey,
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw  = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $json = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
  return [$code, $json, $raw];
}

/**
 * Fetch call dispositions (GET /v1/calls/disposition) and return a
 * lowercased-name => id map, merging system + custom dispositions.
 *
 * Response shape:
 *   { "response": { "system_dispositions": [ {"disposition_name":"No Answer","id":1}, ... ],
 *                   "custom_dispositions": [ ... ]? } }
 * NOTE: `id` comes back as int OR string across entries — always cast to int.
 */
function forth_fetch_disposition_map(string $apiKey): array {
  list($code, $json, $raw) = forth_api_get_json($apiKey, FORTH_API_BASE . 'calls/disposition');
  $map = [];
  if ($code !== 200 || !is_array($json)) {
    // Log Forth's own error text (not just the HTTP code) so a customer whose
    // dispositions silently stop mapping has a diagnostic trail. Uses
    // _pb_write_api_log so it is safe in the webhook context (no bootstrap).
    $fail = describe_api_failure(['http_code' => $code, 'raw_body' => (string)$raw], $json);
    _pb_write_api_log('forth_dispo_fetch.error', [
      'status'       => $fail['status'],
      'provider_msg' => $fail['message'],
      'response'     => $fail['response'],
      'body_snippet' => $fail['body_snippet'],
      'curl_error'   => $fail['curl_error'],
    ]);
    return $map;
  }

  $inner = (isset($json['response']) && is_array($json['response'])) ? $json['response'] : $json;
  foreach (['system_dispositions', 'custom_dispositions'] as $bucket) {
    $rows = $inner[$bucket] ?? null;
    if (!is_array($rows)) continue;
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $name = strtolower(trim((string)($row['disposition_name'] ?? '')));
      $id   = isset($row['id']) ? (int)$row['id'] : 0;
      if ($name !== '' && $id > 0) $map[$name] = $id;
    }
  }
  return $map;
}

/**
 * Map a PhoneBurner disposition/status into a Forth call_disposition id using a
 * name=>id map from forth_fetch_disposition_map(). Falls back through the known
 * Forth system dispositions (No Answer / Connected / Left Message / Wrong
 * Number) by keyword. Returns null if nothing sensible matches (caller omits
 * call_disposition rather than guessing).
 */
function forth_map_pb_status_to_disposition_id(string $pbStatus, string $connected, array $dispoMap): ?int {
  $s = strtolower(trim($pbStatus));

  // 1) Exact name match against the account's own dispositions.
  if ($s !== '' && isset($dispoMap[$s])) return $dispoMap[$s];

  // 2) Keyword → canonical Forth system disposition name, then look up its id.
  $pick = null;
  if ($s !== '') {
    // Only match specific voicemail phrasings — NOT a bare "message" substring,
    // which would misclassify statuses like "No Message Reached" as Left Message.
    if (strpos($s, 'voicemail') !== false || strpos($s, 'left message') !== false) {
      $pick = 'left message';
    } elseif (strpos($s, 'wrong') !== false || strpos($s, 'bad number') !== false || strpos($s, 'bad_number') !== false) {
      $pick = 'wrong number';
    } elseif (strpos($s, 'no answer') !== false || strpos($s, 'did not answer') !== false || strpos($s, 'busy') !== false) {
      $pick = 'no answer';
    }
  }
  if ($pick === null && strtolower($connected) === '1') {
    $pick = 'connected';
  }

  if ($pick !== null && isset($dispoMap[$pick])) return $dispoMap[$pick];

  return null;
}

/**
 * Format a duration in seconds as Forth's expected "HH:MM:SS" string.
 */
function forth_format_duration_hms(int $seconds): string {
  if ($seconds < 0) $seconds = 0;
  $h = intdiv($seconds, 3600);
  $m = intdiv($seconds % 3600, 60);
  $s = $seconds % 60;
  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}
