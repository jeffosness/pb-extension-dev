<?php
// server/public/api/crm/hubspot/hs_resolve_by_phone.php
//
// Dial-pad resolver: given a typed phone number, find the HubSpot contact(s) it
// belongs to so the extension can dial the right record (PB logs the call via
// external_crm_data) — or learn there's no match so it can offer to create one.
//
// Accepts: { client_id, number }
// Returns: api_ok({ digits, count, matches: [{id, firstname, lastname, company, phone, mobilephone}, ...] })
//
// Read-only. The search strategy (EQ on HubSpot's calculated searchable phone
// properties) was settled empirically — see hs_search_contacts_by_phone() and
// scripts/diagnostics/probe_hs_phone_search.php. Multi-match is expected; the
// extension disambiguates with a picker.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/hs_helpers.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 30);

$number = trim((string)($data['number'] ?? ''));
if ($number === '') {
  api_error('number is required', 'bad_request', 400);
}

$hs = load_hs_tokens($client_id);
if (!is_array($hs)) {
  api_error('No HubSpot tokens saved for this client_id', 'unauthorized', 401);
}
if (hs_token_is_expired($hs)) {
  $hs = hs_refresh_access_token_or_fail($client_id, $hs);
}
$hsAccess = (string)($hs['access_token'] ?? '');
if ($hsAccess === '') {
  api_error('No HubSpot access token saved for this client_id', 'unauthorized', 401);
}

$res = hs_search_contacts_by_phone($hsAccess, $number);
if (!$res['ok']) {
  $msg = 'HubSpot phone search failed' . (!empty($res['error']) ? ': ' . $res['error'] : '');
  api_error($msg, 'upstream_error', 502);
}

api_ok([
  'digits'  => $res['digits'],
  'count'   => $res['count'],
  'matches' => $res['matches'],
]);
