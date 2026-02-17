<?php
// server/public/api/crm/hubspot/hs_lists.php
//
// Returns the user's most recently updated HubSpot lists (contacts + companies).
// Used by the extension popup to populate the "Launch from List" dropdown.
//
// Accepts: { client_id }
// Returns: { ok: true, lists: [{ listId, name, size, objectType, type, updatedAt }] }

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/hs_helpers.php';

// -----------------------------------------------------------------------------
// Input + auth
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

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

// -----------------------------------------------------------------------------
// Fetch lists from HubSpot (contacts + companies)
// -----------------------------------------------------------------------------
$allLists = [];

$objectTypes = [
  '0-1' => 'contacts',
  '0-2' => 'companies',
];

foreach ($objectTypes as $objectTypeId => $objectTypeName) {
  list($code, $json, $_raw) = hs_api_post_json($hsAccess, 'https://api.hubapi.com/crm/v3/lists/search', [
    'objectTypeId'    => $objectTypeId,
    'processingTypes' => ['MANUAL', 'DYNAMIC', 'SNAPSHOT'],
  ]);

  // Retry once on 401
  if ($code === 401) {
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = (string)($hs['access_token'] ?? '');
    list($code, $json, $_raw) = hs_api_post_json($hsAccess, 'https://api.hubapi.com/crm/v3/lists/search', [
      'objectTypeId'    => $objectTypeId,
      'processingTypes' => ['MANUAL', 'DYNAMIC', 'SNAPSHOT'],
    ]);
  }

  if ($code !== 200 || !is_array($json) || !isset($json['lists'])) {
    api_log('hs_lists.search_fail', [
      'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
      'object_type'    => $objectTypeName,
      'http_code'      => $code,
    ]);
    continue; // Skip this object type but don't fail entirely
  }

  foreach ($json['lists'] as $list) {
    if (!is_array($list)) continue;

    $allLists[] = [
      'listId'     => (string)($list['listId'] ?? $list['id'] ?? ''),
      'name'       => (string)($list['name'] ?? ''),
      'size'       => (int)($list['size'] ?? 0),
      'objectType' => $objectTypeName,
      'type'       => strtolower((string)($list['processingType'] ?? 'unknown')),
      'updatedAt'  => (string)($list['updatedAt'] ?? $list['createdAt'] ?? ''),
    ];
  }
}

// Sort by updatedAt descending (most recent first)
usort($allLists, function($a, $b) {
  return strcmp($b['updatedAt'], $a['updatedAt']);
});

// Return top 10
$allLists = array_slice($allLists, 0, 10);

api_log('hs_lists.ok', [
  'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
  'total_lists'    => count($allLists),
]);

api_ok(['lists' => $allLists]);
