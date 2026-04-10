<?php
// server/public/api/crm/apollo/pb_dialsession_from_tasks.php
//
// Creates a PhoneBurner dial session from open call tasks in an Apollo sequence.
// This is the primary Apollo use case: "clear your call queue via PhoneBurner".
//
// Accepts: { client_id, sequence_id, filter: "due_today"|"due_and_overdue"|"all_open" }
// Returns: { session_token, temp_code, launch_url, contacts_sent, skipped }
//
// Key difference from selection: contacts_map stores BOTH contact_id AND task_id,
// because the call logger needs the task_id to complete the task and advance the sequence.

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

$sequenceId = trim((string)($data['sequence_id'] ?? ''));
if ($sequenceId === '' || !preg_match('/^[a-f0-9]{24}$/', $sequenceId)) {
  api_error('Invalid or missing sequence_id', 'bad_request', 400);
}

$filter = trim((string)($data['filter'] ?? 'all_open'));
if (!in_array($filter, ['due_today', 'due_and_overdue', 'all_open'], true)) {
  $filter = 'all_open';
}

// -----------------------------------------------------------------------------
// Fetch open call tasks for this sequence
// -----------------------------------------------------------------------------
$searchBody = [
  'per_page'             => 500,
  'open_factor_id'       => $sequenceId,
  'type'                 => 'make_call',
  'is_completed'         => false,
  'sort_by_key'          => 'due_date',
  'sort_ascending'       => true,
];

$today = gmdate('Y-m-d');
if ($filter === 'due_today') {
  $searchBody['due_date_range'] = [
    'min' => $today . 'T00:00:00Z',
    'max' => $today . 'T23:59:59Z',
  ];
} elseif ($filter === 'due_and_overdue') {
  $searchBody['due_date_range'] = [
    'max' => $today . 'T23:59:59Z',
  ];
}

list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://api.apollo.io/v1/tasks/search', $searchBody);

// Retry once on 401
if ($code === 401) {
  $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
  $accessToken = (string)($tokens['access_token'] ?? '');
  list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://api.apollo.io/v1/tasks/search', $searchBody);
}

if ($code !== 200 || !is_array($json)) {
  api_error('Failed to fetch Apollo tasks', 'api_error', 502, [
    'http_code' => $code,
  ]);
}

$taskItems = $json['tasks'] ?? $json['data'] ?? [];
if (!is_array($taskItems) || empty($taskItems)) {
  api_error('No open call tasks found for this sequence', 'bad_request', 400, [
    'filter'      => $filter,
    'sequence_id' => $sequenceId,
  ]);
}

// Build contact_id -> task_id mapping
$contactTaskMap = []; // apollo_contact_id => task_id
foreach ($taskItems as $task) {
  if (!is_array($task)) continue;
  $contactId = (string)($task['contact_id'] ?? $task['person_id'] ?? '');
  $taskId    = (string)($task['id'] ?? '');
  if ($contactId !== '' && $taskId !== '') {
    // Keep first task per contact (earliest due)
    if (!isset($contactTaskMap[$contactId])) {
      $contactTaskMap[$contactId] = $taskId;
    }
  }
}

if (empty($contactTaskMap)) {
  api_error('No contacts with call tasks found', 'bad_request', 400);
}

$contactIds = array_keys($contactTaskMap);

// Cap at 500
if (count($contactIds) > 500) {
  $contactIds = array_slice($contactIds, 0, 500);
}

// -----------------------------------------------------------------------------
// Fetch full contact details from Apollo API
// -----------------------------------------------------------------------------
$diag = [
  'tasks_found'  => count($taskItems),
  'contacts_to_fetch' => count($contactIds),
];

$apolloContacts = apollo_fetch_contacts_with_refresh_retry(
  $client_id, $tokens, $accessToken, $contactIds, $diag
);

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

foreach ($apolloContacts as $c) {
  $first    = trim((string)($c['first_name'] ?? ''));
  $last     = trim((string)($c['last_name'] ?? ''));
  $email    = trim((string)($c['email'] ?? ''));
  $phone    = trim((string)($c['phone'] ?? ''));
  $apolloId = (string)($c['apollo_id'] ?? '');

  if ($apolloId === '') { $skipped++; continue; }
  if ($phone === '') { $skipped++; continue; }

  $taskId = $contactTaskMap[$apolloId] ?? '';
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

  // CRITICAL: Store task_id alongside contact data for call logger
  $contacts_map[$apolloId] = [
    'name'              => $displayName,
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone,
    'email'             => $email,
    'source_url'        => null,
    'source_label'      => 'Sequence call tasks',
    'crm_name'          => 'apollo',
    'crm_identifier'    => $apolloId,
    'record_url'        => $recordUrl,
    'apollo_task_id'    => $taskId,
    'apollo_sequence_id' => $sequenceId,
  ];
}

if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, [
    'skipped'          => $skipped,
    'apollo_contacts'  => count($apolloContacts),
    'reason'           => 'missing phone numbers',
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
  'name'        => 'Apollo Sequence – ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id'   => $client_id,
    'source'      => 'apollo-sequence-tasks',
    'crm_name'    => 'apollo',
    'sequence_id' => $sequenceId,
    'filter'      => $filter,
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
  api_log('apollo_tasks.error.no_launch_url', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ms'          => $pb_ms,
    'pb_http'        => $httpCode,
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
  'filter'          => $filter,
  'sequence_id'     => $sequenceId,
  'pb_ms'           => $pb_ms,
]);
