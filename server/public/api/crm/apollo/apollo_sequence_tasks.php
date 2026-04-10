<?php
// server/public/api/crm/apollo/apollo_sequence_tasks.php
//
// Returns open call tasks for a given Apollo sequence.
// This is the preview step — shows count + contact names before launching.
//
// Accepts: { client_id, sequence_id, filter: "due_today"|"due_and_overdue"|"all_open" }
// Returns: { ok: true, tasks: [{ task_id, contact_id, contact_name, due_date }], total }

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/apollo_helpers.php';

// -----------------------------------------------------------------------------
// Input + auth
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

$sequenceId = trim((string)($data['sequence_id'] ?? ''));
if ($sequenceId === '' || !preg_match('/^[a-f0-9]{24}$/', $sequenceId)) {
  api_error('Invalid or missing sequence_id', 'bad_request', 400);
}

$filter = trim((string)($data['filter'] ?? 'all_open'));
if (!in_array($filter, ['due_today', 'due_and_overdue', 'all_open'], true)) {
  $filter = 'all_open';
}

$tokens = load_apollo_tokens($client_id);
if (!is_array($tokens)) {
  api_error('No Apollo tokens saved for this client_id', 'unauthorized', 401);
}

$accessToken = apollo_ensure_access_token($client_id, $tokens);

// -----------------------------------------------------------------------------
// Fetch open call tasks for this sequence
// POST /v1/tasks/search
// -----------------------------------------------------------------------------
$searchBody = [
  'per_page'             => 500,
  'open_factor_id'       => $sequenceId,  // sequence filter
  'type'                 => 'make_call',  // call tasks only
  'is_completed'         => false,        // open tasks only
  'sort_by_key'          => 'due_date',
  'sort_ascending'       => true,
];

// Apply date filter
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
// 'all_open' = no date filter

list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://app.apollo.io/api/v1/tasks/search', $searchBody);

// Retry once on 401
if ($code === 401) {
  $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
  $accessToken = (string)($tokens['access_token'] ?? '');
  list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://app.apollo.io/api/v1/tasks/search', $searchBody);
}

if ($code !== 200 || !is_array($json)) {
  api_log('apollo_sequence_tasks.search_fail', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'sequence_id'    => $sequenceId,
    'filter'         => $filter,
    'http_code'      => $code,
  ]);
  api_error('Failed to fetch Apollo tasks', 'api_error', 502);
}

// Normalize results
$tasks     = [];
$taskItems = $json['tasks'] ?? $json['data'] ?? [];
$total     = (int)($json['pagination']['total_entries'] ?? $json['total_entries'] ?? count($taskItems));

if (is_array($taskItems)) {
  foreach ($taskItems as $task) {
    if (!is_array($task)) continue;

    $contactId = (string)($task['contact_id'] ?? $task['person_id'] ?? '');
    $contactName = trim((string)($task['contact_name'] ?? $task['person_name'] ?? ''));

    // Some Apollo task responses nest contact info
    if ($contactName === '' && isset($task['contact'])) {
      $c = $task['contact'];
      $contactName = trim(
        trim((string)($c['first_name'] ?? '')) . ' ' . trim((string)($c['last_name'] ?? ''))
      );
    }

    $tasks[] = [
      'task_id'      => (string)($task['id'] ?? ''),
      'contact_id'   => $contactId,
      'contact_name' => $contactName,
      'due_date'     => (string)($task['due_date'] ?? ''),
      'type'         => (string)($task['type'] ?? ''),
      'priority'     => (string)($task['priority'] ?? ''),
    ];
  }
}

api_log('apollo_sequence_tasks.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  'sequence_id'    => $sequenceId,
  'filter'         => $filter,
  'tasks_found'    => count($tasks),
  'total'          => $total,
]);

api_ok([
  'tasks'       => $tasks,
  'total'       => $total,
  'filter'      => $filter,
  'sequence_id' => $sequenceId,
]);
