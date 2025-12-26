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

$externalId  = $payload['external_id'] ?? null;
$contactsMap = $state['contacts_map'] ?? [];
$fromMap     = ($externalId && isset($contactsMap[$externalId]))
    ? $contactsMap[$externalId]
    : null;

$current = [
    'received_at'     => date('c'),
    'raw'             => $payload,
    'external_id'     => $externalId,
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
