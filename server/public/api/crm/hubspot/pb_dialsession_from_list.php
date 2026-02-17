<?php
// server/public/api/crm/hubspot/pb_dialsession_from_list.php
//
// Creates a PhoneBurner dial session from ALL members of a HubSpot list.
// Supports both contact lists and company lists.
//
// IMPORTANT — PhoneBurner ↔ HubSpot Data Sync caveat:
// If a customer has the PhoneBurner Data Sync app connected to HubSpot, PhoneBurner may
// sync the primary phone number back to the HubSpot contact's "Phone Number" field. This
// means whatever we send as the primary `phone` in the PB payload can overwrite the value
// in HubSpot. To avoid this, customers should disable phone number syncing in the Data Sync
// app and rely on this extension to feed phone numbers into PhoneBurner dial sessions.
//
// Accepts: { client_id, list_id, portal_id, object_type }
//   - object_type: "contacts" or "companies"
// Returns: { ok, session_token, temp_code, launch_url, contacts_sent, skipped, ... }

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/hs_helpers.php';

// PhoneBurner maximum contacts per dial session
define('PB_MAX_CONTACTS', 500);

// -----------------------------------------------------------------------------
// Input + tokens
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);
$member_user_id = resolve_member_user_id_for_client($client_id);
rate_limit_or_fail($client_id, 30); // Stricter limit — list launches are heavier

$listId    = trim((string)($data['list_id'] ?? ''));
$portalId  = trim((string)($data['portal_id'] ?? ''));
$objectType = trim((string)($data['object_type'] ?? 'contacts'));

if ($listId === '') {
  api_error('list_id is required', 'bad_request', 400);
}

// Sanitize list_id — HubSpot list IDs are numeric strings
$listId = preg_replace('/[^a-zA-Z0-9_-]/', '', $listId);
if ($listId === '') {
  api_error('Invalid list_id', 'bad_request', 400);
}

if (!in_array($objectType, ['contacts', 'companies'], true)) {
  api_error('object_type must be "contacts" or "companies"', 'bad_request', 400);
}

$isCompanyList = ($objectType === 'companies');

// Load PhoneBurner PAT
$pat = load_pb_token($client_id);
if (!$pat) {
  api_error('No PhoneBurner PAT saved for this client_id', 'unauthorized', 401);
}

// Load HubSpot tokens
$hs = load_hs_tokens($client_id);
if (!is_array($hs)) {
  api_error('No HubSpot tokens saved for this client_id', 'unauthorized', 401);
}

if (hs_token_is_expired($hs)) {
  $hs = hs_refresh_access_token_or_fail($client_id, $hs);
}

$hsAccess = (string)($hs['access_token'] ?? '');
if ($hsAccess === '') {
  api_error('No HubSpot access token available', 'unauthorized', 401);
}

$hubId = (string)($hs['hub_id'] ?? '');

// -----------------------------------------------------------------------------
// Fetch list memberships (paginated, up to PB_MAX_CONTACTS)
// -----------------------------------------------------------------------------
$diag = [
  'list_id'     => $listId,
  'object_type' => $objectType,
];

$memberIds = [];
$after = null;
$totalFetched = 0;
$pageLimit = 100; // HubSpot default page size

while (count($memberIds) < PB_MAX_CONTACTS) {
  $url = 'https://api.hubapi.com/crm/v3/lists/' . rawurlencode($listId) . '/memberships'
       . '?limit=' . $pageLimit;
  if ($after !== null) {
    $url .= '&after=' . rawurlencode($after);
  }

  list($code, $json, $_raw) = hs_api_get_json($hsAccess, $url);

  // Retry once on 401
  if ($code === 401) {
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = (string)($hs['access_token'] ?? '');
    list($code, $json, $_raw) = hs_api_get_json($hsAccess, $url);
  }

  if ($code === 404) {
    api_error('List not found in HubSpot', 'not_found', 404, ['list_id' => $listId]);
  }

  if ($code !== 200 || !is_array($json)) {
    api_log('hs_list_members.fetch_fail', [
      'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
      'list_id'        => $listId,
      'http_code'      => $code,
      'page'           => $totalFetched,
    ]);
    api_error('Failed to fetch list memberships from HubSpot', 'hs_error', 502, [
      'http_code' => $code,
    ]);
  }

  $results = $json['results'] ?? [];
  if (!is_array($results) || empty($results)) {
    break; // No more members
  }

  foreach ($results as $member) {
    if (count($memberIds) >= PB_MAX_CONTACTS) break;

    // Member records contain recordId
    $rid = (string)($member['recordId'] ?? $member['id'] ?? '');
    if ($rid !== '') {
      $memberIds[$rid] = true; // Deduplicate
    }
  }

  $totalFetched += count($results);

  // Check for next page
  $nextAfter = $json['paging']['next']['after'] ?? null;
  if ($nextAfter === null || $nextAfter === $after) {
    break; // No more pages
  }
  $after = $nextAfter;
}

$memberIds = array_keys($memberIds);
$truncated = ($totalFetched > PB_MAX_CONTACTS);

$diag['members_fetched'] = count($memberIds);
$diag['total_in_list']   = $totalFetched;
$diag['truncated']       = $truncated;

if (empty($memberIds)) {
  api_error('This list has no members', 'bad_request', 400, [
    'list_id' => $listId,
  ]);
}

// -----------------------------------------------------------------------------
// Fetch record details from HubSpot
// -----------------------------------------------------------------------------
$hsRecords = [];

if ($isCompanyList) {
  $phoneProps = hs_discover_phone_properties($hsAccess, 'companies', $hubId);
  $hsRecords = hs_fetch_companies_with_refresh_retry($client_id, $hs, $hsAccess, $memberIds, $phoneProps, $diag);
} else {
  $phoneProps = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
  $hsRecords = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $memberIds, $phoneProps, $diag);
}

$diag['phone_props'] = array_column($phoneProps, 'name');

if (empty($hsRecords)) {
  api_error('No records returned from HubSpot for this list', 'bad_request', 400, $diag);
}

// -----------------------------------------------------------------------------
// Normalize into PhoneBurner dialsession payload
// (Same normalization logic as pb_dialsession_selection.php)
// -----------------------------------------------------------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];
$skipped      = 0;

foreach ($hsRecords as $c) {
  $additionalPhones = $c['additional_phones'] ?? [];

  if ($isCompanyList) {
    // Company normalization
    $name  = trim((string)($c['name'] ?? ''));
    $first = $name;
    $last  = '';
    $email = '';
    $phone = trim((string)($c['phone'] ?? ''));
    $hsId  = (string)($c['hs_id'] ?? '');

    if ($hsId === '') { $skipped++; continue; }
    if ($phone === '') { $skipped++; continue; }

    $recordUrl = ($portalId !== '')
      ? ('https://app.hubspot.com/contacts/' . rawurlencode($portalId) . '/record/0-2/' . rawurlencode($hsId))
      : null;
  } else {
    // Contact normalization
    $first = trim((string)($c['first_name'] ?? ''));
    $last  = trim((string)($c['last_name'] ?? ''));
    $email = trim((string)($c['email'] ?? ''));
    $phone = trim((string)($c['phone'] ?? ''));
    $hsId  = (string)($c['hs_id'] ?? '');

    if ($hsId === '') { $skipped++; continue; }
    if ($phone === '') { $skipped++; continue; }

    $recordUrl = ($portalId !== '')
      ? ('https://app.hubspot.com/contacts/' . rawurlencode($portalId) . '/record/0-1/' . rawurlencode($hsId))
      : null;
  }

  // CRM ID uniqueness: companies get prefixed to prevent merging with contacts
  $externalId = $isCompanyList ? ('HS Company ' . $hsId) : $hsId;

  $externalCrmData = [];
  $externalCrmData[] = [
    'crm_id'   => $externalId,
    'crm_name' => $isCompanyList ? 'hubspotcompany' : 'hubspot',
  ];

  $pbContact = [
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone ?: null,
    'email'             => $email ?: null,
    'external_crm_data' => $externalCrmData,
  ];
  if (!empty($additionalPhones)) {
    $pbContact['additional_phone'] = $additionalPhones;
  }
  $pbContacts[] = $pbContact;

  $displayName = trim(($first !== '' || $last !== '') ? ($first . ' ' . $last) : '');

  $contacts_map[$externalId] = [
    'name'           => $displayName,
    'first_name'     => $first,
    'last_name'      => $last,
    'phone'          => $phone,
    'email'          => $email,

    'source_url'     => null,
    'source_label'   => 'HubSpot List',

    'crm_name'       => $isCompanyList ? 'hubspotcompany' : 'hubspot',
    'crm_identifier' => $externalId,

    'record_url'     => $recordUrl ?: null,
  ];
}

if (empty($pbContacts)) {
  $noun = $isCompanyList ? 'companies' : 'contacts';
  api_error("No dialable {$noun} after normalization", 'bad_request', 400, [
    'skipped'    => $skipped,
    'hs_records' => count($hsRecords),
    'reason'     => 'missing phone numbers',
  ]);
}

// -----------------------------------------------------------------------------
// Create PhoneBurner dial session
// -----------------------------------------------------------------------------
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

$sessionLabel = $isCompanyList ? 'HubSpot Companies List' : 'HubSpot Contacts List';

$payload = [
  'name'        => $sessionLabel . ' - ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id' => $client_id,
    'source'    => 'hubspot-list',
    'crm_name'  => 'hubspot',
    'list_id'   => $listId,
  ],
  'callbacks'    => $callbacks,
  'webhook_meta' => [
    'session_token' => $session_token,
    'client_id'     => $client_id,
    'crm_name'      => 'hubspot',
  ],
];

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

// -----------------------------------------------------------------------------
// Extract launch URL
// -----------------------------------------------------------------------------
$launch_url = $resp['dialsessions']['redirect_url'] ?? null;
$dial_id    = $resp['dialsessions']['id'] ?? null;

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
  api_log('hubspot_list_dial.error.no_launch_url', [
    'client_id_hash'   => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ms'            => $pb_ms,
    'pb_http'          => $httpCode,
    'resp_keys'        => is_array($resp) ? array_slice(array_keys($resp), 0, 30) : null,
    'has_dialsessions' => isset($resp['dialsessions']),
  ]);

  api_error('PhoneBurner response missing launch URL', 'pb_error', 502, [
    'pb_ms' => $pb_ms,
  ]);
}

// -----------------------------------------------------------------------------
// Save session state
// -----------------------------------------------------------------------------
$state = [
  'session_token'   => $session_token,
  'dialsession_id'  => $resp['dialsessions']['id'] ?? ($resp['dialsession_id'] ?? null),
  'dialsession_url' => $launch_url,

  'client_id'       => $client_id,
  'member_user_id'  => $member_user_id,
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

// -----------------------------------------------------------------------------
// Generate temporary code for secure URL
// -----------------------------------------------------------------------------
$tempCode = temp_code_store($session_token, 300);

$response = [
  'session_token'   => $session_token,
  'temp_code'       => $tempCode,
  'dialsession_url' => $launch_url,
  'launch_url'      => $launch_url . (strpos($launch_url, '?') ? '&' : '?') . 'code=' . urlencode($tempCode),
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
  'truncated'       => $truncated,
  'total_in_list'   => $totalFetched,
];

if ($skipped > 0) {
  $noun = $isCompanyList ? 'companies' : 'contacts';
  $total = count($pbContacts) + $skipped;
  $response['success_message'] = "Created dial session with " . count($pbContacts) . " of {$total} {$noun} (skipped {$skipped} without phone)";
}

if ($truncated) {
  $response['truncation_message'] = "List has {$totalFetched} members. PhoneBurner limit is " . PB_MAX_CONTACTS . " — the first " . PB_MAX_CONTACTS . " were used.";
}

api_ok_flat($response);
