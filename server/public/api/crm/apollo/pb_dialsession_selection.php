<?php
// server/public/api/crm/apollo/pb_dialsession_selection.php
//
// Creates a PhoneBurner dial session from Apollo contact IDs sent by the extension.
// Used when user selects contacts on the Apollo People table page.
// Fetches full contact data from Apollo API (phones, emails) then creates PB session.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/apollo_helpers.php';

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

$tokens = load_apollo_tokens($client_id);
if (!is_array($tokens)) {
  api_error('No Apollo tokens saved for this client_id', 'unauthorized', 401);
}

$accessToken = apollo_ensure_access_token($client_id, $tokens);

$contactIds = $data['contact_ids'] ?? [];
$context    = $data['context'] ?? [];

if (!is_array($contactIds)) $contactIds = [];

// Sanitize contact IDs (Apollo format: 24-char hex)
$contactIds = array_values(array_filter(array_map(function($id) {
  $id = trim((string)$id);
  return preg_match('/^[a-f0-9]{24}$/', $id) ? $id : '';
}, $contactIds)));

if (empty($contactIds)) {
  api_error('No contact IDs provided', 'bad_request', 400);
}

// Cap at 500 contacts
if (count($contactIds) > 500) {
  $contactIds = array_slice($contactIds, 0, 500);
}

// -----------------------------------------------------------------------------
// Fetch full contact details from Apollo API
// -----------------------------------------------------------------------------
$diag = [
  'selected_contact_ids' => count($contactIds),
  'first_ids' => array_slice($contactIds, 0, 3),
];

api_log('apollo_selection.fetching', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  'contact_count' => count($contactIds),
  'first_ids' => array_slice($contactIds, 0, 3),
]);

$apolloContacts = apollo_fetch_contacts_with_refresh_retry(
  $client_id, $tokens, $accessToken, $contactIds, $diag
);

api_log('apollo_selection.fetch_result', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  'contacts_returned' => count($apolloContacts),
  'diag' => $diag,
]);

if (empty($apolloContacts)) {
  api_error('No contacts returned from Apollo API', 'bad_request', 400, $diag);
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

foreach ($apolloContacts as $c) {
  $first    = trim((string)($c['first_name'] ?? ''));
  $last     = trim((string)($c['last_name'] ?? ''));
  $email    = trim((string)($c['email'] ?? ''));
  $phone    = trim((string)($c['phone'] ?? ''));
  $apolloId = (string)($c['apollo_id'] ?? '');

  if ($apolloId === '') { $skipped++; continue; }
  if ($phone === '') { $skipped++; continue; }

  $recordUrl = 'https://app.apollo.io/#/contacts/' . rawurlencode($apolloId);

  $externalCrmData = [
    [
      'crm_id'   => $apolloId,
      'crm_name' => 'apollo',
    ],
  ];

  $pbContact = [
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone ?: null,
    'email'             => $email ?: null,
    'external_crm_data' => $externalCrmData,
    'external_id'       => $apolloId,
  ];

  $additionalPhones = $c['additional_phones'] ?? [];
  if (!empty($additionalPhones)) {
    $pbContact['additional_phone'] = $additionalPhones;
  }

  $pbContacts[] = $pbContact;

  $displayName = trim(($first !== '' || $last !== '') ? ($first . ' ' . $last) : '');

  $contacts_map[$apolloId] = [
    'name'              => $displayName,
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone,
    'email'             => $email,
    'source_url'        => $sourceUrl ?: null,
    'source_label'      => $sourceLabel ?: null,
    'crm_name'          => 'apollo',
    'crm_identifier'    => $apolloId,
    'record_url'        => $recordUrl,
  ];
}

if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, [
    'skipped'          => $skipped,
    'apollo_contacts'  => count($apolloContacts),
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
  'name'        => 'Apollo – ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id' => $client_id,
    'source'    => 'apollo-selection',
    'crm_name'  => 'apollo',
  ],
  'callbacks'    => $callbacks,
  'webhook_meta' => [
    'session_token' => $session_token,
    'client_id'     => $client_id,
    'crm_name'      => 'apollo',
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
  api_log('apollo_selection.error.no_launch_url', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ms'          => $pb_ms,
    'pb_http'        => $httpCode,
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
  'crm_name'        => 'apollo',
];

save_session_state($session_token, $state);

$tempCode = temp_code_store($session_token, 300);

api_ok_flat([
  'session_token'   => $session_token,
  'temp_code'       => $tempCode,
  'dialsession_url' => $launch_url,
  'launch_url'      => $launch_url . (strpos($launch_url, '?') ? '&' : '?') . 'code=' . urlencode($tempCode),
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
]);
