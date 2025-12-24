<?php
// HubSpot/api/pb_dialsession_selection.php
//
// Build a PhoneBurner dial session from a HubSpot selection sent by the extension.
//
// Expected JSON body:
// {
//   "mode": "contacts" | "deals" | "companies",
//   "records": [ { "id": "123" }, { "id": "456" } ] OR [ "123","456" ],
//   "context": { "portalId": "...", "url": "...", "title": "...", "selectedCount": 10 }
// }

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

// -----------------------------------------------------------------------------
// Debug log (NO PII/tokens recommended; keep minimal)
// -----------------------------------------------------------------------------
function hs_dial_debug($row) {
  $dir = __DIR__ . '/../data';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $row['ts'] = date('c');
  @file_put_contents($dir . '/hs_dial_debug.log',
    json_encode($row, JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND
  );
}

/**
 * Load the PhoneBurner PAT from the generic tokens dir for the given user.
 * Supports:
 *   /generic_crm/tokens/<user>.json
 *   /generic_cm/tokens/<user>.json
 * And token structures:
 *   { "pat": "..." }
 *   { "access_token": "..." }
 *   { "pb": { "access_token": "..." } }
 */
function hs_load_pb_access_token_for_user($userId) {
  $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$userId);

  // This file lives in /HubSpot/api
  // Two levels up: project root
  $rootDir  = dirname(__DIR__, 2);

  $candidateDirs = [
    $rootDir . '/generic_crm/tokens',
    $rootDir . '/generic_cm/tokens',
  ];

  foreach ($candidateDirs as $dir) {
    $path = $dir . '/' . $safeUser . '.json';
    if (!is_file($path)) continue;

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') continue;

    $j = json_decode($raw, true);
    if (!is_array($j)) continue;

    if (!empty($j['pat'])) return $j['pat'];
    if (!empty($j['access_token'])) return $j['access_token'];
    if (!empty($j['pb']['access_token'])) return $j['pb']['access_token'];
  }

  return null;
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function extract_ids_from_records($records) {
  $idSet = [];
  if (!is_array($records)) return [];

  foreach ($records as $r) {
    $id = null;
    if (is_array($r)) $id = $r['id'] ?? null;
    elseif (is_scalar($r)) $id = $r;

    $id = trim((string)$id);
    if ($id !== '') {
      // IMPORTANT: do NOT filter by length; HubSpot IDs can be short (e.g. 2801)
      $idSet[$id] = true;
    }
  }
  return array_keys($idSet);
}

function hs_api_get_v3($userId, $url) {
  // utils.php provides hs_get_json($userId, $url) in your existing codebase
  return hs_get_json($userId, $url);
}

/**
 * Resolve selected IDs into HubSpot CONTACT IDs:
 * - mode=contacts: ids already are contact IDs
 * - mode=deals/companies: fetch object and pull associations.contacts.results[].id
 */
function hs_resolve_contact_ids_from_selection($userId, $mode, $ids) {
  $contactIdSet = [];

  if ($mode === 'contacts') {
    foreach ($ids as $id) $contactIdSet[(string)$id] = true;
    return array_keys($contactIdSet);
  }

  $objType = null;
  if ($mode === 'deals') $objType = 'deals';
  if ($mode === 'companies') $objType = 'companies';

  if (!$objType) return [];

  foreach ($ids as $pid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/' .
           rawurlencode($objType) . '/' . rawurlencode($pid) .
           '?associations=contacts&archived=false';

    list($status, $res) = hs_api_get_v3($userId, $url);
    if ($status !== 200 || !is_array($res)) continue;

    $assoc = $res['associations']['contacts']['results'] ?? [];
    if (!is_array($assoc)) continue;

    foreach ($assoc as $row) {
      if (!is_array($row) || empty($row['id'])) continue;
      $cid = trim((string)$row['id']);
      if ($cid !== '') $contactIdSet[$cid] = true;
    }
  }

  return array_keys($contactIdSet);
}

// -----------------------------------------------------------------------------
// 1) Auth checks: PB token + HS token
// -----------------------------------------------------------------------------
$userId = current_user_id();

$pb = hs_load_pb_access_token_for_user($userId);
if (!$pb) {
  hs_dial_debug(['step' => 'no_pb', 'user' => $userId]);
  json_out(['error' => 'PB not connected'], 401);
}

// HS tokens: try both dashed and no-dash (matches your standalone approach)
$idsToTry = [];
if ($userId) {
  $idsToTry[] = $userId;
  $noDash = str_replace('-', '', $userId);
  if ($noDash !== $userId) $idsToTry[] = $noDash;
}

$hsTokens = null;
$hsUserId = null;
foreach ($idsToTry as $cand) {
  $t = hs_get_tokens($cand);
  if ($t && !empty($t['access_token'])) {
    $hsTokens = $t;
    $hsUserId = $cand;
    break;
  }
}

if (!$hsTokens) {
  hs_dial_debug(['step' => 'no_hs', 'user_init' => $userId, 'tried_ids' => $idsToTry]);
  json_out(['error' => 'HubSpot not connected'], 401);
}

// Normalize to the ID that actually has HS tokens
$userId = $hsUserId;

// -----------------------------------------------------------------------------
// 2) Read request payload
// -----------------------------------------------------------------------------
$body    = read_json();
$mode    = isset($body['mode']) ? (string)$body['mode'] : 'contacts';
$records = $body['records'] ?? [];
$context = $body['context'] ?? [];

$ids = extract_ids_from_records($records);

hs_dial_debug([
  'step' => 'payload',
  'mode' => $mode,
  'records_count' => is_array($records) ? count($records) : 0,
  'ids_count' => count($ids),
]);

if (empty($ids)) {
  json_out(['error' => 'No valid HubSpot IDs in selection'], 400);
}

if (!in_array($mode, ['contacts','deals','companies'], true)) {
  json_out(['error' => 'Unsupported mode', 'mode' => $mode], 400);
}

$portalId    = $context['portalId'] ?? null;
$sourceLabel = $context['title']   ?? 'HubSpot selection';
$sourceUrl   = $context['url']     ?? null;

// -----------------------------------------------------------------------------
// 3) Resolve to CONTACT IDs (for deals/companies, use associations)
// -----------------------------------------------------------------------------
$contactIds = hs_resolve_contact_ids_from_selection($userId, $mode, $ids);

hs_dial_debug([
  'step' => 'resolved_contact_ids',
  'mode' => $mode,
  'contact_ids_count' => count($contactIds),
]);

if (empty($contactIds)) {
  json_out([
    'error' => 'No associated HubSpot contacts found to dial.',
    'debug' => [
      'mode' => $mode,
      'ids_received' => $ids,
    ]
  ], 400);
}

// -----------------------------------------------------------------------------
// 4) Fetch HubSpot contact details for each contact ID
// -----------------------------------------------------------------------------
$contacts = [];
foreach ($contactIds as $cid) {
  $url = 'https://api.hubapi.com/crm/v3/objects/contacts/' .
         rawurlencode($cid) .
         '?properties=firstname,lastname,phone,mobilephone,email&archived=false';

  list($status, $res) = hs_api_get_v3($userId, $url);
  if ($status !== 200 || !is_array($res)) continue;

  $props = $res['properties'] ?? [];

  $first = trim((string)($props['firstname']   ?? ''));
  $last  = trim((string)($props['lastname']    ?? ''));
  $phone = trim((string)($props['phone']       ?? ''));
  $mobi  = trim((string)($props['mobilephone'] ?? ''));
  $email = trim((string)($props['email']       ?? ''));

  if ($phone === '' && $mobi !== '') $phone = $mobi;

  // Match standalone behavior: require a phone number to dial
  if ($phone === '') continue;

  $recordUrl = null;
  if ($portalId) {
    $recordUrl = "https://app.hubspot.com/contacts/{$portalId}/record/0-1/{$cid}";
  }

  $contacts[] = [
    'hubspot_id' => (string)$cid,
    'first'      => $first,
    'last'       => $last,
    'phone'      => $phone,
    'email'      => $email,
    'record_url' => $recordUrl,
  ];
}

if (empty($contacts)) {
  json_out([
    'error' => 'No dialable HubSpot contacts (no phone or fetch failed)',
    'debug' => [
      'mode' => $mode,
      'contact_ids_count' => count($contactIds),
      'selectedCount' => $context['selectedCount'] ?? null,
    ]
  ], 400);
}

// -----------------------------------------------------------------------------
// 5) Build PhoneBurner dialsession payload (standalone style)
// -----------------------------------------------------------------------------
$session_token = random_token(18);

$pbContacts   = [];
$skipped      = 0;
$contacts_map = [];

foreach ($contacts as $c) {
  $hsId  = trim((string)($c['hubspot_id'] ?? ''));
  $first = trim((string)($c['first'] ?? ''));
  $last  = trim((string)($c['last']  ?? ''));
  $phone = trim((string)($c['phone'] ?? ''));
  $email = trim((string)($c['email'] ?? ''));

  if ($phone === '') { $skipped++; continue; }

  $pbContacts[] = [
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone,
    'email'             => $email,
    'external_crm_data' => [
      [
        'crm_id'   => $hsId,
        'crm_name' => 'hubspot',
      ],
    ],
  ];

  $name = trim($first . ' ' . $last);
  if ($name === '' && $email !== '') $name = $email;
  if ($name === '' && $phone !== '') $name = $phone;

  $contacts_map[$hsId] = [
    'name'           => $name,
    'first_name'     => $first,
    'last_name'      => $last,
    'phone'          => $phone,
    'email'          => $email,
    'crm_name'       => 'hubspot',
    'crm_identifier' => $hsId,
    'record_url'     => $c['record_url'] ?? null,
    'source_label'   => $sourceLabel,
    'source_url'     => $sourceUrl,
  ];
}

if (empty($pbContacts)) {
  json_out([
    'error' => 'No dialable contacts after filtering (no phone)',
    'debug' => ['skipped_no_phone' => $skipped]
  ], 400);
}

// Callbacks (include BOTH so follow-me + call-done stats work)
$base = rtrim(cfg()['BASE_URL'] ?? '', '/');
$callbacks = [
  [
    'callback_type' => 'api_contact_displayed',
    'callback'      => $base . '/webhooks/contact_displayed.php?s=' . urlencode($session_token) . '&src=' . urlencode('hubspot-selection'),
  ],
  [
    'callback_type' => 'api_calldone',
    'callback'      => $base . '/webhooks/call_done.php?s=' . urlencode($session_token) . '&src=' . urlencode('hubspot-selection'),
  ],
];

$payload = [
  'name'        => 'HubSpot Selection – ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'userId' => $userId,
    'source' => 'hubspot-selection',
    'mode'   => $mode,
  ],
  'callbacks'   => $callbacks,
  'webhook_meta'=> [
    'session_token' => $session_token,
    'mode'          => $mode,
  ],
];

// -----------------------------------------------------------------------------
// 6) Create PB dialsession
// -----------------------------------------------------------------------------
try {
  $resp   = pb_post_json('https://www.phoneburner.com/rest/1/dialsession', $payload, $pb);
  $status = $resp['status'];
  $body   = $resp['body'];
  $j      = json_decode($body, true);

  hs_dial_debug(['step' => 'pb_response', 'status' => $status, 'ok' => ($status > 0 && $status < 400)]);

  if ($status >= 400 || $status === 0) {
    json_out([
      'error'  => 'PhoneBurner API error',
      'status' => $status,
      'body'   => $j ?: $body,
    ], $status ?: 502);
  }

  $launch_url = $j['dialsessions']['redirect_url'] ?? null;
  if (!$launch_url) {
    json_out([
      'error' => 'No launch URL in PhoneBurner response',
      'body'  => $j,
    ], 502);
  }

  // Save initial session state so follow-me & stats work
  $state = [
    'session_token'   => $session_token,
    'dialsession_id'  => $j['dialsessions']['id'] ?? null,
    'dialsession_url' => $launch_url,
    'client_id'       => $userId,
    'created_at'      => date('c'),
    'crm'             => 'hubspot',
    'context'         => [
      'mode'      => $mode,
      'portalId'  => $portalId,
      'sourceUrl' => $sourceUrl,
      'title'     => $sourceLabel,
    ],
    'current'         => null,
    'last_call'       => null,
    'last_event_type' => null,
    'stats'           => [
      'total_calls'  => 0,
      'connected'    => 0,
      'appointments' => 0,
      'by_status'    => [],
    ],
    'contacts_map'    => $contacts_map,
  ];

  save_session_state($session_token, $state);

  json_out([
    'launch_url'    => $launch_url,
    'session_token' => $session_token,
    'contacts_sent' => count($pbContacts),
    'skipped'       => $skipped,
    'mode'          => $mode,
  ]);

} catch (Exception $e) {
  hs_dial_debug(['step' => 'exception', 'error' => $e->getMessage()]);
  json_out(['error' => $e->getMessage()], 500);
}

// -----------------------------------------------------------------------------
// Helper – POST JSON to PB
// -----------------------------------------------------------------------------
function pb_post_json($url, $payload, $bearer) {
  $headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $bearer,
  ];
  $opts = [
    'http' => [
      'method'        => 'POST',
      'header'        => implode("\r\n", $headers) . "\r\n",
      'content'       => json_encode($payload),
      'timeout'       => 60,
      'ignore_errors' => true,
    ],
  ];
  $ctx  = stream_context_create($opts);
  $body = file_get_contents($url, false, $ctx);

  $status = 0;
  if (isset($http_response_header)) {
    foreach ($http_response_header as $h) {
      if (preg_match('#HTTP/\S+\s(\d{3})#', $h, $m)) {
        $status = (int)$m[1];
        break;
      }
    }
  }
  return ['status' => $status, 'body' => $body];
}
