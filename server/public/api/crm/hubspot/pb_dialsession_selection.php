<?php
// server/public/api/crm/hubspot/pb_dialsession_selection.php
//
// Creates a PhoneBurner dial session from a HubSpot selection (IDs) sent by the extension.
// Unified parity with /api/crm/generic/dialsession_from_scan.php:
// - json_input() / get_client_id_or_fail()
// - load_pb_token($client_id)
// - load_hs_tokens($client_id)
// - save_session_state(session_token, ...)
// - api_ok_flat([session_token, dialsession_url, ...])

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

// -----------------------------------------------------------------------------
// HubSpot API helper (v3)
// -----------------------------------------------------------------------------
function hs_refresh_access_token_or_fail(string $client_id, array $hsTokens): array {
  $cfg = cfg();
  $hsClientId     = $cfg['HS_CLIENT_ID'] ?? null;
  $hsClientSecret = $cfg['HS_CLIENT_SECRET'] ?? null;

  if (!$hsClientId || !$hsClientSecret) {
    api_error('Server missing HS_CLIENT_ID/HS_CLIENT_SECRET for token refresh', 'server_error', 500);
  }

  $refresh = $hsTokens['refresh_token'] ?? '';
  if (!$refresh) {
    api_error('HubSpot token expired and no refresh_token is available. Please reconnect HubSpot.', 'unauthorized', 401);
  }

  $t0 = microtime(true);
  list($status, $resp) = http_post_form(
    'https://api.hubapi.com/oauth/v1/token',
    [
      'grant_type'    => 'refresh_token',
      'client_id'     => $hsClientId,
      'client_secret' => $hsClientSecret,
      'refresh_token' => $refresh,
    ]
  );
  $hs_ms = (int) round((microtime(true) - $t0) * 1000);

  if ($status < 200 || $status >= 300 || !is_array($resp)) {
    api_log('hubspot_refresh.error', [
      'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
      'status' => (int)$status,
      'hs_ms' => $hs_ms,
    ]);
    api_error('HubSpot token refresh failed. Please reconnect HubSpot.', 'unauthorized', 401);
  }

  // Keep refresh_token if HubSpot doesn’t return a new one
  if (empty($resp['refresh_token'])) {
    $resp['refresh_token'] = $refresh;
  }

  $now        = time();
  $expires_in = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 1800;
  $resp['created_at'] = $now;
  $resp['expires_at'] = $now + max(0, $expires_in - 60);

  save_hs_tokens($client_id, $resp);

  api_log('hubspot_refresh.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'hub_id' => $resp['hub_id'] ?? null,
    'hs_ms' => $hs_ms,
  ]);

  return $resp;
}
function hs_fetch_contacts_with_refresh_retry(string $client_id, array &$hs, string &$hsAccess, array $ids, array $phoneProperties = [], array &$diag = []) {
  $contacts = hs_fetch_contacts_by_ids($hsAccess, $ids, $phoneProperties, $diag);

  $lastHttp = $diag['contacts_fetch']['last_http'] ?? null;
  if (empty($contacts) && $lastHttp === 401) {
    // refresh + retry once
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = (string)($hs['access_token'] ?? '');
    $contacts = hs_fetch_contacts_by_ids($hsAccess, $ids, $phoneProperties, $diag);
  }

  return $contacts;
}

function hs_token_is_expired(array $hsTokens): bool {
  $exp = isset($hsTokens['expires_at']) ? (int)$hsTokens['expires_at'] : 0;
  return $exp > 0 && time() >= $exp;
}

function hs_api_get_json($accessToken, $url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $accessToken,
      'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = null;
  if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
  }
  return [$code, $json, $raw];
}

/**
 * Discover all phone-type properties for a HubSpot object type.
 * Uses file-based caching (1-hour TTL) keyed by portal hub_id + object type.
 * Falls back to hardcoded defaults on any failure.
 */
function hs_discover_phone_properties(string $accessToken, string $objectType, string $hubId): array {
  $fallbacks = [
    'contacts'  => [
      ['name' => 'phone',       'label' => 'Phone Number'],
      ['name' => 'mobilephone', 'label' => 'Mobile Phone Number'],
    ],
    'companies' => [
      ['name' => 'phone', 'label' => 'Phone Number'],
    ],
  ];
  $fallback = $fallbacks[$objectType] ?? $fallbacks['contacts'];

  $logCtx = [
    'hub_id' => $hubId ?: '(empty)',
    'object_type' => $objectType,
  ];

  // Cache check
  $cacheDir = __DIR__ . '/../../../cache';
  if (!is_dir($cacheDir)) {
    $mkdirOk = @mkdir($cacheDir, 0770, true);
    if (!$mkdirOk) {
      api_log('phone_props.cache_mkdir_fail', array_merge($logCtx, [
        'cache_dir' => $cacheDir,
      ]));
    }
  }
  $safeHubId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $hubId);
  $safeObjType = preg_replace('/[^a-zA-Z0-9_-]/', '', $objectType);
  $cacheFile   = $cacheDir . '/hs_phone_props_' . $safeHubId . '_' . $safeObjType . '.json';

  if (is_file($cacheFile)) {
    $mtime = @filemtime($cacheFile);
    if ($mtime !== false && (time() - $mtime) < 3600) {
      $cached = @json_decode(@file_get_contents($cacheFile), true);
      if (is_array($cached) && !empty($cached)) {
        api_log('phone_props.cache_hit', array_merge($logCtx, [
          'count' => count($cached),
          'names' => array_column($cached, 'name'),
          'age_sec' => time() - $mtime,
        ]));
        return $cached;
      }
    }
  }

  // Fetch from HubSpot Properties API
  $url = 'https://api.hubapi.com/crm/v3/properties/' . rawurlencode($objectType);
  list($code, $json, $_raw) = hs_api_get_json($accessToken, $url);

  if ($code !== 200 || !is_array($json) || !isset($json['results'])) {
    api_log('phone_props.api_fail', array_merge($logCtx, [
      'http_code' => $code,
      'has_results' => isset($json['results']),
      'fallback_names' => array_column($fallback, 'name'),
    ]));
    return $fallback;
  }

  $phoneProps = [];
  foreach ($json['results'] as $prop) {
    if (!is_array($prop)) continue;
    if (($prop['type'] ?? '') === 'phonenumber') {
      $phoneProps[] = [
        'name'  => (string)($prop['name'] ?? ''),
        'label' => (string)($prop['label'] ?? $prop['name'] ?? ''),
      ];
    }
  }

  if (empty($phoneProps)) {
    api_log('phone_props.none_found', array_merge($logCtx, [
      'total_properties' => count($json['results']),
      'fallback_names' => array_column($fallback, 'name'),
    ]));
    return $fallback;
  }

  api_log('phone_props.discovered', array_merge($logCtx, [
    'count' => count($phoneProps),
    'names' => array_column($phoneProps, 'name'),
    'total_properties' => count($json['results']),
  ]));

  $written = @file_put_contents($cacheFile, json_encode($phoneProps, JSON_UNESCAPED_SLASHES), LOCK_EX);
  if ($written === false) {
    api_log('phone_props.cache_write_fail', array_merge($logCtx, [
      'cache_file' => $cacheFile,
      'dir_exists' => is_dir($cacheDir),
      'dir_writable' => is_writable($cacheDir),
    ]));
  }

  return $phoneProps;
}

/**
 * Extract primary phone + additional phones from a HubSpot record's properties.
 *
 * @param array       $hsProps           HubSpot record "properties" map (name => value)
 * @param array       $phoneProperties   Array of ['name' => ..., 'label' => ...] from discovery
 * @param string|null $preferredPrimary  Optional: property name to prefer as primary (for future user config)
 * @return array ['primary' => string, 'additional' => array]
 */
function build_phone_fields_from_props(array $hsProps, array $phoneProperties, ?string $preferredPrimary = null): array {
  $primary = '';
  $additional = [];

  // If a preferred primary is specified, check it first
  if ($preferredPrimary !== null && $preferredPrimary !== '') {
    foreach ($phoneProperties as $propDef) {
      if ($propDef['name'] === $preferredPrimary) {
        $value = trim((string)($hsProps[$preferredPrimary] ?? ''));
        if ($value !== '') {
          $primary = $value;
        }
        break;
      }
    }
  }

  // Iterate all phone properties
  $seenValues = []; // Deduplicate by normalized phone value
  if ($primary !== '') {
    $seenValues[preg_replace('/[^0-9+]/', '', $primary)] = true;
  }

  foreach ($phoneProperties as $propDef) {
    $propName  = $propDef['name'] ?? '';
    $propLabel = $propDef['label'] ?? $propName;
    $value = trim((string)($hsProps[$propName] ?? ''));

    if ($value === '') continue;

    // Deduplicate by digits-only normalization (ignores formatting differences)
    $normalized = preg_replace('/[^0-9+]/', '', $value);
    if (isset($seenValues[$normalized])) continue;
    $seenValues[$normalized] = true;

    if ($primary === '') {
      $primary = $value;
    } else {
      $additional[] = [
        'number'      => $value,
        'phone_type'  => '2',
        'phone_label' => $propLabel,
      ];
    }
  }

  return ['primary' => $primary, 'additional' => $additional];
}

function extract_ids_from_records($records) {
  if (!is_array($records)) return [];
  $out = [];
  foreach ($records as $r) {
    $id = null;
    if (is_array($r)) $id = $r['id'] ?? null;
    elseif (is_scalar($r)) $id = $r;

    $id = trim((string)$id);
    if ($id !== '') $out[$id] = true;
  }
  return array_keys($out);
}

// Fetch contact objects by ID (HubSpot contacts)
function hs_fetch_contacts_by_ids($accessToken, array $contactIds, array $phoneProperties = [], &$diag = []) {
  $contacts = [];
  $diag['contacts_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  // Build properties list: base fields + all discovered phone property names
  $baseProps = ['firstname', 'lastname', 'email'];
  $phonePropNames = array_column($phoneProperties, 'name');
  $allProps = array_values(array_unique(array_merge($baseProps, $phonePropNames)));

  foreach ($contactIds as $cid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/contacts/' . rawurlencode($cid) .
           '?properties=' . rawurlencode(implode(',', $allProps));

    list($code, $json, $_raw) = hs_api_get_json($accessToken, $url);
    $diag['contacts_fetch']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      $diag['contacts_fetch']['fail']++;
      continue;
    }

    $props = $json['properties'] ?? [];
    $phoneData = build_phone_fields_from_props($props, $phoneProperties);

    // Log phone extraction details for debugging (no PII - just property presence)
    $phonePropPresence = [];
    foreach ($phoneProperties as $pd) {
      $pn = $pd['name'] ?? '';
      $pv = trim((string)($props[$pn] ?? ''));
      $phonePropPresence[$pn] = $pv !== '' ? '(has value)' : '(empty)';
    }
    $diag['contacts_fetch']['phone_extraction'][] = [
      'contact_idx' => $diag['contacts_fetch']['ok'],
      'props_requested' => $allProps,
      'phone_prop_presence' => $phonePropPresence,
      'primary' => $phoneData['primary'] !== '' ? '(has value)' : '(empty)',
      'additional_count' => count($phoneData['additional']),
    ];

    $contacts[] = [
      'hs_id'             => (string)$cid,
      'first_name'        => (string)($props['firstname'] ?? ''),
      'last_name'         => (string)($props['lastname'] ?? ''),
      'email'             => (string)($props['email'] ?? ''),
      'phone'             => $phoneData['primary'],
      'additional_phones' => $phoneData['additional'],
    ];
    $diag['contacts_fetch']['ok']++;
  }

  return $contacts;
}

/**
 * Fetch company details by HubSpot company IDs
 * Mirrors hs_fetch_contacts_by_ids pattern
 */
function hs_fetch_companies_by_ids($accessToken, array $companyIds, array $phoneProperties = [], &$diag = []) {
  $companies = [];
  $diag['companies_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  // Build properties list: base fields + all discovered phone property names
  $baseProps = ['name', 'domain', 'city', 'state'];
  $phonePropNames = array_column($phoneProperties, 'name');
  $allProps = array_values(array_unique(array_merge($baseProps, $phonePropNames)));

  foreach ($companyIds as $cid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/companies/' . rawurlencode($cid) .
           '?properties=' . rawurlencode(implode(',', $allProps));

    list($code, $json, $_raw) = hs_api_get_json($accessToken, $url);
    $diag['companies_fetch']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      $diag['companies_fetch']['fail']++;
      continue;
    }

    $props = $json['properties'] ?? [];
    $phoneData = build_phone_fields_from_props($props, $phoneProperties);

    // Log phone extraction details for debugging (no PII - just property presence)
    $phonePropPresence = [];
    foreach ($phoneProperties as $pd) {
      $pn = $pd['name'] ?? '';
      $pv = trim((string)($props[$pn] ?? ''));
      $phonePropPresence[$pn] = $pv !== '' ? '(has value)' : '(empty)';
    }
    $diag['companies_fetch']['phone_extraction'][] = [
      'company_idx' => $diag['companies_fetch']['ok'],
      'props_requested' => $allProps,
      'phone_prop_presence' => $phonePropPresence,
      'primary' => $phoneData['primary'] !== '' ? '(has value)' : '(empty)',
      'additional_count' => count($phoneData['additional']),
    ];

    $companies[] = [
      'hs_id'             => (string)$cid,
      'name'              => (string)($props['name'] ?? ''),
      'phone'             => $phoneData['primary'],
      'additional_phones' => $phoneData['additional'],
      'domain'            => (string)($props['domain'] ?? ''),
      'city'              => (string)($props['city'] ?? ''),
      'state'             => (string)($props['state'] ?? ''),
    ];
    $diag['companies_fetch']['ok']++;
  }

  return $companies;
}

/**
 * Wrapper with token refresh retry for companies
 * Mirrors hs_fetch_contacts_with_refresh_retry pattern
 */
function hs_fetch_companies_with_refresh_retry($client_id, $hs, $hsAccess, array $companyIds, array $phoneProperties = [], &$diag = []) {
  $companies = hs_fetch_companies_by_ids($hsAccess, $companyIds, $phoneProperties, $diag);

  // Retry with refresh if 401
  if (empty($companies) && ($diag['companies_fetch']['last_http'] ?? null) === 401) {
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = $hs['access_token'];
    $companies = hs_fetch_companies_by_ids($hsAccess, $companyIds, $phoneProperties, $diag);
  }

  return $companies;
}

// NEW: Resolve associated contact IDs AND return a map of contactId => [sourceObjectIds...]
// Used so external_crm_data can include deal/company IDs alongside the contact ID.
function hs_resolve_contact_ids_map_from_objects($accessToken, $objectType, array $objectIds, &$diag = []) {
  $contactIds = [];
  $contactToSourceIds = []; // [contactId => [sourceId => true]]

  $diag['assoc_resolve'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  foreach ($objectIds as $oid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/' . rawurlencode($objectType) . '/' . rawurlencode($oid) .
           '?associations=contacts&archived=false';

    list($code, $json, $_raw) = hs_api_get_json($accessToken, $url);
    $diag['assoc_resolve']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      $diag['assoc_resolve']['fail']++;
      continue;
    }

    $assoc = $json['associations']['contacts']['results'] ?? [];
    if (!is_array($assoc)) {
      $diag['assoc_resolve']['fail']++;
      continue;
    }

    foreach ($assoc as $row) {
      $cid = (string)($row['id'] ?? '');
      if ($cid === '') continue;

      $contactIds[$cid] = true;

      if (!isset($contactToSourceIds[$cid])) $contactToSourceIds[$cid] = [];
      $contactToSourceIds[$cid][(string)$oid] = true;
    }

    $diag['assoc_resolve']['ok']++;
  }

  // Convert to arrays
  $contactIdsList = array_keys($contactIds);

  $map = [];
  foreach ($contactToSourceIds as $cid => $srcSet) {
    $map[$cid] = array_keys($srcSet);
  }

  return [$contactIdsList, $map];
}


// -----------------------------------------------------------------------------
// PhoneBurner API compatibility wrapper (unified projects vary)
// -----------------------------------------------------------------------------
function pb_call_dialsession($pat, array $payload) {
  // Prefer pb_api_call if present
  if (function_exists('pb_api_call')) {
    return pb_api_call($pat, 'POST', '/dialsession', $payload); // [info, resp]
  }

  // Some unified codebases expose pb_api($pat, $method, $path, $payload)
  if (function_exists('pb_api')) {
    $resp = pb_api($pat, 'POST', '/dialsession', $payload);
    // Try to emulate pb_api_call return shape
    return [['http_code' => is_array($resp) && isset($resp['_http_code']) ? (int)$resp['_http_code'] : 200], $resp];
  }

  api_error('PhoneBurner API helper not found (pb_api_call/pb_api)', 'server_error', 500);
}

// -----------------------------------------------------------------------------
// Input + tokens (Unified)
// -----------------------------------------------------------------------------
$data      = json_input();
$client_id = get_client_id_or_fail($data);
$member_user_id = resolve_member_user_id_for_client($client_id);

$pat = load_pb_token($client_id);
if (!$pat) {
  api_error('No PhoneBurner PAT saved for this client_id', 'unauthorized', 401);
}

$hs = load_hs_tokens($client_id);
if (!is_array($hs)) {
  api_error('No HubSpot tokens saved for this client_id', 'unauthorized', 401);
}

// refresh if expired
if (hs_token_is_expired($hs)) {
  $hs = hs_refresh_access_token_or_fail($client_id, $hs);
}

$hsAccess = (string)($hs['access_token'] ?? '');
if ($hsAccess === '') {
  api_error('No HubSpot access token saved for this client_id', 'unauthorized', 401);
}

$hubId = (string)($hs['hub_id'] ?? '');

$mode       = (string)($data['mode'] ?? 'contacts');
$callTarget = $data['call_target'] ?? null; // NEW: For company dual-mode
$records    = $data['records'] ?? [];
$context    = $data['context'] ?? [];

$ids = extract_ids_from_records($records);
if (empty($ids)) {
  api_error('No selected records provided', 'bad_request', 400);
}

// -----------------------------------------------------------------------------
// Build contact list from HubSpot selection
// -----------------------------------------------------------------------------
$diag = [
  'mode' => $mode,
  'selected_ids' => count($ids),
];

$hsContacts = [];
$sourceObjectIdsByContact = []; // NEW: [contactId => [dealIds...] or [companyIds...]]
$sourceObjectType = null;       // NEW: 'deals' | 'companies' | null

if ($mode === 'contacts') {
  $phonePropsContacts = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
  $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $ids, $phonePropsContacts, $diag);

} elseif ($mode === 'deals') {
  $sourceObjectType = 'deals';
  list($contactIds, $map) = hs_resolve_contact_ids_map_from_objects($hsAccess, 'deals', $ids, $diag);
  $diag['resolved_contact_ids'] = count($contactIds);

  $sourceObjectIdsByContact = is_array($map) ? $map : [];

  $phonePropsContacts = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
  $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $contactIds, $phonePropsContacts, $diag);

} elseif ($mode === 'companies') {
  $sourceObjectType = 'companies';

  // NEW: Branch based on call_target
  if ($callTarget === 'companies') {
    // **NEW PATH: Dial companies directly**
    $diag['call_target'] = 'companies';
    $diag['resolved_company_ids'] = count($ids);

    // Fetch company details (not contacts)
    $phonePropsCompanies = hs_discover_phone_properties($hsAccess, 'companies', $hubId);
    $hsCompanies = hs_fetch_companies_with_refresh_retry($client_id, $hs, $hsAccess, $ids, $phonePropsCompanies, $diag);

    // Diagnostic: Log raw company data (redacted)
    $diag['companies_raw_sample'] = !empty($hsCompanies) ? array_map(function($c) {
      return [
        'has_hs_id' => !empty($c['hs_id']),
        'has_name' => !empty($c['name']),
        'has_phone' => !empty($c['phone']),
        'phone_value' => isset($c['phone']) ? (empty(trim($c['phone'])) ? '(empty)' : '(has value)') : '(not set)',
      ];
    }, array_slice($hsCompanies, 0, 2)) : [];

    // Store companies in $hsContacts for compatibility with existing error checking
    // We'll normalize them differently in the normalization section
    $hsContacts = $hsCompanies;

  } else {
    // **EXISTING PATH: Dial contacts related to companies (backward compat)**
    $diag['call_target'] = 'contacts';
    list($contactIds, $map) = hs_resolve_contact_ids_map_from_objects($hsAccess, 'companies', $ids, $diag);
    $diag['resolved_contact_ids'] = count($contactIds);

    $sourceObjectIdsByContact = is_array($map) ? $map : [];

    $phonePropsContacts = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
    $hsContacts = hs_fetch_contacts_with_refresh_retry($client_id, $hs, $hsAccess, $contactIds, $phonePropsContacts, $diag);
  }

} else {
  api_error('Invalid mode', 'bad_request', 400);
}

// Diagnostic: log discovered phone property names (schema metadata, not PII)
$diag['phone_props'] = isset($phonePropsContacts)
  ? array_column($phonePropsContacts, 'name')
  : (isset($phonePropsCompanies) ? array_column($phonePropsCompanies, 'name') : []);

if (empty($hsContacts)) {
  // Important: return diagnostic HTTP codes for quick troubleshooting (no secrets)
  api_error('No dialable contacts returned from HubSpot selection', 'bad_request', 400, $diag);
}

// -----------------------------------------------------------------------------
// Normalize into PhoneBurner dialsession payload (Unified parity)
// -----------------------------------------------------------------------------
$session_token = bin2hex(random_bytes(16));

$pbContacts   = [];
$contacts_map = [];
$skipped      = 0;

$portalId     = (string)($context['portalId'] ?? '');
$sourceUrl    = (string)($context['url'] ?? '');
$sourceLabel  = (string)($context['title'] ?? '');

foreach ($hsContacts as $c) {
  // Handle companies vs contacts differently
  $additionalPhones = $c['additional_phones'] ?? [];

  if ($callTarget === 'companies') {
    // Company normalization
    $name  = trim((string)($c['name'] ?? ''));
    $first = $name;
    $last  = '';
    $email = ''; // Per user preference: phone-only for companies
    $phone = trim((string)($c['phone'] ?? ''));
    $hsId  = (string)($c['hs_id'] ?? '');

    if ($hsId === '') { $skipped++; continue; }
    if ($phone === '') { $skipped++; continue; } // Companies require phone

    // Record URL for follow-me (0-2 = company object type)
    $recordUrl = ($portalId !== '')
      ? ('https://app.hubspot.com/contacts/' . rawurlencode($portalId) . '/record/0-2/' . rawurlencode($hsId))
      : null;
  } else {
    // Contact normalization (existing)
    $first = trim((string)($c['first_name'] ?? ''));
    $last  = trim((string)($c['last_name'] ?? ''));
    $email = trim((string)($c['email'] ?? ''));
    $phone = trim((string)($c['phone'] ?? ''));
    $hsId  = (string)($c['hs_id'] ?? '');

    if ($hsId === '') { $skipped++; continue; }
    if ($phone === '') { $skipped++; continue; } // Phone required; email is optional

    // Record URL for follow-me (0-1 = contact object type)
    $recordUrl = ($portalId !== '')
      ? ('https://app.hubspot.com/contacts/' . rawurlencode($portalId) . '/record/0-1/' . rawurlencode($hsId))
      : null;
  }

  // We will use this as our internal key (NOT sent to PB as external_id)
  // For companies, use prefixed format to match the crm_id we send to PhoneBurner
  $externalId = ($callTarget === 'companies') ? ('HS Company ' . $hsId) : $hsId;

  // REQUIRED: external_crm_data must be an ARRAY of objects with crm_id + crm_name
  $externalCrmData = [];

  // Include the HubSpot identity (contact or company)
  // For companies, prefix ID with "HS Company " to prevent matching with existing contact records
  $externalCrmData[] = [
    'crm_id'   => ($callTarget === 'companies') ? ('HS Company ' . $hsId) : $hsId,
    'crm_name' => ($callTarget === 'companies') ? 'hubspotcompany' : 'hubspot',
  ];

  // OPTIONAL: include originating deal/company IDs as additional external_crm_data entries
  // - deals => crm_name = hubspotdeal, crm_id = dealId
  // - companies => crm_name = hubspotcompany, crm_id = companyId
  if (($mode === 'deals' || $mode === 'companies') && !empty($sourceObjectType)) {
    $srcIds = $sourceObjectIdsByContact[$hsId] ?? [];
    if (is_array($srcIds) && !empty($srcIds)) {
      $crmName = ($sourceObjectType === 'deals') ? 'hubspotdeal' : 'hubspotcompany';

      foreach ($srcIds as $srcId) {
        $srcId = trim((string)$srcId);
        if ($srcId === '') continue;

        $externalCrmData[] = [
          'crm_id'   => $srcId,
          'crm_name' => $crmName,
        ];
      }
    }
  }

  // Build PB contact WITHOUT external_id
  $pbContact = [
    'first_name'        => $first,
    'last_name'         => $last,
    'phone'             => $phone ?: null,
    'email'             => $email ?: null,
    'external_crm_data' => $externalCrmData,
  ];
  if (!empty($additionalPhones)) {
    $pbContact['additional_phone'] = $additionalPhones;
  }
  $pbContacts[] = $pbContact;


  $displayName = trim(($first !== '' || $last !== '') ? ($first . ' ' . $last) : '');

$contacts_map[$externalId] = [
  'name'           => $displayName,
  'first_name'     => $first,
  'last_name'      => $last,
  'phone'          => $phone,
  'email'          => $email,

  'source_url'     => $sourceUrl ?: null,
  'source_label'   => $sourceLabel ?: null,

  'crm_name'       => ($callTarget === 'companies') ? 'hubspotcompany' : 'hubspot',
  'crm_identifier' => $externalId,

  'record_url'     => $recordUrl ?: null,
];
}

// Add diagnostics for companies mode
if ($callTarget === 'companies') {
  $diag['companies_normalized'] = count($pbContacts);
  $diag['companies_skipped'] = $skipped;
}

if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, [
    'skipped' => $skipped,
    'hs_contacts' => count($hsContacts),
  ]);
}

$base = rtrim(cfg()['BASE_URL'] ?? '', '/');
if ($base === '') {
  api_error('Missing BASE_URL in config', 'server_error', 500);
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
  'name'        => 'HubSpot – ' . gmdate('c'),
  'contacts'    => $pbContacts,
  'preset_id'   => null,
  'custom_data' => [
    'client_id' => $client_id,
    'source'    => 'hubspot-selection',
    'crm_name'  => 'hubspot',
  ],
  'callbacks'    => $callbacks,
  'webhook_meta' => [
    'session_token' => $session_token,
    'client_id'     => $client_id,
    'crm_name'      => 'hubspot',
  ],
];

// Call PhoneBurner
$t0 = microtime(true);
list($info, $resp) = pb_call_dialsession($pat, $payload);
$pb_ms = (int) round((microtime(true) - $t0) * 1000);

$httpCode = (int)($info['http_code'] ?? 0);
if ($httpCode >= 400 || !is_array($resp)) {
  api_error('PhoneBurner dialsession failed', 'pb_error', 502, [
    'pb_http' => $httpCode,
    'pb_ms'   => $pb_ms,
  ]);
}

// -------------------------
// Extract launch URL (match dialsession_from_scan.php)
// -------------------------
$launch_url = $resp['dialsessions']['redirect_url'] ?? null;
$dial_id    = $resp['dialsessions']['id'] ?? null;

// Fallbacks (in case PB returns a slightly different shape)
if (!$launch_url) {
  $launch_url =
    $resp['dialsession']['redirect_url'] ??
    $resp['dialsession']['launch_url'] ??
    $resp['redirect_url'] ??
    $resp['launch_url'] ??
    $resp['dialsession_url'] ??
    null;

  $dial_id =
    $dial_id ??
    ($resp['dialsession']['id'] ?? null);
}

if (!$launch_url) {
  // Log keys only (no payload / no PII)
  api_log('hubspot_selection.error.no_launch_url', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ms'          => $pb_ms,
    'pb_http'        => $httpCode,
    'resp_keys'      => is_array($resp) ? array_slice(array_keys($resp), 0, 30) : null,
    'has_dialsessions' => isset($resp['dialsessions']),
  ]);

  api_error('PhoneBurner response missing launch URL', 'pb_error', 502, [
    'pb_ms' => $pb_ms,
  ]);
}

// -------------------------
// Save initial session state (match dialsession_from_scan.php shape)
// -------------------------
$state = [
  'session_token'   => $session_token,
  'dialsession_id'  => $resp['dialsessions']['id'] ?? ($resp['dialsession_id'] ?? null),
  'dialsession_url' => $launch_url,

  'client_id'       => $client_id,
  'member_user_id'  => $member_user_id,
  'created_at'      => date('c'),

  'current'         => null,
  'last_call'       => null,
  'stats'           => [
    'total_calls'  => 0,
    'connected'    => 0,
    'appointments' => 0,
  ],

  'contacts_map'    => $contacts_map,
  'crm_name'        => 'hubspot',
];

save_session_state($session_token, $state);

// -------------------------
// Generate temporary code for secure URL (not embedding token)
// -------------------------
$tempCode = temp_code_store($session_token, 300);  // 5-minute TTL

// Unified-style response (flat keys)
$response = [
  'session_token'   => $session_token,
  'temp_code'       => $tempCode,
  'dialsession_url' => $launch_url,
  'launch_url'      => $launch_url . (strpos($launch_url, '?') ? '&' : '?') . 'code=' . urlencode($tempCode),
  'contacts_sent'   => count($pbContacts),
  'skipped'         => $skipped,
  'pb_ms'           => $pb_ms,
];

// Add success message for companies mode when some were skipped
if ($callTarget === 'companies' && $skipped > 0) {
  $total = count($pbContacts) + $skipped;
  $response['success_message'] = "Created dial session with " . count($pbContacts) . " of {$total} companies (skipped {$skipped} without phone)";
}

api_ok_flat($response);

