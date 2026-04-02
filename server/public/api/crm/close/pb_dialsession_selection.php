<?php
// server/public/api/crm/close/pb_dialsession_selection.php
//
// Creates a PhoneBurner dial session from Close contact IDs sent by the extension.
// Fetches full contact data from Close API (phones, emails) then creates PB session.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/close_helpers.php';

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

$closeTokens = load_close_tokens($client_id);
if (!is_array($closeTokens)) {
  api_error('No Close tokens saved for this client_id', 'unauthorized', 401);
}

if (close_token_is_expired($closeTokens)) {
  $closeTokens = close_refresh_access_token_or_fail($client_id, $closeTokens);
}

$accessToken = (string)($closeTokens['access_token'] ?? '');
if ($accessToken === '') {
  api_error('No Close access token available', 'unauthorized', 401);
}

$contactIds = $data['contact_ids'] ?? [];
$leadIds    = $data['lead_ids'] ?? [];
$context    = $data['context'] ?? [];

if (!is_array($contactIds)) $contactIds = [];
if (!is_array($leadIds)) $leadIds = [];

// Sanitize contact IDs (Close format: cont_XXXXX)
$contactIds = array_values(array_filter(array_map(function($id) {
  $id = trim((string)$id);
  return preg_match('/^cont_[a-zA-Z0-9]+$/', $id) ? $id : '';
}, $contactIds)));

// Sanitize lead IDs (Close format: lead_XXXXX)
$leadIds = array_values(array_filter(array_map(function($id) {
  $id = trim((string)$id);
  return preg_match('/^lead_[a-zA-Z0-9]+$/', $id) ? $id : '';
}, $leadIds)));

if (empty($contactIds) && empty($leadIds)) {
  api_error('No contact or lead IDs provided', 'bad_request', 400);
}

// -----------------------------------------------------------------------------
// If we have lead IDs (from /leads/ page), resolve to contact IDs via Close API
// -----------------------------------------------------------------------------
if (!empty($leadIds) && empty($contactIds)) {
  $resolvedContactIds = [];
  foreach ($leadIds as $lid) {
    if (count($resolvedContactIds) >= 500) break;

    $url = 'https://api.close.com/api/v1/contact/?lead_id=' . rawurlencode($lid);
    list($code, $json, $_raw) = close_api_get_json($accessToken, $url);

    // Retry once on 401
    if ($code === 401) {
      $closeTokens = close_refresh_access_token_or_fail($client_id, $closeTokens);
      $accessToken = (string)($closeTokens['access_token'] ?? '');
      list($code, $json, $_raw) = close_api_get_json($accessToken, $url);
    }

    if ($code === 200 && is_array($json) && isset($json['data'])) {
      foreach ($json['data'] as $contact) {
        $cid = (string)($contact['id'] ?? '');
        if ($cid !== '' && count($resolvedContactIds) < 500) {
          $resolvedContactIds[] = $cid;
        }
      }
    }
  }
  $contactIds = $resolvedContactIds;
}

if (empty($contactIds)) {
  api_error('No contacts found for the selected leads', 'bad_request', 400, [
    'lead_ids_provided' => count($leadIds),
  ]);
}

// Cap at 500 contacts
if (count($contactIds) > 500) {
  $contactIds = array_slice($contactIds, 0, 500);
}

// -----------------------------------------------------------------------------
// Fetch full contact details from Close API
// -----------------------------------------------------------------------------
$diag = [
  'selected_contact_ids' => count($contactIds),
  'lead_ids_resolved' => !empty($leadIds) ? count($leadIds) : 0,
];

$closeContacts = close_fetch_contacts_with_refresh_retry(
  $client_id, $closeTokens, $accessToken, $contactIds, $diag
);

if (empty($closeContacts)) {
  api_error('No contacts returned from Close API', 'bad_request', 400, $diag);
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

foreach ($closeContacts as $c) {
  $first   = trim((string)($c['first_name'] ?? ''));
  $last    = trim((string)($c['last_name'] ?? ''));
  $email   = trim((string)($c['email'] ?? ''));
  $phone   = trim((string)($c['phone'] ?? ''));
  $closeId = (string)($c['close_id'] ?? '');
  $leadId  = (string)($c['lead_id'] ?? '');

  if ($closeId === '') { $skipped++; continue; }
  if ($phone === '') { $skipped++; continue; }

  // Build record URL pointing to the lead page with contact context
  $recordUrl = ($leadId !== '')
    ? ('https://app.close.com/lead/' . rawurlencode($leadId) . '/#contactId=' . rawurlencode($closeId))
    : null;

  $externalCrmData = [
    [
      'crm_id'   => $closeId,
      'crm_name' => 'close',
    ],
  ];

  $pbContact = [
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone ?: null,
    'email'             => $email ?: null,
    'external_crm_data' => $externalCrmData,
  ];

  $additionalPhones = $c['additional_phones'] ?? [];
  if (!empty($additionalPhones)) {
    $pbContact['additional_phone'] = $additionalPhones;
  }

  $pbContacts[] = $pbContact;

  $displayName = trim(($first !== '' || $last !== '') ? ($first . ' ' . $last) : '');

  $contacts_map[$closeId] = [
    'name'           => $displayName,
    'first_name'     => $first,
    'last_name'      => $last,
    'phone'          => $phone,
    'email'          => $email,
    'source_url'     => $sourceUrl ?: null,
    'source_label'   => $sourceLabel ?: null,
    'crm_name'       => 'close',
    'crm_identifier' => $closeId,
    'record_url'     => $recordUrl,
  ];
}

if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, [
    'skipped'        => $skipped,
    'close_contacts' => count($closeContacts),
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
  'name'        => 'Close – ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id' => $client_id,
    'source'    => 'close-selection',
    'crm_name'  => 'close',
  ],
  'callbacks'    => $callbacks,
  'webhook_meta' => [
    'session_token' => $session_token,
    'client_id'     => $client_id,
    'crm_name'      => 'close',
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
  api_log('close_selection.error.no_launch_url', [
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
  'crm_name'        => 'close',
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
