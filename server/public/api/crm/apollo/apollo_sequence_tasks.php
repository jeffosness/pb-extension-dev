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
// Fetch all tasks with pagination (Apollo defaults to 10 per page)
// Then filter for call type + open status + sequence match in PHP
$authType = apollo_auth_type($tokens);
$allTaskItems = [];
$page = 1;
$maxPages = 50; // safety limit: 50 pages * 100 = 5000 tasks max

while ($page <= $maxPages) {
  $searchBody = [
    'sort_by_field' => 'task_due_at',
    'per_page'      => 100,
    'page'          => $page,
  ];

  list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://api.apollo.io/api/v1/tasks/search', $searchBody, $authType);

  // Retry once on 401 (first page only)
  if ($code === 401 && $page === 1) {
    $tokens = apollo_refresh_access_token_or_fail($client_id, $tokens);
    $accessToken = (string)($tokens['access_token'] ?? '');
    list($code, $json, $_raw) = apollo_api_post_json($accessToken, 'https://api.apollo.io/api/v1/tasks/search', $searchBody, $authType);
  }

  if ($code !== 200 || !is_array($json)) {
    // If first page fails, error out. Otherwise use what we have.
    if ($page === 1) {
      api_error('Failed to fetch Apollo tasks', 'api_error', 502, [
        'http_code'      => $code,
        'sequence_id'    => $sequenceId,
        'filter'         => $filter,
        'auth_type'      => $authType,
        'raw_preview'    => is_string($_raw) ? substr($_raw, 0, 500) : null,
      ]);
    }
    break;
  }

  $pageTasks = $json['tasks'] ?? [];
  if (!is_array($pageTasks) || empty($pageTasks)) break;

  $allTaskItems = array_merge($allTaskItems, $pageTasks);

  // Check if there are more pages
  $totalPages = (int)($json['pagination']['total_pages'] ?? 1);
  if ($page >= $totalPages) break;

  $page++;
}

// Filter results for call type + open + sequence + due date
$tasks     = [];
$totalRaw  = count($allTaskItems);
$today     = gmdate('Y-m-d');
$taskItems = $allTaskItems;

if (is_array($taskItems)) {
  foreach ($taskItems as $task) {
    if (!is_array($task)) continue;

    $taskType = strtolower(trim((string)($task['type'] ?? '')));
    $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
    $completedAt = $task['completed_at'] ?? null;
    $campaignId = (string)($task['emailer_campaign_id'] ?? '');
    $dueAt = (string)($task['due_at'] ?? $task['due_date'] ?? '');

    // Filter: only call tasks (type contains "call")
    $isCallTask = (strpos($taskType, 'call') !== false);
    if (!$isCallTask) continue;

    // Filter: only open/incomplete tasks
    if ($completedAt !== null) continue;
    if ($taskStatus === 'completed' || $taskStatus === 'skipped') continue;

    // Filter: match sequence if provided
    if ($sequenceId !== '' && $campaignId !== '' && $campaignId !== $sequenceId) continue;

    // Filter: by due date
    $dueDate = substr($dueAt, 0, 10); // YYYY-MM-DD
    if ($filter === 'due_today' && $dueDate !== $today) continue;
    if ($filter === 'due_and_overdue' && $dueDate > $today) continue;

    $contactId = (string)($task['contact_id'] ?? $task['person_id'] ?? '');
    $contactName = trim((string)($task['contact_name'] ?? $task['person_name'] ?? ''));

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
      'due_date'     => $dueAt,
      'type'         => $taskType,
      'priority'     => (string)($task['priority'] ?? ''),
      'campaign_id'  => $campaignId,
    ];
  }
}

$total = count($tasks);

api_log('apollo_sequence_tasks.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  'sequence_id'    => $sequenceId,
  'filter'         => $filter,
  'tasks_found'    => count($tasks),
  'total_raw'      => $totalRaw,
  'total'          => $total,
]);

api_ok([
  'tasks'       => $tasks,
  'total'       => $total,
  'filter'      => $filter,
  'sequence_id' => $sequenceId,
]);
