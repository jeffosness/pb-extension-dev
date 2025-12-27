<?php
// server/public/api/crm/hubspot/pb_dialsession_selection.php
//
// Creates a PhoneBurner dial session from a HubSpot selection (IDs) sent by the extension.
// Unified parity with /api/crm/generic/dialsession_from_scan.php:
// - json_input() / get_client_id_or_fail()
// - load_pb_token($client_id)
// - load_hs_tokens($client_id)
// - save_session_state(session_token, ...)
// - api_ok_flat([session_token, dialsession_url, ...])

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

// -----------------------------------------------------------------------------
// HubSpot API helper (v3)
// -----------------------------------------------------------------------------
function hs_refresh_access_token_or_fail(string $client_id, array $hsTokens): array {
  $cfg = cfg();
  $hsClientId     = $cfg['HS_CLIENT_ID'] ?? null;
  $hsClientSecret = $cfg['HS_CLIENT_SECRET'] ?? null;

  if (!$hsClientId || !$hsClientSecret) {
    api_error('Server missing HS_CLIENT_ID/HS_CLIENT_SECRET for token refresh', 'server_error', 500);
  }

  $refresh = $hsTokens['refresh_token'] ?? '';
  if (!$refresh) {
    api_error('HubSpot token expired and no refresh_token is available. Please reconnect HubSpot.', 'unauthorized', 401);
  }

  $t0 = microtime(true);
  list($status, $resp) = http_post_form(
    'https://api.hubapi.com/oauth/v1/token',
    [
      'grant_type'    => 'refresh_token',
      'client_id'     => $hsClientId,
      'client_secret' => $hsClientSecret,
      'refresh_token' => $refresh,
    ]
  );
  $hs_ms = (int) round((microtime(true) - $t0) * 1000);

  if ($status < 200 || $status >= 300 || !is_array($resp)) {
    api_log('hubspot_refresh.error', [
      'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
      'status' => (int)$status,
      'hs_ms' => $hs_ms,
    ]);
    api_error('HubSpot token refresh failed. Please reconnect HubSpot.', 'unauthorized', 401);
  }

  // Keep refresh_token if HubSpot doesn’t return a new one
  if (empty($resp['refresh_token'])) {
    $resp['refresh_token'] = $refresh;
  }

  $now        = time();
  $expires_in = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 1800;
  $resp['created_at'] = $now;
  $resp['expires_at'] = $now + max(0, $expires_in - 60);

  save_hs_tokens($client_id, $resp);

  api_log('hubspot_refresh.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'hub_id' => $resp['hub_id'] ?? null,
    'hs_ms' => $hs_ms,
  ]);

  return $resp;
}
function hs_fetch_contacts_with_refresh_retry(string $client_id, array &$hs, string &$hsAccess, array $ids, array &$diag) {
  $contacts = hs_fetch_contacts_by_ids($hsAccess, $ids, $diag);

  $lastHttp = $diag['contacts_fetch']['last_http'] ?? null;
  if (empty($contacts) && $lastHttp === 401) {
    // refresh + retry once
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = (string)($hs['access_token'] ?? '');
    $contacts = hs_fetch_contacts_by_ids($hsAccess, $ids, $diag);
  }

  return $contacts;
}

function hs_token_is_expired(array $hsTokens): bool {
  $exp = isset($hsTokens['expires_at']) ? (int)$hsTokens['expires_at'] : 0;
  return $exp > 0 && time() >= $exp;
}

function hs_api_get_json($accessToken, $url) {
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

function extract_ids_from_records($records) {
  if (!is_array($records)) return [];
  $out = [];
  foreach ($records as $r) {
    $id = null;
    if (is_array($r)) $id = $r['id'] ?? null;
    elseif (is_scalar($r)) $id = $r;

    $id = trim((string)$id);
    if ($id !== '') $out[$id] = true;
  }
  return array_keys($out);
}

// Fetch contact objects by ID (HubSpot contacts)
function hs_fetch_contacts_by_ids($accessToken, array $contactIds, &$diag = []) {
  $contacts = [];
  $diag['contacts_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  foreach ($contactIds as $cid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/contacts/' . rawurlencode($cid) .
           '?properties=firstname,lastname,email,phone,mobilephone';

    list($code, $json, $_raw) = hs_api_get_json($accessToken, $url);
    $diag['contacts_fetch']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      $diag['contacts_fetch']['fail']++;
      continue;
    }

    $props = $json['properties'] ?? [];
    $contacts[] = [
      'hs_id'      => (string)$cid,
      'first_name' => (string)($props['firstname'] ?? ''),
      'last_name'  => (string)($props['lastname'] ?? ''),
      'email'      => (string)($props['email'] ?? ''),
      'phone'      => (string)($props['phone'] ?? ($props['mobilephone'] ?? '')),
    ];
    $diag['contacts_fetch']['ok']++;
  }

  return $contacts;
}



// NEW: Resolve associated contact IDs AND return a map of contactId => [sourceObjectIds...]
// Used so external_crm_data can include deal/company IDs alongside the contact ID.
function hs_resolve_contact_ids_map_from_objects($accessToken, $objectType, array $objectIds, &$diag = []) {
  $contactIds = [];
  $contactToSourceIds = []; // [contactId => [sourceId => true]]

  $diag['assoc_resolve'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  foreach ($objectIds as $oid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/' . rawurlencode($objectType) . '/' . rawurlencode($oid) .
           '?associations=contacts&archived=false';

    list($code, $json, $_raw) = hs_api_get_json($accessToken, $url);
    $diag['assoc_resolve']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      $diag['assoc_resolve']['fail']++;
      continue;
    }

    $assoc = $json['associations']['contacts']['results'] ?? [];
    if (!is_array($assoc)) {
      $diag['assoc_resolve']['fail']++;
      continue;
    }

    foreach ($assoc as $row) {
      $cid = (string)($row['id'] ?? '');
      if ($cid === '') continue;

      $contactIds[$cid] = true;

      if (!isset($contactToSourceIds[$cid])) $contactToSourceIds[$cid] = [];
      $contactToSourceIds[$cid][(string)$oid] = true;
    }

    $diag['assoc_resolve']['ok']++;
  }

  // Convert to arrays
  $contactIdsList = array_keys($contactIds);

  $map = [];
  foreach ($contactToSourceIds as $cid => $srcSet) {
    $map[$cid] = array_keys($srcSet);
  }

  return [$contactIdsList, $map];
}


// -----------------------------------------------------------------------------
// PhoneBurner API compatibility wrapper (unified projects vary)
// -----------------------------------------------------------------------------
function pb_call_dialsession($pat, array $payload) {
  // Prefer pb_api_call if present
  if (function_exists('pb_api_call')) {
    return pb_api_call($pat, 'POST', '/dialsession', $payload); // [info, resp]
  }

  // Some unified codebases expose pb_api($pat, $method, $path, $payload)
  if (function_exists('pb_api')) {
    $resp = pb_api($pat, 'POST', '/dialsession', $payload);
    // Try to emulate pb_api_call return shape
    return [['http_code' => is_array($resp) && isset($resp['_http_code']) ? (int)$resp['_http_code'] : 200], $resp];
  }

  api_error('PhoneBurner API helper not found (pb_api_call/pb_api)', 'server_error', 500);
}

// -----------------------------------------------------------------------------
// Input + tokens (Unified)
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);

$pat = load_pb_token($client_id);
if (!$pat) {
  api_error('No PhoneBurner PAT saved for this client_id', 'unauthorized', 401);
}

$hs = load_hs_tokens($client_id);
if (!is_array($hs)) {
  api_error('No HubSpot tokens saved for this client_id', 'unauthorized', 401);
}

// refresh if expired
if (hs_token_is_expired($hs)) {
  $hs = hs_refresh_access_token_or_fail($client_id, $hs);
}

$hsAccess = (string)($hs['access_token'] ?? '');
if ($hsAccess === '') {
  api_error('No HubSpot access token saved for this client_id', 'unauthorized', 401);
}

$mode    = (string)($data['mode'] ?? 'contacts');
$records = $data['records'] ?? [];
$context = $data['context'] ?? [];

$ids = extract_ids_from_records($records);
if (empty($ids)) {
  api_error('No selected records provided', 'bad_request', 400);
}

// -----------------------------------------------------------------------------
// Build contact list from HubSpot selection
// -----------------------------------------------------------------------------
$diag = [
  'mode' => $mode,
  'selected_ids' => count($ids),
];

$hsContacts = [];
$sourceObjectIdsByContact = []; // NEW: [contactId => [dealIds...] or [companyIds...]]
$sourceObjectType = null;       // NEW: 'deals' | 'companies' | null

if ($mode === 'contacts') {
  $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $ids, $diag);

} elseif ($mode === 'deals') {
  $sourceObjectType = 'deals';
  list($contactIds, $map) = hs_resolve_contact_ids_map_from_objects($hsAccess, 'deals', $ids, $diag);
  $diag['resolved_contact_ids'] = count($contactIds);

  $sourceObjectIdsByContact = is_array($map) ? $map : [];

  $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $contactIds, $diag);

} elseif ($mode === 'companies') {
  $sourceObjectType = 'companies';
  list($contactIds, $map) = hs_resolve_contact_ids_map_from_objects($hsAccess, 'companies', $ids, $diag);
  $diag['resolved_contact_ids'] = count($contactIds);

  $sourceObjectIdsByContact = is_array($map) ? $map : [];

  $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $contactIds, $diag);

} else {
  api_error('Invalid mode', 'bad_request', 400);
}



if (empty($hsContacts)) {
  // Important: return diagnostic HTTP codes for quick troubleshooting (no secrets)
  api_error('No dialable contacts returned from HubSpot selection', 'bad_request', 400, $diag);
}

// -----------------------------------------------------------------------------
// Normalize into PhoneBurner dialsession payload (Unified parity)
// -----------------------------------------------------------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];
$skipped      = 0;

$portalId     = (string)($context['portalId'] ?? '');
$sourceUrl    = (string)($context['url'] ?? '');
$sourceLabel  = (string)($context['title'] ?? '');

foreach ($hsContacts as $c) {
  $first = trim((string)($c['first_name'] ?? ''));
  $last  = trim((string)($c['last_name'] ?? ''));
  $email = trim((string)($c['email'] ?? ''));
  $phone = trim((string)($c['phone'] ?? ''));
  $hsId  = (string)($c['hs_id'] ?? '');

  if ($hsId === '') { $skipped++; continue; }
  if ($phone === '' && $email === '') { $skipped++; continue; }

  // Record URL for follow-me
  $recordUrl = ($portalId !== '')
    ? ('https://app.hubspot.com/contacts/' . rawurlencode($portalId) . '/record/0-1/' . rawurlencode($hsId))
    : null;

  // We will use this as our internal key (NOT sent to PB as external_id)
  $externalId = $hsId;

  // REQUIRED: external_crm_data must be an ARRAY of objects with crm_id + crm_name
  $externalCrmData = [];

  // Always include the HubSpot contact identity
  $externalCrmData[] = [
    'crm_id'   => $hsId,
    'crm_name' => 'hubspot',
  ];

  // OPTIONAL: include originating deal/company IDs as additional external_crm_data entries
  // - deals => crm_name = hubspotdeal, crm_id = dealId
  // - companies => crm_name = hubspotcompany, crm_id = companyId
  if (($mode === 'deals' || $mode === 'companies') && !empty($sourceObjectType)) {
    $srcIds = $sourceObjectIdsByContact[$hsId] ?? [];
    if (is_array($srcIds) && !empty($srcIds)) {
      $crmName = ($sourceObjectType === 'deals') ? 'hubspotdeal' : 'hubspotcompany';

      foreach ($srcIds as $srcId) {
        $srcId = trim((string)$srcId);
        if ($srcId === '') continue;

        $externalCrmData[] = [
          'crm_id'   => $srcId,
          'crm_name' => $crmName,
        ];
      }
    }
  }

  // Build PB contact WITHOUT external_id
  $pbContacts[] = [
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone ?: null,
    'email'             => $email ?: null,
    'external_crm_data' => $externalCrmData,
  ];


  $displayName = trim(($first !== '' || $last !== '') ? ($first . ' ' . $last) : '');

$contacts_map[$externalId] = [
  'name'           => $displayName,
  'first_name'     => $first,
  'last_name'      => $last,
  'phone'          => $phone,
  'email'          => $email,

  'source_url'     => $sourceUrl ?: null,
  'source_label'   => $sourceLabel ?: null,

  'crm_name'       => 'hubspot',
  'crm_identifier' => $externalId,

  'record_url'     => $recordUrl ?: null,
];
}

if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, [
    'skipped' => $skipped,
    'hs_contacts' => count($hsContacts),
  ]);
}

$base = rtrim(cfg()['BASE_URL'] ?? '', '/');
if ($base === '') {
  api_error('Missing BASE_URL in config', 'server_error', 500);
}

$callbacks = [
  [
    'callback_type' => 'api_contact_displayed',
    'callback'      => $base . '/webhooks/contact_displayed.php?s=' . urlencode($session_token),
  ],
  [
    'callback_type' => 'api_calldone',
    'callback'      => $base . '/webhooks/call_done.php?s=' . urlencode($session_token),
  ],
];

$payload = [
  'name'        => 'HubSpot – ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id' => $client_id,
    'source'    => 'hubspot-selection',
    'crm_name'  => 'hubspot',
  ],
  'callbacks'    => $callbacks,
  'webhook_meta' => [
    'session_token' => $session_token,
    'client_id'     => $client_id,
    'crm_name'      => 'hubspot',
  ],
];

// Call PhoneBurner
$t0 = microtime(true);
list($info, $resp) = pb_call_dialsession($pat, $payload);
$pb_ms = (int) round((microtime(true) - $t0) * 1000);

$httpCode = (int)($info['http_code'] ?? 0);
if ($httpCode >= 400 || !is_array($resp)) {
  api_error('PhoneBurner dialsession failed', 'pb_error', 502, [
    'pb_http' => $httpCode,
    'pb_ms'   => $pb_ms,
  ]);
}

// -------------------------
// Extract launch URL (match dialsession_from_scan.php)
// -------------------------
$launch_url = $resp['dialsessions']['redirect_url'] ?? null;
$dial_id    = $resp['dialsessions']['id'] ?? null;

// Fallbacks (in case PB returns a slightly different shape)
if (!$launch_url) {
  $launch_url =
    $resp['dialsession']['redirect_url'] ??
    $resp['dialsession']['launch_url'] ??
    $resp['redirect_url'] ??
    $resp['launch_url'] ??
    $resp['dialsession_url'] ??
    null;

  $dial_id =
    $dial_id ??
    ($resp['dialsession']['id'] ?? null);
}

if (!$launch_url) {
  // Log keys only (no payload / no PII)
  api_log('hubspot_selection.error.no_launch_url', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ms'          => $pb_ms,
    'pb_http'        => $httpCode,
    'resp_keys'      => is_array($resp) ? array_slice(array_keys($resp), 0, 30) : null,
    'has_dialsessions' => isset($resp['dialsessions']),
  ]);

  api_error('PhoneBurner response missing launch URL', 'pb_error', 502, [
    'pb_ms' => $pb_ms,
  ]);
}

// -------------------------
// Save initial session state (match dialsession_from_scan.php shape)
// -------------------------
$state = [
  'session_token'   => $session_token,
  'dialsession_id'  => $resp['dialsessions']['id'] ?? ($resp['dialsession_id'] ?? null),
  'dialsession_url' => $launch_url,

  'client_id'       => $client_id,
  'created_at'      => date('c'),

  'current'         => null,
  'last_call'       => null,
  'stats'           => [
    'total_calls'  => 0,
    'connected'    => 0,
    'appointments' => 0,
  ],

  'contacts_map'    => $contacts_map,
  'crm_name'        => 'hubspot',
];

save_session_state($session_token, $state);

// Unified-style response (flat keys)
api_ok_flat([
  'session_token'   => $session_token,
  'dialsession_url' => $launch_url,
  'launch_url'      => $launch_url, // optional backward compat
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
]);

