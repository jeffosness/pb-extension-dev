<?php
// server/public/api/crm/hubspot/pb_dialsession_from_tasks.php
//
// Creates a PhoneBurner dial session from a list of HubSpot task IDs.
// Used by the extension's Task Queue feature — the content script extracts
// task IDs from the HubSpot tasks page DOM (typically the user's filtered
// queue view) and the user clicks "Dial This View" to launch a dial session
// for all contacts associated with those tasks.
//
// IMPORTANT — PhoneBurner ↔ HubSpot Data Sync caveat:
// If a customer has the PhoneBurner Data Sync app connected to HubSpot, PB may
// sync the primary phone number back to the HubSpot contact's "Phone Number"
// field. To avoid that, customers should disable phone number syncing in the
// Data Sync app. (Same caveat as the other dial session endpoints.)
//
// Accepts: { client_id, task_ids[], portal_id, hs_host }
// Returns: { ok, session_token, temp_code, launch_url, contacts_sent,
//            tasks_processed, tasks_without_contact, skipped, diag }
//
// contacts_map stores `hs_task_ids` (array — a single contact can be
// associated with multiple tasks) so the call_done webhook handler can mark
// all related tasks complete after the call lands. See hs_call_logger.php
// (added in a follow-up PR).

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/hs_helpers.php';

define('PB_MAX_CONTACTS', 500);

// -----------------------------------------------------------------------------
// Input + tokens
// -----------------------------------------------------------------------------
$data           = json_input();
$client_id      = get_client_id_or_fail($data);
$member_user_id = resolve_member_user_id_for_client($client_id);
rate_limit_or_fail($client_id, 30);

$taskIdsRaw = $data['task_ids'] ?? [];
if (!is_array($taskIdsRaw) || empty($taskIdsRaw)) {
  api_error('task_ids must be a non-empty array', 'bad_request', 400);
}

// Sanitize task IDs — HubSpot task IDs are numeric strings.
// Deduplicate via array_keys trick so the same task ID submitted twice
// (which can happen if the UI scrapes overlapping rows) doesn't double-process.
$taskIds = [];
foreach ($taskIdsRaw as $tid) {
  $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)$tid));
  if ($clean !== '') $taskIds[$clean] = true;
}
$taskIds = array_keys($taskIds);
if (empty($taskIds)) {
  api_error('No valid task_ids provided', 'bad_request', 400);
}

$portalId = trim((string)($data['portal_id'] ?? ''));

// HubSpot regional subdomain support (e.g., app.na2.hubspot.com)
$hsHost = 'app.hubspot.com';
if (!empty($data['hs_host']) && is_string($data['hs_host'])) {
  $candidate = strtolower(trim($data['hs_host']));
  if (preg_match('/^app(\.[a-z0-9-]+)?\.hubspot\.com$/', $candidate)) {
    $hsHost = $candidate;
  }
}

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

$hubId = $hs['hub_id'] ?? null;

// -----------------------------------------------------------------------------
// Fetch task details with contact associations
// -----------------------------------------------------------------------------
$diag = ['tasks_input' => count($taskIds)];

$tasks = hs_fetch_tasks_with_refresh_retry(
  $client_id,
  $hs,
  $hsAccess,
  $taskIds,
  $diag
);

if (empty($tasks)) {
  api_error('Failed to fetch task details from HubSpot', 'api_error', 502, $diag);
}

$diag['tasks_fetched'] = count($tasks);

// Build map: contact_id => [task_ids...]
// A single contact may be associated with multiple tasks (e.g., two open
// call tasks for the same person). We dial the contact once and mark ALL
// their associated tasks complete in the call_done handler.
$contactTaskMap     = [];
$tasksWithoutContact = 0;

foreach ($tasks as $task) {
  $taskId = (string)($task['id'] ?? '');
  if ($taskId === '') continue;

  $associations = $task['associations']['contacts']['results'] ?? [];
  if (!is_array($associations) || empty($associations)) {
    $tasksWithoutContact++;
    continue;
  }

  foreach ($associations as $assoc) {
    $contactId = (string)($assoc['id'] ?? '');
    if ($contactId === '') continue;
    if (!isset($contactTaskMap[$contactId])) {
      $contactTaskMap[$contactId] = [];
    }
    $contactTaskMap[$contactId][] = $taskId;
  }
}

$diag['tasks_without_contact']     = $tasksWithoutContact;
$diag['unique_contacts_from_tasks'] = count($contactTaskMap);

if (empty($contactTaskMap)) {
  // Log explicitly so the diag is grep-able in app.log.
  api_log('hubspot_tasks_dial.error.no_contacts', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'diag'           => $diag,
  ]);
  api_error('No contacts found in associated tasks', 'bad_request', 400, $diag);
}

// Cap at PB's session limit
$uniqueContactIds = array_keys($contactTaskMap);
$truncatedCount   = 0;
if (count($uniqueContactIds) > PB_MAX_CONTACTS) {
  $truncatedCount   = count($uniqueContactIds) - PB_MAX_CONTACTS;
  $uniqueContactIds = array_slice($uniqueContactIds, 0, PB_MAX_CONTACTS);
  $diag['truncated_contacts'] = $truncatedCount;
}

// -----------------------------------------------------------------------------
// Fetch contact details (reuse existing helper with refresh retry)
// -----------------------------------------------------------------------------
// Load user's preferred primary phone field
$preferredPhoneProp = '';
if ($member_user_id) {
  $userSettings = load_user_settings($member_user_id);
  $preferredPhoneProp = trim((string)($userSettings['crm_preferences']['hubspot']['preferred_phone_property_contacts'] ?? ''));
}

// Discover available phone properties so the existing helper can resolve
// primary + additional phones with the user's preferred property in mind.
$phonePropsContacts = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);

$hsContacts = hs_fetch_contacts_with_refresh_retry(
  $client_id,
  $hs,
  $hsAccess,
  $uniqueContactIds,
  $phonePropsContacts,
  $diag,
  $preferredPhoneProp
);

if (empty($hsContacts)) {
  api_error('Failed to fetch contact details from HubSpot', 'api_error', 502, $diag);
}

// -----------------------------------------------------------------------------
// Normalize to PhoneBurner contacts
// -----------------------------------------------------------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];
$skipped      = 0;

foreach ($hsContacts as $c) {
  $first = trim((string)($c['first_name'] ?? ''));
  $last  = trim((string)($c['last_name'] ?? ''));
  $email = trim((string)($c['email'] ?? ''));
  $phone = trim((string)($c['phone'] ?? ''));
  $hsId  = (string)($c['hs_id'] ?? '');

  if ($hsId === '') { $skipped++; continue; }
  if ($phone === '') { $skipped++; continue; }

  $additionalPhones = $c['additional_phones'] ?? [];
  $relatedTaskIds   = $contactTaskMap[$hsId] ?? [];

  // Record URL for follow-me navigation (0-1 = contact object type)
  $recordUrl = ($portalId !== '')
    ? ('https://' . $hsHost . '/contacts/' . rawurlencode($portalId) . '/record/0-1/' . rawurlencode($hsId))
    : null;

  // Only the contact's own crm_id is sent. No related-company breadcrumb —
  // see CLAUDE.md "CRM ID Uniqueness Across Object Types" for why.
  $externalCrmData = [
    [
      'crm_id'   => $hsId,
      'crm_name' => 'hubspot',
    ],
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

  // contacts_map keyed by hs_id (matches what PB sends back in webhooks).
  // hs_task_ids is the new field consumed by the call_done auto-complete
  // logic in hs_call_logger.php (follow-up PR).
  $contacts_map[$hsId] = [
    'name'           => $displayName,
    'first_name'     => $first,
    'last_name'      => $last,
    'phone'          => $phone,
    'email'          => $email,

    'source_url'     => null,
    'source_label'   => 'HubSpot Task Queue',

    'crm_name'       => 'hubspot',
    'crm_identifier' => $hsId,

    'record_url'     => $recordUrl ?: null,

    'hs_task_ids'    => $relatedTaskIds,
  ];
}

$diag['contacts_normalized'] = count($pbContacts);
$diag['skipped']             = $skipped;

if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, $diag);
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

$payload = [
  'name'        => 'HubSpot Task Queue - ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id' => $client_id,
    'source'    => 'hubspot-task-queue',
    'crm_name'  => 'hubspot',
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
// Extract launch URL (mirrors the resilient pattern used by other endpoints)
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
  $dial_id = $dial_id ?? ($resp['dialsession']['id'] ?? null);
}

if (!$launch_url) {
  api_log('hubspot_tasks_dial.error.no_launch_url', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ms'          => $pb_ms,
    'pb_http'        => $httpCode,
    'resp_keys'      => is_array($resp) ? array_slice(array_keys($resp), 0, 30) : null,
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

  // Signals to hs_call_logger.php (added in follow-up PR) that we should
  // auto-complete the hs_task_ids associated with each contact after their
  // call_done webhook lands. Other HubSpot session types (selection, list)
  // don't have this field, so call logger won't try to complete tasks for them.
  'launch_source'   => 'queue-tasks',
];

save_session_state($session_token, $state);

// -----------------------------------------------------------------------------
// Generate temporary single-use code for SSE URL (security: don't put
// session_token in URLs that go to the browser)
// -----------------------------------------------------------------------------
$temp_code = temp_code_store($session_token, 300);

api_log('hubspot_tasks_dial.ok', [
  'client_id_hash'        => substr(hash('sha256', (string)$client_id), 0, 12),
  'tasks_input'           => count($taskIds),
  'tasks_fetched'         => count($tasks),
  'unique_contacts'       => count($contactTaskMap),
  'pb_contacts_sent'      => count($pbContacts),
  'tasks_without_contact' => $tasksWithoutContact,
  'truncated_contacts'    => $truncatedCount,
  'skipped'               => $skipped,
  'pb_ms'                 => $pb_ms,
]);

// Build the launch_url with the temp_code appended as a query param.
// The PhoneBurner dialer page passes URL params through to the content
// script, which reads `code` to establish the SSE connection (the code is
// single-use and exchanged for the session_token server-side). Mirrors the
// pattern used by pb_dialsession_from_list.php and pb_dialsession_selection.php.
$launch_url_with_code = $launch_url
  . (strpos($launch_url, '?') !== false ? '&' : '?')
  . 'code=' . urlencode($temp_code);

$response = [
  'session_token'         => $session_token,
  'temp_code'             => $temp_code,
  'dialsession_url'       => $launch_url,           // raw PB URL (no code)
  'launch_url'            => $launch_url_with_code, // PB URL with ?code=… appended
  'dialsession_id'        => $dial_id,
  'contacts_sent'         => count($pbContacts),
  'tasks_processed'       => count($tasks),
  'tasks_without_contact' => $tasksWithoutContact,
  'truncated_contacts'    => $truncatedCount,
  'skipped'               => $skipped,
  'pb_ms'                 => $pb_ms,
];

if ($skipped > 0) {
  $total = count($pbContacts) + $skipped;
  $response['success_message'] = "Created dial session with " . count($pbContacts) . " of {$total} contacts (skipped {$skipped} without phone)";
}

if ($truncatedCount > 0) {
  $totalContacts = count($pbContacts) + $truncatedCount;
  $response['truncation_message'] = "Tasks reference {$totalContacts} unique contacts. PhoneBurner limit is " . PB_MAX_CONTACTS . " — the first " . PB_MAX_CONTACTS . " were used.";
}

api_ok_flat($response);
