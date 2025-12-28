<?php
// server/public/webhooks/contact_displayed.php
//
// PhoneBurner webhook: api_contact_displayed
// Updates the session state file so SSE + overlay can show the “current contact”.

require_once __DIR__ . '/../utils.php';

$session_token = $_GET['s'] ?? null;
if (!$session_token) {
    http_response_code(400);
    echo 'Missing session token';
    exit;
}

$raw = file_get_contents('php://input');
log_msg('contact_displayed: ' . $raw);

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$state = load_session_state($session_token) ?? [];
$contactsMap = $state['contacts_map'] ?? [];

/**
 * Extract a stable lookup key for contacts_map.
 * Priority:
 *  1) external_id (Level 1/2)
 *  2) external_crm / external_crm_data hubspot entry crm_id (Level 3 HubSpot)
 *  3) first crm_id in external_crm / external_crm_data (generic fallback)
 */
function extract_contact_lookup_key(array $payload): ?string {
    // external_id could be top-level or nested depending on PB payload shape
    $externalId =
        $payload['external_id'] ??
        ($payload['contact']['external_id'] ?? null) ??
        ($payload['data']['external_id'] ?? null) ??
        ($payload['data']['contact']['external_id'] ?? null) ??
        null;

    $externalId = is_scalar($externalId) ? trim((string)$externalId) : '';
    if ($externalId !== '') {
        return $externalId;
    }

    // PB appears to send "external_crm" (not "external_crm_data")
    // Support both + nested variants.
    $ecd =
        $payload['external_crm'] ??
        $payload['external_crm_data'] ??
        ($payload['contact']['external_crm'] ?? null) ??
        ($payload['contact']['external_crm_data'] ?? null) ??
        ($payload['data']['external_crm'] ?? null) ??
        ($payload['data']['external_crm_data'] ?? null) ??
        ($payload['data']['contact']['external_crm'] ?? null) ??
        ($payload['data']['contact']['external_crm_data'] ?? null) ??
        null;

    // We expect array-of-arrays: [ ['crm_id'=>..., 'crm_name'=>...], ... ]
    if (is_array($ecd)) {
        // Prefer hubspot entry explicitly
        foreach ($ecd as $row) {
            if (!is_array($row)) continue;
            $crmName = strtolower(trim((string)($row['crm_name'] ?? '')));
            $crmId   = trim((string)($row['crm_id'] ?? ''));
            if ($crmName === 'hubspot' && $crmId !== '') {
                return $crmId;
            }
        }
        // Fallback: first crm_id
        foreach ($ecd as $row) {
            if (!is_array($row)) continue;
            $crmId = trim((string)($row['crm_id'] ?? ''));
            if ($crmId !== '') {
                return $crmId;
            }
        }
    }

    return null;
}

$lookupKey = extract_contact_lookup_key($payload);

$fromMap = ($lookupKey && isset($contactsMap[$lookupKey]))
    ? $contactsMap[$lookupKey]
    : null;

$current = [
    'received_at'     => date('c'),
    'raw'             => $payload,

    // For debugging + UI parity
    'lookup_key'      => $lookupKey,
    'external_id'     => $payload['external_id'] ?? null,

    // PB fields (if present)
    'contact_user_id' => $payload['contact_user_id'] ?? null,
    'custom_data'     => $payload['custom_data'] ?? [],
    'webhook_type'    => 'contact_displayed',
];

if ($fromMap) {
    $current['name']         = $fromMap['name']         ?? null;
    $current['phone']        = $fromMap['phone']        ?? null;
    $current['email']        = $fromMap['email']        ?? null;
    $current['source_url']   = $fromMap['source_url']   ?? null;
    $current['source_label'] = $fromMap['source_label'] ?? null;
    $current['crm_name']     = $fromMap['crm_name']     ?? null;
    $current['record_url']   = $fromMap['record_url']   ?? null;
} else {
    // Helpful for debugging: show why it didn't match + what keys exist
    $current['map_miss'] = [
        'has_lookup_key'      => (bool)$lookupKey,
        'contacts_map_count'  => is_array($contactsMap) ? count($contactsMap) : 0,
        'payload_keys'        => array_slice(array_keys($payload), 0, 30),
        'has_external_crm'    => isset($payload['external_crm']),
        'has_external_crm_data' => isset($payload['external_crm_data']),
    ];
}

$state['current']         = $current;
$state['last_event_type'] = 'contact_displayed';

// Keep stats/last_call if they already exist
if (!isset($state['last_call'])) {
    $state['last_call'] = null;
}
if (!isset($state['stats'])) {
    $state['stats'] = [
        'total_calls'  => 0,
        'connected'    => 0,
        'appointments' => 0,
        'by_status'    => [],
    ];
} else {
    // ensure by_status always exists
    if (!isset($state['stats']['by_status']) || !is_array($state['stats']['by_status'])) {
        $state['stats']['by_status'] = [];
    }
}

save_session_state($session_token, $state);

echo 'OK';
