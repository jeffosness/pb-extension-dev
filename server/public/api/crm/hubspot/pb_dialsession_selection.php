<?php
// server/public/api/crm/hubspot/pb_dialsession_selection.php
//
// Creates a PhoneBurner dial session from a HubSpot selection (IDs) sent by the extension.
//
// IMPORTANT — PhoneBurner ↔ HubSpot Data Sync caveat:
// If a customer has the PhoneBurner Data Sync app connected to HubSpot, PhoneBurner may
// sync the primary phone number back to the HubSpot contact's "Phone Number" field. This
// means whatever we send as the primary `phone` in the PB payload can overwrite the value
// in HubSpot. To avoid this, customers should disable phone number syncing in the Data Sync
// app and rely on this extension to feed phone numbers into PhoneBurner dial sessions.
//
// Unified parity with /api/crm/generic/dialsession_from_scan.php:
// - json_input() / get_client_id_or_fail()
// - load_pb_token($client_id)
// - load_hs_tokens($client_id)
// - save_session_state(session_token, ...)
// - api_ok_flat([session_token, dialsession_url, ...])

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/hs_helpers.php';

// -----------------------------------------------------------------------------
// Input + tokens (Unified)
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);
$member_user_id = resolve_member_user_id_for_client($client_id);

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

$hubId = (string)($hs['hub_id'] ?? '');

$mode       = (string)($data['mode'] ?? 'contacts');
$callTarget = $data['call_target'] ?? null; // NEW: For company dual-mode
$records    = $data['records'] ?? [];
$context    = $data['context'] ?? [];

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
  $phonePropsContacts = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
  $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $ids, $phonePropsContacts, $diag);

} elseif ($mode === 'deals') {
  $sourceObjectType = 'deals';
  list($contactIds, $map) = hs_resolve_contact_ids_map_from_objects($hsAccess, 'deals', $ids, $diag);
  $diag['resolved_contact_ids'] = count($contactIds);

  $sourceObjectIdsByContact = is_array($map) ? $map : [];

  $phonePropsContacts = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
  $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $contactIds, $phonePropsContacts, $diag);

} elseif ($mode === 'companies') {
  $sourceObjectType = 'companies';

  // NEW: Branch based on call_target
  if ($callTarget === 'companies') {
    // **NEW PATH: Dial companies directly**
    $diag['call_target'] = 'companies';
    $diag['resolved_company_ids'] = count($ids);

    // Fetch company details (not contacts)
    $phonePropsCompanies = hs_discover_phone_properties($hsAccess, 'companies', $hubId);
    $hsCompanies = hs_fetch_companies_with_refresh_retry($client_id, $hs, $hsAccess, $ids, $phonePropsCompanies, $diag);

    // Diagnostic: Log raw company data (redacted)
    $diag['companies_raw_sample'] = !empty($hsCompanies) ? array_map(function($c) {
      return [
        'has_hs_id' => !empty($c['hs_id']),
        'has_name' => !empty($c['name']),
        'has_phone' => !empty($c['phone']),
        'phone_value' => isset($c['phone']) ? (empty(trim($c['phone'])) ? '(empty)' : '(has value)') : '(not set)',
      ];
    }, array_slice($hsCompanies, 0, 2)) : [];

    // Store companies in $hsContacts for compatibility with existing error checking
    // We'll normalize them differently in the normalization section
    $hsContacts = $hsCompanies;

  } else {
    // **EXISTING PATH: Dial contacts related to companies (backward compat)**
    $diag['call_target'] = 'contacts';
    list($contactIds, $map) = hs_resolve_contact_ids_map_from_objects($hsAccess, 'companies', $ids, $diag);
    $diag['resolved_contact_ids'] = count($contactIds);

    $sourceObjectIdsByContact = is_array($map) ? $map : [];

    $phonePropsContacts = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
    $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $contactIds, $phonePropsContacts, $diag);
  }

} else {
  api_error('Invalid mode', 'bad_request', 400);
}

// Diagnostic: log discovered phone property names (schema metadata, not PII)
$diag['phone_props'] = isset($phonePropsContacts)
  ? array_column($phonePropsContacts, 'name')
  : (isset($phonePropsCompanies) ? array_column($phonePropsCompanies, 'name') : []);

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
  // Handle companies vs contacts differently
  $additionalPhones = $c['additional_phones'] ?? [];

  if ($callTarget === 'companies') {
    // Company normalization
    $name  = trim((string)($c['name'] ?? ''));
    $first = $name;
    $last  = '';
    $email = ''; // Per user preference: phone-only for companies
    $phone = trim((string)($c['phone'] ?? ''));
    $hsId  = (string)($c['hs_id'] ?? '');

    if ($hsId === '') { $skipped++; continue; }
    if ($phone === '') { $skipped++; continue; } // Companies require phone

    // Record URL for follow-me (0-2 = company object type)
    $recordUrl = ($portalId !== '')
      ? ('https://app.hubspot.com/contacts/' . rawurlencode($portalId) . '/record/0-2/' . rawurlencode($hsId))
      : null;
  } else {
    // Contact normalization (existing)
    $first = trim((string)($c['first_name'] ?? ''));
    $last  = trim((string)($c['last_name'] ?? ''));
    $email = trim((string)($c['email'] ?? ''));
    $phone = trim((string)($c['phone'] ?? ''));
    $hsId  = (string)($c['hs_id'] ?? '');

    if ($hsId === '') { $skipped++; continue; }
    if ($phone === '') { $skipped++; continue; } // Phone required; email is optional

    // Record URL for follow-me (0-1 = contact object type)
    $recordUrl = ($portalId !== '')
      ? ('https://app.hubspot.com/contacts/' . rawurlencode($portalId) . '/record/0-1/' . rawurlencode($hsId))
      : null;
  }

  // We will use this as our internal key (NOT sent to PB as external_id)
  // For companies, use prefixed format to match the crm_id we send to PhoneBurner
  $externalId = ($callTarget === 'companies') ? ('HS Company ' . $hsId) : $hsId;

  // REQUIRED: external_crm_data must be an ARRAY of objects with crm_id + crm_name
  $externalCrmData = [];

  // Include the HubSpot identity (contact or company)
  // For companies, prefix ID with "HS Company " to prevent matching with existing contact records
  $externalCrmData[] = [
    'crm_id'   => ($callTarget === 'companies') ? ('HS Company ' . $hsId) : $hsId,
    'crm_name' => ($callTarget === 'companies') ? 'hubspotcompany' : 'hubspot',
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

  'source_url'     => $sourceUrl ?: null,
  'source_label'   => $sourceLabel ?: null,

  'crm_name'       => ($callTarget === 'companies') ? 'hubspotcompany' : 'hubspot',
  'crm_identifier' => $externalId,

  'record_url'     => $recordUrl ?: null,
];
}

// Add diagnostics for companies mode
if ($callTarget === 'companies') {
  $diag['companies_normalized'] = count($pbContacts);
  $diag['companies_skipped'] = $skipped;
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

// -------------------------
// Generate temporary code for secure URL (not embedding token)
// -------------------------
$tempCode = temp_code_store($session_token, 300);  // 5-minute TTL

// Unified-style response (flat keys)
$response = [
  'session_token'   => $session_token,
  'temp_code'       => $tempCode,
  'dialsession_url' => $launch_url,
  'launch_url'      => $launch_url . (strpos($launch_url, '?') ? '&' : '?') . 'code=' . urlencode($tempCode),
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
];

// Add success message for companies mode when some were skipped
if ($callTarget === 'companies' && $skipped > 0) {
  $total = count($pbContacts) + $skipped;
  $response['success_message'] = "Created dial session with " . count($pbContacts) . " of {$total} companies (skipped {$skipped} without phone)";
}

api_ok_flat($response);

