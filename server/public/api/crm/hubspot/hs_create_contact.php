<?php
// server/public/api/crm/hubspot/hs_create_contact.php
//
// Dial-pad "no match → create & dial" path: create a HubSpot contact for a typed
// number so the subsequent call logs against a real record.
//
// Accepts: { client_id, number, firstname?, lastname? }
// Returns: api_ok({ id, created })   // created=false if an existing match was found
//
// DUPLICATE GUARD: even though the extension only calls this after a resolve
// returned zero matches, we re-search immediately before creating. That closes
// the double-submit / race window and means a flaky first search can't strand a
// duplicate in the customer's CRM — the highest-consequence failure mode for
// this feature (see the Touch Plan). If a match now exists, we return it instead
// of creating.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
require_once __DIR__ . '/hs_helpers.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 20);

$number    = trim((string)($data['number'] ?? ''));
$firstname = trim((string)($data['firstname'] ?? ''));
$lastname  = trim((string)($data['lastname'] ?? ''));
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

// Enrich with owner_id (lazy backfill) so the created contact is attributed to
// this user rather than left ownerless. Safe on failure — hs_ensure_owner_cached
// returns the tokens unchanged if enrichment can't complete.
$hs = hs_ensure_owner_cached($client_id, $hs, 'hs_dialpad_create');
$hsAccess = (string)($hs['access_token'] ?? '');
if ($hsAccess === '') {
  api_error('No HubSpot access token saved for this client_id', 'unauthorized', 401);
}
$ownerId = (string)($hs['owner_id'] ?? '');

// Duplicate guard: re-search right before creating.
$existing = hs_search_contacts_by_phone($hsAccess, $number);
if ($existing['ok'] && $existing['count'] > 0) {
  api_ok([
    'id'      => $existing['matches'][0]['id'],
    'created' => false,
    'matches' => $existing['matches'],
  ]);
}

$res = hs_create_contact_record($hsAccess, $number, $firstname, $lastname, $ownerId);
if (!$res['ok']) {
  $msg = 'HubSpot contact creation failed' . (!empty($res['error']) ? ': ' . $res['error'] : '');
  api_error($msg, 'upstream_error', 502);
}

api_log('hs_dialpad_create.ok', [
  'client_id_hash' => substr(hash('sha256', $client_id), 0, 12),
  'has_owner'      => $ownerId !== '',
]);

api_ok([
  'id'      => $res['id'],
  'created' => true,
]);
