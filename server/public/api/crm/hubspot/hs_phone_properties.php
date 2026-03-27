<?php
// server/public/api/crm/hubspot/hs_phone_properties.php
//
// Returns the list of phone-type properties available for a HubSpot portal.
// Used by the extension popup to populate the "Primary Phone Field" preference dropdown.
//
// Accepts: { client_id, object_type? }
//   - object_type: "contacts" (default) or "companies"
// Returns: { ok, data: { phone_properties: [{name, label}, ...] } }

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/hs_helpers.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

$objectType = trim((string)($data['object_type'] ?? 'contacts'));
if (!in_array($objectType, ['contacts', 'companies'], true)) {
  api_error('object_type must be "contacts" or "companies"', 'bad_request', 400);
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

$hubId = (string)($hs['hub_id'] ?? '');

// Discover phone properties (uses 1-hour cache)
$phoneProps = hs_discover_phone_properties($hsAccess, $objectType, $hubId);

api_ok([
  'phone_properties' => $phoneProps,
  'object_type'      => $objectType,
]);
