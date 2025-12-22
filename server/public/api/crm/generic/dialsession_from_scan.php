<?php
// generic_crm/api/pb_dialsession.php
require_once __DIR__ . '/../../../utils.php';

// Simple flag so we can turn SF logging on/off in one place
const PB_UNIFIED_DEBUG_SF = true;

// 1) Get JSON input from extension and resolve client_id
$data      = json_input();
$client_id = get_client_id_or_fail($data);

// 2) Get the PhoneBurner PAT for this client
$pat = load_pb_token($client_id);
if (!$pat) {
    json_response([
        'ok'    => false,
        'error' => 'No PhoneBurner PAT saved for this client_id'
    ], 401);
}

// 3) Extract contacts + context
$contactsIn = $data['contacts'] ?? [];
$context    = $data['context']  ?? [];

// Normalize crm_name once and keep it lowercase for consistency
$crmNameRaw = $context['crm_name'] ?? 'generic-crm';
$crmName    = strtolower($crmNameRaw);

// 🔍 SF DEBUG: helper flag
$isSalesforce = ($crmName === 'salesforce' || $crmName === 'salesforce.com');

// 🔍 SF DEBUG: log raw inbound data from extension
if (PB_UNIFIED_DEBUG_SF && $isSalesforce) {
    log_msg('SF-UNIFIED: raw contactsIn from extension: ' . json_encode($contactsIn));
    log_msg('SF-UNIFIED: raw context from extension: ' . json_encode($context));
}

if (!is_array($contactsIn) || empty($contactsIn)) {
    json_response([
        'ok'    => false,
        'error' => 'No contacts provided'
    ], 400);
}

// 4) Create a session_token that will be shared with webhooks & SSE
$session_token = bin2hex(random_bytes(16));

$pbContacts = [];
$skipped    = 0;

// 5) Normalize the generic contact fields from the content script
$contacts_map = [];

foreach ($contactsIn as $idx => $c) {
    $name  = trim((string)($c['name'] ?? ''));
    $first = trim((string)($c['first_name'] ?? ''));
    $last  = trim((string)($c['last_name']  ?? ''));

    if (!$first && !$last && $name) {
        $parts = preg_split('/\s+/', $name);
        $first = $parts[0] ?? '';
        $last  = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';
    }

    $phone = trim((string)($c['phone'] ?? ''));
    $email = trim((string)($c['email'] ?? ''));

    if ($phone === '' && $email === '') {
        $skipped++;
        continue;
    }

    // This is the key that will come back in contact_displayed.external_id
    $externalId =
        $c['crm_identifier'] ??           // preferred (e.g. bntouch/pipedrive ID)
        $c['external_id']    ??           // if content.js provided it explicitly
        ($crmName ?: 'generic') . '-' . $idx; // fallback

    $pbContacts[] = [
        'first_name'  => $first,
        'last_name'   => $last,
        'phone'       => $phone ?: null,
        'email'       => $email ?: null,
        'external_id' => $externalId,
        'external_crm_data' => [
            [
                'crm_id'   => $externalId,
                'crm_name' => $crmName, // always lowercase
            ],
        ],
    ];

    $contacts_map[$externalId] = [
        'name'           => $name ?: trim("$first $last"),
        'first_name'     => $first,
        'last_name'      => $last,
        'phone'          => $phone,
        'email'          => $email,
        'source_url'     => $c['source_url']    ?? null,
        'source_label'   => $c['source_label']  ?? null,
        'crm_name'       => $crmName,
        'crm_identifier' => $externalId,
        'record_url'     => $c['record_url']    ?? null,
    ];
}

// 🔍 SF DEBUG: log normalized contacts before sending to PhoneBurner
if (PB_UNIFIED_DEBUG_SF && $isSalesforce) {
    log_msg('SF-UNIFIED: normalized pbContacts (about to send to PB): ' . json_encode($pbContacts));
    log_msg('SF-UNIFIED: contacts_map used for session state: ' . json_encode($contacts_map));
}

log_msg('pb_dialsession contacts_map: ' . json_encode($contacts_map));

if (empty($pbContacts)) {
    json_response([
        'ok'    => false,
        'error' => 'No dialable contacts (no phone or email).',
        'debug' => ['skipped_no_phone_or_email' => $skipped]
    ], 400);
}

// 6) Build webhook callback URLs
$base = rtrim(cfg()['BASE_URL'], '/');

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

// 7) Prepare the dialsession payload – mirrors your HubSpot logic, just with generic metadata
$payload = [
    'name'        => 'Generic CRM List – ' . gmdate('c'),
    'contacts'    => $pbContacts,
    'preset_id'   => null,

    // SESSION-LEVEL custom_data – same shape across all CRMs
    'custom_data' => [
        'client_id' => $client_id,
        'source'    => $context['source'] ?? 'generic-crm-extension',
        'crm_name'  => $crmName,
    ],

    'callbacks'   => $callbacks,
    'webhook_meta'=> [
        'session_token' => $session_token,
        'client_id'     => $client_id,
        'crm_name'      => $crmName,
    ],
];

// 🔍 SF DEBUG: log the payload we are POSTing to /dialsession
if (PB_UNIFIED_DEBUG_SF && $isSalesforce) {
    log_msg('SF-UNIFIED: final dialsession payload to PB: ' . json_encode($payload));
}

// 8) Call PhoneBurner – same endpoint as your HubSpot version, but using pb_api_call()
list($info, $resp) = pb_api_call($pat, 'POST', '/dialsession', $payload);

// Log for debugging
log_msg('generic_crm pb_dialsession response: ' . json_encode([
    'http' => $info,
    'resp' => $resp
]));

$httpCode = (int)($info['http_code'] ?? 0);

if ($httpCode >= 400 || !$resp) {
    json_response([
        'ok'     => false,
        'error'  => 'PhoneBurner API error: ' . json_encode($resp),
        'status' => $httpCode,
        'details'=> $resp,
    ], $httpCode ?: 500);
}

// 9) Extract the launch URL the same way your working HubSpot code does
$launch_url = $resp['dialsessions']['redirect_url'] ?? null;
if (!$launch_url) {
    json_response([
        'ok'    => false,
        'error' => 'No launch URL in PhoneBurner response',
        'body'  => $resp
    ], 502);
}

// 10) Save initial session state so SSE/webhooks have somewhere to write
$state = [
    'session_token'   => $session_token,
    'dialsession_id'  => $resp['dialsessions']['id'] ?? null,
    'dialsession_url' => $launch_url,
    'client_id'       => $client_id,
    'created_at'      => date('c'),
    'current'         => null,
    'last_call'       => null,
    'stats'           => [
        'total_calls'  => 0,
        'connected'    => 0,
        'appointments' => 0,
    ],
    'contacts_map'    => $contacts_map, 
    'crm_name'        => $crmName,  
];
save_session_state($session_token, $state);

// 11) Respond in the shape that background.js expects
json_response([
    'ok'             => true,
    'session_token'  => $session_token,
    'dialsession_url'=> $launch_url,
    'contacts_sent'  => count($pbContacts),
    'skipped'        => $skipped,
]);
