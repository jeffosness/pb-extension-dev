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

// Load user's preferred primary phone field
$preferredPhoneField = '';
if ($member_user_id) {
  $userSettings = load_user_settings($member_user_id);
  $preferredPhoneField = trim((string)($userSettings['crm_preferences']['apollo']['preferred_phone_field'] ?? ''));
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
// Tasks response includes full contact data (phone_numbers, email, etc.)
// so we don't need a separate contact fetch.
// -----------------------------------------------------------------------------
// Fetch all tasks with pagination (Apollo defaults to 10 per page)
$authType = apollo_auth_type($tokens);
$allTaskItems = [];
$page = 1;
$maxPages = 50;

while ($page <= $maxPages) {
  $searchBody = [
    'sort_by_field' => 'task_due_at',
    'per_page'      => 100,
    'page'          => $page,
  ];

  list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://api.apollo.io/api/v1/tasks/search', $searchBody, $authType);

  if ($code === 401 && $page === 1) {
    $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
    $accessToken = (string)($tokens['access_token'] ?? '');
    list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://api.apollo.io/api/v1/tasks/search', $searchBody, $authType);
  }

  if ($code !== 200 || !is_array($json)) {
    if ($page === 1) {
      api_error('Failed to fetch Apollo tasks', 'api_error', 502, [
        'http_code'   => $code,
        'raw_preview' => is_string($_raw) ? substr($_raw, 0, 500) : null,
      ]);
    }
    break;
  }

  $pageTasks = $json['tasks'] ?? [];
  if (!is_array($pageTasks) || empty($pageTasks)) break;

  $allTaskItems = array_merge($allTaskItems, $pageTasks);

  $totalPages = (int)($json['pagination']['total_pages'] ?? 1);
  if ($page >= $totalPages) break;

  $page++;
}

// Filter tasks: call type, open, matching sequence, due date
$taskItems = $allTaskItems;
$today = gmdate('Y-m-d');

$filteredTasks = [];
foreach ($taskItems as $task) {
  if (!is_array($task)) continue;

  $taskType = strtolower(trim((string)($task['type'] ?? '')));
  if (strpos($taskType, 'call') === false) continue;

  if ($task['completed_at'] !== null) continue;
  $status = strtolower(trim((string)($task['status'] ?? '')));
  if ($status === 'completed' || $status === 'skipped') continue;

  $campaignId = (string)($task['emailer_campaign_id'] ?? '');
  if ($sequenceId !== '' && $campaignId !== '' && $campaignId !== $sequenceId) continue;

  $dueAt = (string)($task['due_at'] ?? '');
  $dueDate = substr($dueAt, 0, 10);
  if ($filter === 'due_today' && $dueDate !== $today) continue;
  if ($filter === 'due_and_overdue' && $dueDate > $today) continue;

  $filteredTasks[] = $task;
}

if (empty($filteredTasks)) {
  api_error('No open call tasks found for this sequence', 'bad_request', 400, [
    'filter'      => $filter,
    'sequence_id' => $sequenceId,
    'total_tasks' => count($taskItems),
  ]);
}

// Cap at 500
if (count($filteredTasks) > 500) {
  $filteredTasks = array_slice($filteredTasks, 0, 500);
}

// -----------------------------------------------------------------------------
// Build PB contacts directly from task data (contact is embedded in response)
// -----------------------------------------------------------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];
$skipped      = 0;

foreach ($filteredTasks as $task) {
  $taskId    = (string)($task['id'] ?? '');
  $contactId = (string)($task['contact_id'] ?? $task['person_id'] ?? '');
  $contact   = $task['contact'] ?? null;

  if (!is_array($contact) || $contactId === '') { $skipped++; continue; }

  // Normalize contact from embedded task data
  $c = apollo_normalize_contact($contact, $preferredPhoneField);
  $first    = trim((string)($c['first_name'] ?? ''));
  $last     = trim((string)($c['last_name'] ?? ''));
  $email    = trim((string)($c['email'] ?? ''));
  $phone    = trim((string)($c['phone'] ?? ''));
  $apolloId = (string)($c['apollo_id'] ?? $contactId);

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
