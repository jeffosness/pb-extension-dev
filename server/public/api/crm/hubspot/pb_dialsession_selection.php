<?php
// Build a PhoneBurner dial session from a HubSpot selection.
//
// Expected JSON body from the extension (popup.js):
// {
//   "mode": "contacts" | "deals" | "companies",
//   "records": [ { "id": "123" }, { "id": "456" }, ... ],
//   "context": {
//      "objectType": "contact" | "deal" | "company",
//      "portalId": "123456",
//      "url": "...",
//      "title": "...",
//      "selectedCount": 42
//   },
//   "client_id": "pb_unified_client_id-..."
// }

require_once __DIR__ . '/../../../utils.php';

$cfg       = cfg();
$data      = json_input();
$client_id = get_client_id_or_fail($data);

$mode    = $data['mode']    ?? 'contacts';
$records = $data['records'] ?? [];
$context = $data['context'] ?? [];

// Canonical lowercase CRM name for HubSpot
$crmName = 'hubspot';


// ---------------------------------------------------------------------
// 0) Basic validation & tokens
// ---------------------------------------------------------------------

if (!is_array($records) || empty($records)) {
    json_response(['ok' => false, 'error' => 'No HubSpot records supplied'], 400);
}

$hsTokens = load_hs_tokens($client_id);
if (!$hsTokens || empty($hsTokens['access_token'])) {
    json_response([
        'ok'    => false,
        'error' => 'HubSpot is not connected for this client_id',
    ], 401);
}

$pat = load_pb_token($client_id);
if (!$pat) {
    json_response([
        'ok'    => false,
        'error' => 'No PhoneBurner PAT saved for this client_id',
    ], 401);
}

$accessToken = $hsTokens['access_token'];
$portalId    = isset($context['portalId']) ? (string)$context['portalId'] : null;

// ---------------------------------------------------------------------
// 1) Work out which CONTACT IDs we need to call
// ---------------------------------------------------------------------

/**
 * Normalize "records" array into a flat list of string IDs.
 */
function extract_ids_from_records($records)
{
    $ids = [];
    foreach ($records as $r) {
        if (is_array($r) && isset($r['id'])) {
            $ids[] = (string)$r['id'];
        } elseif (is_scalar($r)) {
            $ids[] = (string)$r;
        }
    }
    return array_values(array_unique($ids));
}

$contactIds = [];
$debugInfo  = [];

if ($mode === 'contacts') {
    // Direct contact selection – records are contact IDs
    $contactIds = extract_ids_from_records($records);
    $debugInfo['source'] = 'contacts';

} elseif ($mode === 'deals' || $mode === 'companies') {
    // Deal or company selection – we need their associated contacts
    $objType    = ($mode === 'deals') ? 'deals' : 'companies';
    $primaryIds = extract_ids_from_records($records);
    $debugInfo['source']      = $objType;
    $debugInfo['primary_ids'] = $primaryIds;

    $seenContactIds = [];

    foreach ($primaryIds as $pid) {
        // GET /crm/v3/objects/deals/{id}?associations=contacts
        // GET /crm/v3/objects/companies/{id}?associations=contacts
        list($status, $body) = hs_api_get(
            $accessToken,
            '/crm/v3/objects/' . $objType . '/' . rawurlencode($pid),
            [
                'associations' => 'contacts',
                // we don't strictly need properties for association lookup
            ]
        );

        if ($status < 200 || $status >= 300 || !is_array($body)) {
            $debugInfo['assoc_errors'][] = [
                'id'     => $pid,
                'status' => $status,
            ];
            continue;
        }

        // Shape is typically: associations.contacts.results[] = [ "id" => "123", ... ]
        $assocContacts = $body['associations']['contacts']['results'] ?? [];

        foreach ($assocContacts as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $cid = (string)$row['id'];
            if (!isset($seenContactIds[$cid])) {
                $seenContactIds[$cid] = true;
            }
        }
    }

    $contactIds = array_keys($seenContactIds);
    $debugInfo['contact_ids_from_associations'] = $contactIds;

} else {
    json_response([
        'ok'    => false,
        'error' => 'Unsupported selection mode',
        'debug' => ['mode' => $mode],
    ], 400);
}

if (empty($contactIds)) {
    json_response([
        'ok'    => false,
        'error' => 'No associated HubSpot contacts found to dial.',
        'debug' => $debugInfo,
    ], 400);
}

// ---------------------------------------------------------------------
// 2) Resolve HubSpot contact details for those IDs
// ---------------------------------------------------------------------

$contacts = [];
$skipped  = 0;

foreach ($contactIds as $id) {
    list($status, $body) = hs_api_get(
        $accessToken,
        '/crm/v3/objects/contacts/' . rawurlencode($id),
        [
            'properties' => 'firstname,lastname,phone,mobilephone,email',
        ]
    );

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

    // Build a CRM record URL if we know portalId
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
    json_response([
        'ok'    => false,
        'error' => 'No dialable HubSpot contacts (no phone or email).',
        'debug' => [
            'skipped_contacts' => $skipped,
            'inner_source'     => $debugInfo,
        ],
    ], 400);
}

// ---------------------------------------------------------------------
// 3) Build PhoneBurner contacts + session state (mirrors generic flow)
// ---------------------------------------------------------------------

$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];

$sourceUrl   = $context['url']   ?? null;
$sourceLabel = $context['title'] ?? null;

foreach ($contacts as $c) {
    $extId = 'hs-contact:' . $c['hs_id'];

$pbContacts[] = [
    'firstname'       => $c['first_name'],
    'lastname'        => $c['last_name'],
    'phone'           => $c['phone'],
    'email'           => $c['email'],
    'external_id'     => $extId,
    'external_source' => 'hubspot',

    // NEW: standard external CRM descriptor, same pattern as generic
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

$base = rtrim($cfg['BASE_URL'], '/');

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

    // SESSION-LEVEL custom data – same shape as generic
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

list($info, $resp) = pb_api_call($pat, 'POST', '/dialsession', $payload);

// Log for debugging
log_msg('hubspot pb_dialsession response: ' . json_encode([
    'http' => $info,
    'resp' => $resp,
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

$launch_url     = $resp['dialsessions']['redirect_url'] ?? null;
$dialsession_id = $resp['dialsessions']['id']          ?? null;

if (!$launch_url) {
    json_response([
        'ok'    => false,
        'error' => 'No launch URL in PhoneBurner response',
        'body'  => $resp,
    ], 502);
}


// ---------------------------------------------------------------------
// 5) Initialize and persist session state for SSE + webhooks
// ---------------------------------------------------------------------

$state = [
    'session_token' => $session_token,
    'created_at'    => date('c'),
    'crm'           => $crmName,
    'client_id'     => $client_id,
    'dialsession_id'=> $dialsession_id ?? null,
    'dialsession_url'=> $launch_url ?? null,
    'context'       => [
        'source'    => 'hubspot-extension',
        'url'       => $sourceUrl,
        'title'     => $sourceLabel,
        'portal_id' => $portalId,
        'mode'      => $mode,
    ],
    'current'       => null,
    'last_call'     => null,
    'stats'         => [
        'total_calls' => 0,
        'connected'   => 0,
        'appointments'=> 0,
        'by_status'   => [],
    ],
    'contacts_map'  => $contacts_map,
];

save_session_state($session_token, $state);

json_response([
    'ok'            => true,
    'session_token' => $session_token,
    'launch_url'    => $launch_url,
    'contacts_sent' => count($pbContacts),
    'skipped'       => $skipped,
    'mode'          => $mode,
]);

// ---------------------------------------------------------------------
// Local helper: minimal HubSpot GET wrapper
// ---------------------------------------------------------------------
function hs_api_get($accessToken, $path, array $params = [])
{
    $url = 'https://api.hubapi.com' . $path;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

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

    if ($err) {
        log_msg('hs_api_get error: ' . $err);
        return [0, null];
    }

    $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
    $data   = json_decode($resp, true);

    return [$status, $data];
}
