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
 * Priority (NEW):
 *  1) first external_crm crm_id that exists in contacts_map (source of truth)
 *  2) hubspot-ish fallback
 *  3) first crm_id fallback
 *  4) external_id fallback
 */
function extract_contact_lookup_key(array $payload, array $contactsMap): ?string {
    $candidates = [];

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

    if (is_array($ecd)) {
        // collect all crm_id candidates in order
        foreach ($ecd as $row) {
            if (!is_array($row)) continue;
            $crmId = trim((string)($row['crm_id'] ?? ''));
            if ($crmId !== '') $candidates[] = $crmId;
        }

        // 1) strongest: pick the first candidate that exists in contacts_map
        foreach ($candidates as $id) {
            if (isset($contactsMap[$id])) return $id;
        }

        // 2) still helpful: prefer hubspot-ish if nothing matches (debug / fallback)
        foreach ($ecd as $row) {
            if (!is_array($row)) continue;
            $crmName = strtolower(trim((string)($row['crm_name'] ?? '')));
            $crmId   = trim((string)($row['crm_id'] ?? ''));
            if ($crmId !== '' && ($crmName === 'hubspot' || str_starts_with($crmName, 'hubspot'))) {
                return $crmId;
            }
        }

        // 3) fallback: first crm_id
        if (!empty($candidates)) return $candidates[0];
    }

    // legacy fallback only
    $externalId =
        $payload['external_id'] ??
        ($payload['contact']['external_id'] ?? null) ??
        ($payload['data']['external_id'] ?? null) ??
        ($payload['data']['contact']['external_id'] ?? null) ??
        null;

    $externalId = is_scalar($externalId) ? trim((string)$externalId) : '';
    if ($externalId !== '') return $externalId;

    return null;
}

$lookupKey = extract_contact_lookup_key($payload, $contactsMap);

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
    'external_crm'    => $payload['external_crm'] ?? ($payload['external_crm_data'] ?? null),
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
