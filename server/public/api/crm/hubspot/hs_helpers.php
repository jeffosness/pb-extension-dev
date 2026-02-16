<?php
// server/public/api/crm/hubspot/hs_helpers.php
//
// Shared HubSpot API helpers used by multiple endpoints:
//   - pb_dialsession_selection.php  (selection-based dial sessions)
//   - pb_dialsession_from_list.php  (list-based dial sessions)
//   - hs_lists.php                  (fetch available lists)
//
// Extracted from pb_dialsession_selection.php to avoid duplication.

// -----------------------------------------------------------------------------
// HubSpot API helpers (v3)
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

  // Keep refresh_token if HubSpot doesn't return a new one
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
 * POST JSON to a HubSpot API endpoint with Bearer auth.
 */
function hs_api_post_json($accessToken, $url, array $body) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer ' . $accessToken,
      'Content-Type: application/json',
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
    if (($prop['fieldType'] ?? '') === 'phonenumber') {
      $name = (string)($prop['name'] ?? '');
      // Skip HubSpot system calculated/searchable properties — they're derived
      // duplicates with ugly labels like "Calculated Phone Number without country code"
      if (strpos($name, 'hs_') === 0 && strpos($name, 'calculated') !== false) continue;

      $phoneProps[] = [
        'name'  => $name,
        'label' => (string)($prop['label'] ?? $prop['name'] ?? ''),
      ];
    }
  }

  // Sort: standard properties first (phone, mobilephone), then custom, then hs_ system props
  usort($phoneProps, function($a, $b) {
    $order = ['phone' => 0, 'mobilephone' => 1];
    $aOrder = $order[$a['name']] ?? (strpos($a['name'], 'hs_') === 0 ? 100 : 50);
    $bOrder = $order[$b['name']] ?? (strpos($b['name'], 'hs_') === 0 ? 100 : 50);
    return $aOrder - $bOrder;
  });

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
  // Deduplicate by normalized phone value — strip all non-digits, then strip
  // leading country code "1" for US numbers so +16463627327 matches 6463627327
  $seenValues = [];
  $normalizePhone = function(string $v): string {
    $digits = preg_replace('/[^0-9]/', '', $v);
    if (strlen($digits) === 11 && $digits[0] === '1') {
      $digits = substr($digits, 1);
    }
    return $digits;
  };
  if ($primary !== '') {
    $seenValues[$normalizePhone($primary)] = true;
  }

  foreach ($phoneProperties as $propDef) {
    $propName  = $propDef['name'] ?? '';
    $propLabel = $propDef['label'] ?? $propName;
    $value = trim((string)($hsProps[$propName] ?? ''));

    if ($value === '') continue;

    // Deduplicate: ignores formatting and US country code differences
    $normalized = $normalizePhone($value);
    if ($normalized === '' || isset($seenValues[$normalized])) continue;
    $seenValues[$normalized] = true;

    if ($primary === '') {
      $primary = $value;
    } else {
      // Map phone_type from property name/label: 1=Home, 2=Work, 3=Mobile
      $hint = strtolower($propName . ' ' . $propLabel);
      if (strpos($hint, 'mobile') !== false || strpos($hint, 'cell') !== false) {
        $phoneType = '3'; // Mobile
      } elseif (strpos($hint, 'home') !== false) {
        $phoneType = '1'; // Home
      } else {
        $phoneType = '2'; // Work (default)
      }

      $additional[] = [
        'number'      => $value,
        'phone_type'  => $phoneType,
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
