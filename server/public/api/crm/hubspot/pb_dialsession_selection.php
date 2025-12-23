<?php
// server/public/api/crm/hubspot/pb_dialsession_selection.php
//
// Build a PhoneBurner dial session from a HubSpot selection.
// Extension-facing endpoint => MUST return FLAT keys (use api_ok_flat()).
// Avoid logging PII/tokens; log only hashes + counts + timings.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$cfg       = cfg();
$data      = json_input();
$client_id = get_client_id_or_fail($data);

$mode    = isset($data['mode']) ? (string)$data['mode'] : 'contacts';
$records = $data['records'] ?? [];
$context = $data['context'] ?? [];

// Canonical lowercase CRM name for HubSpot
$crmName = 'hubspot';

if (!is_array($records) || empty($records)) {
    api_log('hubspot_selection.reject.no_records', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'mode'           => $mode,
    ]);
    api_error('No HubSpot records supplied', 'bad_request', 400);
}

$hsTokens = load_hs_tokens($client_id);
if (!$hsTokens || empty($hsTokens['access_token'])) {
    api_log('hubspot_selection.reject.no_hs_tokens', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    ]);
    api_error('HubSpot is not connected for this client_id', 'unauthorized', 401);
}

$pat = load_pb_token($client_id);
if (!$pat) {
    api_log('hubspot_selection.reject.no_pb_pat', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    ]);
    api_error('No PhoneBurner PAT saved for this client_id', 'unauthorized', 401);
}

$accessToken = (string)$hsTokens['access_token'];
$portalId    = isset($context['portalId']) ? (string)$context['portalId'] : null;

// ---------------------------------------------------------------------
// Helper: Normalize "records" array into a flat list of string IDs.
// ---------------------------------------------------------------------
function extract_ids_from_records($records): array
{
    $ids = [];
    foreach ($records as $r) {
        if (is_array($r) && isset($r['id'])) {
            $ids[] = (string)$r['id'];
        } elseif (is_scalar($r)) {
            $ids[] = (string)$r;
        }
    }
    $ids = array_values(array_unique($ids));
    return $ids;
}

// ---------------------------------------------------------------------
// Helper: minimal HubSpot GET wrapper (timed)
// ---------------------------------------------------------------------
function hs_api_get_timed(string $accessToken, string $path, array $params = []): array
{
    $url = 'https://api.hubapi.com' . $path;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $t0 = microtime(true);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($err) {
        return [0, null, $ms];
    }

    $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
    $data   = json_decode($resp, true);

    return [$status, is_array($data) ? $data : null, $ms];
}

// ---------------------------------------------------------------------
// 1) Work out which CONTACT IDs we need to call
// ---------------------------------------------------------------------
$contactIds = [];
$debugInfo  = [];

if ($mode === 'contacts') {
    $contactIds = extract_ids_from_records($records);
    $debugInfo['source'] = 'contacts';

} elseif ($mode === 'deals' || $mode === 'companies') {
    $objType    = ($mode === 'deals') ? 'deals' : 'companies';
    $primaryIds = extract_ids_from_records($records);

    $debugInfo['source']      = $objType;
    $debugInfo['primary_cnt'] = count($primaryIds);

    $seenContactIds = [];

    foreach ($primaryIds as $pid) {
        // GET /crm/v3/objects/{deals|companies}/{id}?associations=contacts
        list($status, $body, $ms) = hs_api_get_timed(
            $accessToken,
            '/crm/v3/objects/' . $objType . '/' . rawurlencode($pid),
            ['associations' => 'contacts']
        );

        if ($status < 200 || $status >= 300 || !is_array($body)) {
            $debugInfo['assoc_errors'][] = [
                'status' => $status,
            ];
            continue;
        }

        $assocContacts = $body['associations']['contacts']['results'] ?? [];
        foreach ($assocContacts as $row) {
            if (!is_array($row) || empty($row['id'])) continue;
            $cid = (string)$row['id'];
            $seenContactIds[$cid] = true;
        }
    }

    $contactIds = array_keys($seenContactIds);
    $debugInfo['contact_cnt_from_assoc'] = count($contactIds);

} else {
    api_log('hubspot_selection.reject.unsupported_mode', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'mode'           => $mode,
    ]);
    api_error('Unsupported selection mode', 'bad_request', 400, [
        'debug' => ['mode' => $mode],
    ]);
}

if (empty($contactIds)) {
    api_log('hubspot_selection.reject.no_contacts', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'mode'           => $mode,
    ]);
    api_error('No associated HubSpot contacts found to dial.', 'bad_request', 400, [
        'debug' => $debugInfo,
    ]);
}

// ---------------------------------------------------------------------
// 2) Resolve HubSpot contact details for those IDs
// ---------------------------------------------------------------------
$contacts = [];
$skipped  = 0;

$hsCalls = 0;
$hsMsSum = 0;

foreach ($contactIds as $id) {
    $hsCalls++;
    list($status, $body, $ms) = hs_api_get_timed(
        $accessToken,
        '/crm/v3/objects/contacts/' . rawurlencode($id),
        ['properties' => 'firstname,lastname,phone,mobilephone,email']
    );
    $hsMsSum += $ms;

    if ($status < 200 || $status >= 300 || !is_array($body)) {
        $skipped++;
        continue;
    }

    $props = $body['properties'] ?? [];

    $first = trim((string)($props['firstname']   ?? ''));
    $last  = trim((string)($props['lastname']    ?? ''));
    $phone = trim((string)($props['phone']       ?? ''));
    $mobi  = trim((string)($props['mobilephone'] ?? ''));
    $email = trim((string)($props['email']       ?? ''));

    if ($phone === '' && $mobi === '' && $email === '') {
        $skipped++;
        continue;
    }

    if ($phone === '' && $mobi !== '') {
        $phone = $mobi;
    }

    $name = trim($first . ' ' . $last);
    if ($name === '') {
        $name = $email ?: $phone ?: ('Contact #' . $id);
    }

    $recordUrl = null;
    if ($portalId) {
        $recordUrl = 'https://app.hubspot.com/contacts/'
            . rawurlencode($portalId)
            . '/record/0-1/'
            . rawurlencode($id);
    }

    $contacts[] = [
        'hs_id'      => $id,
        'first_name' => $first,
        'last_name'  => $last,
        'name'       => $name,
        'phone'      => $phone,
        'email'      => $email,
        'record_url' => $recordUrl,
    ];
}

if (empty($contacts)) {
    api_log('hubspot_selection.reject.no_dialable_contacts', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'mode'           => $mode,
        'skipped'        => $skipped,
        'hs_calls'       => $hsCalls,
        'hs_ms_sum'      => $hsMsSum,
    ]);
    api_error('No dialable HubSpot contacts (no phone or email).', 'bad_request', 400, [
        'debug' => [
            'skipped_contacts' => $skipped,
            'inner_source'     => $debugInfo,
        ],
    ]);
}

// ---------------------------------------------------------------------
// 3) Build PhoneBurner contacts + session state
// ---------------------------------------------------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];

$sourceUrl   = $context['url']   ?? null;
$sourceLabel = $context['title'] ?? null;

foreach ($contacts as $c) {
    $extId = 'hs-contact:' . $c['hs_id'];

    // Keep existing field names as-is to avoid breaking PB expectations.
    // (If PB requires first_name/last_name only, we can adjust later, but this preserves current behavior.)
    $pbContacts[] = [
        'firstname'       => $c['first_name'],
        'lastname'        => $c['last_name'],
        'phone'           => $c['phone'],
        'email'           => $c['email'],
        'external_id'     => $extId,
        'external_source' => 'hubspot',
        'external_crm_data' => [
            [
                'crm_id'   => $extId,
                'crm_name' => 'hubspot',
            ],
        ],
    ];

    $contacts_map[$extId] = [
        'name'           => $c['name'],
        'first_name'     => $c['first_name'],
        'last_name'      => $c['last_name'],
        'phone'          => $c['phone'],
        'email'          => $c['email'],
        'source_url'     => $sourceUrl,
        'source_label'   => $sourceLabel,
        'crm_name'       => $crmName,
        'crm_identifier' => $extId,
        'record_url'     => $c['record_url'],
    ];
}

// ---------------------------------------------------------------------
// 4) Call PhoneBurner to create the dial session
// ---------------------------------------------------------------------
$base = rtrim($cfg['BASE_URL'] ?? '', '/');
if ($base === '') {
    api_log('hubspot_selection.error.missing_base_url');
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
    'name'        => 'HubSpot selection (' . $mode . ')',
    'description' => 'Created from HubSpot ' . $mode . ' selection via unified extension',
    'contacts'    => $pbContacts,
    'preset_id'   => null,
    'custom_data' => [
        'client_id' => $client_id,
        'source'    => 'hubspot-extension',
        'crm_name'  => $crmName,
        'mode'      => $mode,
    ],
    'callbacks'   => $callbacks,
    'webhook_meta'=> [
        'session_token' => $session_token,
        'client_id'     => $client_id,
        'crm_name'      => $crmName,
        'mode'          => $mode,
    ],
];

$t0 = microtime(true);
list($info, $resp) = pb_api_call($pat, 'POST', '/dialsession', $payload);
$pb_ms = (int) round((microtime(true) - $t0) * 1000);

$httpCode = (int)($info['http_code'] ?? 0);

if ($httpCode >= 400 || !is_array($resp)) {
    api_log('hubspot_selection.error.pb_api', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'mode'           => $mode,
        'status'         => $httpCode,
        'pb_ms'          => $pb_ms,
    ]);
    api_error('PhoneBurner API error', 'pb_api_error', $httpCode ?: 500, [
        'status' => $httpCode,
    ]);
}

$launch_url     = $resp['dialsessions']['redirect_url'] ?? null;
$dialsession_id = $resp['dialsessions']['id'] ?? null;

if (!$launch_url) {
    api_log('hubspot_selection.error.no_launch_url', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'mode'           => $mode,
        'pb_ms'          => $pb_ms,
    ]);
    api_error('No launch URL in PhoneBurner response', 'pb_bad_response', 502);
}

// ---------------------------------------------------------------------
// 5) Initialize and persist session state for SSE + webhooks
// ---------------------------------------------------------------------
$state = [
    'session_token'   => $session_token,
    'created_at'      => date('c'),
    'crm'             => $crmName,
    'client_id'       => $client_id,
    'dialsession_id'  => $dialsession_id,
    'dialsession_url' => $launch_url,
    'context'         => [
        'source'    => 'hubspot-extension',
        'url'       => $sourceUrl,
        'title'     => $sourceLabel,
        'portal_id' => $portalId,
        'mode'      => $mode,
    ],
    'current'         => null,
    'last_call'       => null,
    'stats'           => [
        'total_calls'  => 0,
        'connected'    => 0,
        'appointments' => 0,
        'by_status'    => [],
    ],
    'contacts_map'    => $contacts_map,
];

save_session_state($session_token, $state);

api_log('hubspot_selection.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'mode'           => $mode,
    'contacts_sent'  => count($pbContacts),
    'skipped'        => $skipped,
    'hs_calls'       => $hsCalls,
    'hs_ms_sum'      => $hsMsSum,
    'pb_ms'          => $pb_ms,
]);

// IMPORTANT: keep FLAT keys for extension compatibility.
// Provide BOTH launch_url and dialsession_url to be safe.
api_ok_flat([
    'session_token'   => $session_token,
    'launch_url'      => $launch_url,
    'dialsession_url' => $launch_url,
    'contacts_sent'   => count($pbContacts),
    'skipped'         => $skipped,
    'mode'            => $mode,
    'hs_ms'           => $hsMsSum,
    'pb_ms'           => $pb_ms,
]);
