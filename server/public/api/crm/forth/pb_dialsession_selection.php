<?php
// server/public/api/crm/forth/pb_dialsession_selection.php
//
// Creates a PhoneBurner dial session from Forth contact IDs sent by the
// extension (numeric cid values from the contact-list page). Fetches full
// contact data from the Forth API, then creates the PB session.
//
// Mirrors close/pb_dialsession_selection.php. Auth = Api-Key (durable
// client_id/secret exchanged for a 10-day api_key). crm_name = 'forth' so
// call_done.php dispatches to forth_call_logger.php.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/forth_helpers.php';

// -----------------------------------------------------------------------------
// Input + tokens
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);
$member_user_id = resolve_member_user_id_for_client($client_id);

$pat = load_pb_token($client_id);
if (!$pat) {
  api_error('No PhoneBurner PAT saved for this client_id', 'unauthorized', 401);
}

$forthTokens = load_forth_tokens($client_id);
if (!is_array($forthTokens) || empty($forthTokens['client_id']) || empty($forthTokens['client_secret'])) {
  api_error('No Forth credentials saved for this client_id', 'unauthorized', 401);
}

// Ensure a valid api_key (mints/refreshes if expired). Bootstrap context here,
// so forth_get_access_token_or_fail (which uses api_error/api_log) is safe.
$apiKey = forth_get_access_token_or_fail($client_id, $forthTokens);

$contactIds = $data['contact_ids'] ?? [];
$context    = $data['context'] ?? [];
if (!is_array($contactIds)) $contactIds = [];

// Forth contact IDs are numeric.
$contactIds = array_values(array_filter(array_map(function ($id) {
  $id = trim((string)$id);
  return ctype_digit($id) ? $id : '';
}, $contactIds)));
$contactIds = array_values(array_unique($contactIds));

if (empty($contactIds)) {
  api_error('No contact IDs provided', 'bad_request', 400);
}

// Cap at 500 contacts (parity with other providers).
if (count($contactIds) > 500) {
  $contactIds = array_slice($contactIds, 0, 500);
}

// -----------------------------------------------------------------------------
// Fetch full contact details from Forth API (retry once on 401 via re-mint)
// -----------------------------------------------------------------------------
$diag = ['selected_contact_ids' => count($contactIds)];

$forthContacts = forth_fetch_contacts_by_ids($apiKey, $contactIds, $diag);

// A 401 across the board means the api_key lapsed mid-flight — re-mint once.
if (empty($forthContacts) && ($diag['contacts_fetch']['last_http'] ?? null) === 401) {
  $forthTokens = forth_mint_access_token_or_fail($client_id, $forthTokens);
  $apiKey = (string)($forthTokens['api_key'] ?? '');
  $forthContacts = forth_fetch_contacts_by_ids($apiKey, $contactIds, $diag);
}

if (empty($forthContacts)) {
  api_error('No contacts returned from Forth API', 'bad_request', 400, $diag);
}

// -----------------------------------------------------------------------------
// Normalize into PhoneBurner dialsession payload
// -----------------------------------------------------------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];
$skipped      = 0;

$sourceUrl   = (string)($context['url'] ?? '');
$sourceLabel = (string)($context['title'] ?? '');

foreach ($forthContacts as $c) {
  $first   = trim((string)($c['first_name'] ?? ''));
  $last    = trim((string)($c['last_name'] ?? ''));
  $email   = trim((string)($c['email'] ?? ''));
  $phone   = trim((string)($c['phone'] ?? ''));
  $forthId = (string)($c['forth_id'] ?? '');

  if ($forthId === '') { $skipped++; continue; }
  if ($phone === '')   { $skipped++; continue; }

  // Record URL → the contact's Forth dashboard (cid is the record identity).
  $recordUrl = 'https://client.forthcrm.com/index.php?module=contacts&page=view2&cid=' . rawurlencode($forthId);

  $externalCrmData = [
    [
      'crm_id'   => $forthId,
      'crm_name' => 'forth',
    ],
  ];

  $pbContact = [
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone ?: null,
    'email'             => $email ?: null,
    'external_id'       => $forthId,
    'external_crm_data' => $externalCrmData,
  ];

  $additionalPhones = $c['additional_phones'] ?? [];
  if (!empty($additionalPhones)) {
    $pbContact['additional_phone'] = $additionalPhones;
  }

  $pbContacts[] = $pbContact;

  $displayName = trim(($first !== '' || $last !== '') ? ($first . ' ' . $last) : '');

  // contacts_map key MUST equal the crm_id sent to PB (the Forth cid) so the
  // call_done webhook can find the called contact. See forth_call_logger.php.
  $contacts_map[$forthId] = [
    'name'           => $displayName,
    'first_name'     => $first,
    'last_name'      => $last,
    'phone'          => $phone,
    'email'          => $email,
    'source_url'     => $sourceUrl ?: null,
    'source_label'   => $sourceLabel ?: null,
    'crm_name'       => 'forth',
    'crm_identifier' => $forthId,
    'record_url'     => $recordUrl,
  ];
}

if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, [
    'skipped'        => $skipped,
    'forth_contacts' => count($forthContacts),
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

$payload = [
  'name'        => 'Forth – ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id' => $client_id,
    'source'    => 'forth-selection',
    'crm_name'  => 'forth',
  ],
  'callbacks'    => $callbacks,
  'webhook_meta' => [
    'session_token' => $session_token,
    'client_id'     => $client_id,
    'crm_name'      => 'forth',
  ],
];

$pbResult = pb_dialsession_or_fail($pat, $payload, 'forth_selection', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  'contact_count'  => count($pbContacts),
]);
$resp    = $pbResult['response'];
$pb_ms   = $pbResult['pb_ms'];
$pb_http = $pbResult['pb_http'];

// Extract launch URL
$launch_url = $resp['dialsessions']['redirect_url'] ?? null;
if (!$launch_url) {
  $launch_url =
    $resp['dialsession']['redirect_url'] ??
    $resp['dialsession']['launch_url'] ??
    $resp['redirect_url'] ??
    $resp['launch_url'] ??
    $resp['dialsession_url'] ??
    null;
}

if (!$launch_url) {
  api_log('forth_selection.error.no_launch_url', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ms'          => $pb_ms,
    'pb_http'        => $pb_http,
    'resp_keys'      => is_array($resp) ? array_slice(array_keys($resp), 0, 30) : null,
  ]);
  api_error('PhoneBurner response missing launch URL', 'pb_error', 502);
}

// Save session state
$state = [
  'session_token'   => $session_token,
  'dialsession_id'  => $resp['dialsessions']['id'] ?? null,
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
  'crm_name'        => 'forth',
];

save_session_state($session_token, $state);

$tempCode = temp_code_store($session_token, 300);

api_ok_flat([
  'session_token'   => $session_token,
  'temp_code'       => $tempCode,
  'dialsession_url' => $launch_url,
  'launch_url'      => $launch_url . (strpos($launch_url, '?') !== false ? '&' : '?') . 'code=' . urlencode($tempCode),
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
]);
