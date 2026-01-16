<?php
// server/public/api/crm/generic/dialsession_from_scan.php
//
// Creates a PhoneBurner dial session from contacts scraped by the extension (Level 1/2 generic).
// - Reads JSON from the extension: { client_id, contacts[], context{} }
// - Loads the saved PhoneBurner PAT for that client_id
// - Normalizes contacts into PB's expected format
// - Creates /dialsession with callbacks that include session_token
// - Persists session state for SSE/webhooks
//
// Response shape is standardized via api_ok/api_error from bootstrap.php.
// This endpoint intentionally avoids logging PII (names/phones/emails).

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

// -------------------------
// Input + auth
// -------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);

$pat = load_pb_token($client_id);
if (!$pat) {
    api_log('dialsession_from_scan.reject.no_pat', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    ]);
    api_error('No PhoneBurner PAT saved for this client_id', 'unauthorized', 401);
}

$contactsIn = $data['contacts'] ?? [];
$context    = $data['context']  ?? [];

if (!is_array($contactsIn) || empty($contactsIn)) {
    api_log('dialsession_from_scan.reject.no_contacts', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    ]);
    api_error('No contacts provided', 'bad_request', 400);
}

// Normalize crm_name once and keep it lowercase for consistency
$crmNameRaw = $context['crm_name'] ?? 'generic';
$crmName    = strtolower((string)$crmNameRaw);

// -------------------------
// Build session token
// -------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];
$skipped      = 0;

// -------------------------
// Normalize contacts
// -------------------------
foreach ($contactsIn as $idx => $c) {
    if (!is_array($c)) {
        $skipped++;
        continue;
    }

    $name  = trim((string)($c['name'] ?? ''));
    $first = trim((string)($c['first_name'] ?? ''));
    $last  = trim((string)($c['last_name']  ?? ''));

    if ($first === '' && $last === '' && $name !== '') {
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

    // External ID returned later in contact_displayed.external_id
    $externalId =
        ($c['crm_identifier'] ?? null) ? (string)$c['crm_identifier'] :
        (($c['external_id'] ?? null)   ? (string)$c['external_id']    :
        ($crmName . '-' . $idx));

    $pbContacts[] = [
        'first_name'  => $first,
        'last_name'   => $last,
        'phone'       => $phone ?: null,
        'email'       => $email ?: null,
        'external_id' => $externalId,
        'external_crm_data' => [
            [
                'crm_id'   => $externalId,
                'crm_name' => $crmName,
            ],
        ],
    ];

    // Saved for follow-me/SSE/webhooks (internal state only)
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

if (empty($pbContacts)) {
    api_log('dialsession_from_scan.reject.no_dialable', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'skipped'        => $skipped,
    ]);
    api_error('No dialable contacts (no phone or email).', 'bad_request', 400, [
        'skipped_no_phone_or_email' => $skipped,
    ]);
}

// -------------------------
// Build PB dialsession payload
// -------------------------
$base = rtrim(cfg()['BASE_URL'] ?? '', '/');
if ($base === '') {
    api_log('dialsession_from_scan.error.missing_base_url');
    api_error('Server misconfigured: BASE_URL missing', 'server_error', 500);
}

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

$payload = [
    'name'      => 'CRM List – ' . gmdate('c'),
    'contacts'  => $pbContacts,
    'preset_id' => null,
    'custom_data' => [
        'client_id' => $client_id,
        'source'    => $context['source'] ?? 'crm-extension',
        'crm_name'  => $crmName,
    ],
    'callbacks'    => $callbacks,
    'webhook_meta' => [
        'session_token' => $session_token,
        'client_id'     => $client_id,
        'crm_name'      => $crmName,
    ],
];

// -------------------------
// Call PhoneBurner (timed)
// -------------------------
$t0 = microtime(true);
list($info, $resp) = pb_api_call($pat, 'POST', '/dialsession', $payload);
$pb_ms = (int) round((microtime(true) - $t0) * 1000);

$httpCode = (int)($info['http_code'] ?? 0);
if ($httpCode >= 400 || !is_array($resp)) {
    api_log('dialsession_from_scan.error.pb_api', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'crm_name'       => $crmName,
        'pb_ms'          => $pb_ms,
        'status'         => $httpCode,
    ]);
    api_error('PhoneBurner API error', 'pb_api_error', $httpCode ?: 500, [
        'status' => $httpCode,
    ]);
}

$launch_url = $resp['dialsessions']['redirect_url'] ?? null;
$dial_id    = $resp['dialsessions']['id'] ?? null;

if (!$launch_url) {
    api_log('dialsession_from_scan.error.no_launch_url', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'crm_name'       => $crmName,
        'pb_ms'          => $pb_ms,
    ]);
    api_error('No launch URL in PhoneBurner response', 'pb_bad_response', 502);
}

// -------------------------
// Save initial session state
// -------------------------
$state = [
    'session_token'   => $session_token,
    'dialsession_id'  => $dial_id,
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

api_log('dialsession_from_scan.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'crm_name'       => $crmName,
    'contacts_sent'  => count($pbContacts),
    'skipped'        => $skipped,
    'pb_ms'          => $pb_ms,
]);

// -------------------------
// Generate temporary code for secure URL (not embedding token)
// -------------------------
$tempCode = temp_code_store($session_token, 300);  // 5-minute TTL

api_ok_flat([
  'session_token'   => $session_token,
  'temp_code'       => $tempCode,
  'dialsession_url' => $launch_url,
  'launch_url'      => $launch_url . (strpos($launch_url, '?') ? '&' : '?') . 'code=' . urlencode($tempCode),
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
]);

