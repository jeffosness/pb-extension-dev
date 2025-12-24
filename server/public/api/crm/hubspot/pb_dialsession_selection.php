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

// If selection is deals/companies, resolve associated contacts then fetch contacts.
function hs_resolve_contact_ids_from_objects($accessToken, $objectType, array $objectIds, &$diag = []) {
  $contactIds = [];
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
      if ($cid !== '') $contactIds[$cid] = true;
    }
    $diag['assoc_resolve']['ok']++;
  }

  return array_keys($contactIds);
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
$hsAccess = is_array($hs) ? ($hs['access_token'] ?? '') : '';
if (!$hsAccess) {
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
if ($mode === 'contacts') {
  $hsContacts = hs_fetch_contacts_by_ids($hsAccess, $ids, $diag);
} elseif ($mode === 'deals') {
  $contactIds = hs_resolve_contact_ids_from_objects($hsAccess, 'deals', $ids, $diag);
  $diag['resolved_contact_ids'] = count($contactIds);
  $hsContacts = hs_fetch_contacts_by_ids($hsAccess, $contactIds, $diag);
} elseif ($mode === 'companies') {
  $contactIds = hs_resolve_contact_ids_from_objects($hsAccess, 'companies', $ids, $diag);
  $diag['resolved_contact_ids'] = count($contactIds);
  $hsContacts = hs_fetch_contacts_by_ids($hsAccess, $contactIds, $diag);
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

  $externalId = $hsId;

  $pbContacts[] = [
    'first_name'  => $first,
    'last_name'   => $last,
    'phone'       => $phone,
    'email'       => $email,
    'external_id' => $externalId,
    'external_crm_data' => [
      'external_id'   => $hsId,
      'external_type' => 'hubspot_contact',
      'external_url'  => $recordUrl,
      'crm'           => 'hubspot',
    ],
  ];

  $contacts_map[$externalId] = [
    'record_url'   => $recordUrl,
    'source_url'   => $sourceUrl,
    'source_label' => $sourceLabel,
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

// Launch URL
$launch_url = $resp['launch_url'] ?? $resp['dialsession_url'] ?? null;
if (!$launch_url) {
  api_error('PhoneBurner response missing launch URL', 'pb_error', 502, [
    'pb_ms' => $pb_ms,
  ]);
}

// Save session state for SSE/follow-me
save_session_state($session_token, [
  'client_id'     => $client_id,
  'crm_name'      => 'hubspot',
  'created_at'    => date('c'),
  'contacts_map'  => $contacts_map,
]);

// Unified-style response
api_ok_flat([
  'session_token'   => $session_token,
  'dialsession_url' => $launch_url,
  // Optional backward compatibility:
  'launch_url'      => $launch_url,
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
]);
