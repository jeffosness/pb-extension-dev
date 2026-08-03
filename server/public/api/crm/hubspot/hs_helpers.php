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

/**
 * Refresh a HubSpot OAuth token, with optional fallback to legacy credentials.
 *
 * Tries the primary HS_CLIENT_ID/HS_CLIENT_SECRET first. If HubSpot returns a
 * 4xx (typically because the refresh token was issued by a different OAuth
 * app), and HS_LEGACY_CLIENT_ID/HS_LEGACY_CLIENT_SECRET are configured, retries
 * with the legacy credentials.
 *
 * This supports the OAuth app migration where existing customer tokens were
 * issued by the previous (demo-org) app, but new connections go through the
 * new (PB-portal) app. The legacy fallback keeps existing customers refreshing
 * cleanly until they reconnect.
 */
function hs_refresh_access_token_or_fail(string $client_id, array $hsTokens): array {
  $cfg = cfg();
  $hsClientId     = $cfg['HS_CLIENT_ID'] ?? null;
  $hsClientSecret = $cfg['HS_CLIENT_SECRET'] ?? null;
  $hsLegacyId     = $cfg['HS_LEGACY_CLIENT_ID'] ?? null;
  $hsLegacySecret = $cfg['HS_LEGACY_CLIENT_SECRET'] ?? null;

  if (!$hsClientId || !$hsClientSecret) {
    api_error('Server missing HS_CLIENT_ID/HS_CLIENT_SECRET for token refresh', 'server_error', 500);
  }

  $refresh = $hsTokens['refresh_token'] ?? '';
  if (!$refresh) {
    api_error('HubSpot token expired and no refresh_token is available. Please reconnect HubSpot.', 'unauthorized', 401);
  }

  $clientIdHash = substr(hash('sha256', (string)$client_id), 0, 12);

  // Attempt 1: primary credentials
  list($status, $resp, $hs_ms, $lastInfo) = hs_attempt_token_refresh($refresh, $hsClientId, $hsClientSecret);

  // Attempt 2: legacy credentials (only if primary returned a 4xx AND legacy creds exist).
  // We don't fall back on 5xx because that's a HubSpot-side issue, not a credential mismatch.
  // Preserving $lastInfo through both attempts so describe_api_failure below
  // reflects whichever call actually errored (legacy overwrites primary iff we retried).
  $usedLegacy = false;
  if (($status >= 400 && $status < 500) && $hsLegacyId && $hsLegacySecret) {
    list($status, $resp, $legacy_ms, $lastInfo) = hs_attempt_token_refresh($refresh, $hsLegacyId, $hsLegacySecret);
    $hs_ms += $legacy_ms;
    $usedLegacy = true;
    if ($status >= 200 && $status < 300 && is_array($resp)) {
      api_log('hubspot_refresh.legacy_creds.ok', [
        'client_id_hash' => $clientIdHash,
        'status'         => (int)$status,
        'hs_ms'          => $hs_ms,
      ]);
    }
  }

  if ($status < 200 || $status >= 300 || !is_array($resp)) {
    // describe_api_failure captures HubSpot's own error text (e.g.
    // "invalid_grant", "BAD_AUTH_REFRESH_TOKEN"), not just an HTTP code.
    // This helper fires on every dial-session launch when the access token
    // is stale — hot path for un-triageable customer reports. $info here
    // comes from hs_attempt_token_refresh(); see LESSONS.md 2026-07-27.
    $fail = describe_api_failure($lastInfo, $resp);
    api_log('hubspot_refresh.error', [
      'client_id_hash' => $clientIdHash,
      'status'         => $fail['status'],
      'hs_ms'          => $hs_ms,
      'tried_legacy'   => $usedLegacy,
      'provider_msg'   => $fail['message'],
      'response'       => $fail['response'],
      'body_snippet'   => $fail['body_snippet'],
      'curl_error'     => $fail['curl_error'],
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
    'client_id_hash' => $clientIdHash,
    'hub_id'         => $resp['hub_id'] ?? null,
    'hs_ms'          => $hs_ms,
    'used_legacy'    => $usedLegacy,
  ]);

  return $resp;
}

/**
 * Internal: POST to HubSpot's token endpoint with a specific client_id/secret pair.
 * Returns [http_status, decoded_response_array_or_null, elapsed_ms, curl_info].
 *
 * $curl_info carries raw_body + curl_error so the caller can hand it to
 * describe_api_failure() on the failure path (see LESSONS.md 2026-07-27 for
 * why every external API failure must capture the provider's own error text).
 * Separate from the main refresh function so we can call it twice (primary + legacy).
 */
function hs_attempt_token_refresh(string $refreshToken, string $clientId, string $clientSecret): array {
  $t0 = microtime(true);
  list($info, $resp) = http_post_form_info(
    'https://api.hubapi.com/oauth/v1/token',
    [
      'grant_type'    => 'refresh_token',
      'client_id'     => $clientId,
      'client_secret' => $clientSecret,
      'refresh_token' => $refreshToken,
    ]
  );
  $hs_ms = (int) round((microtime(true) - $t0) * 1000);
  $status = (int)($info['http_code'] ?? 0);
  return [$status, is_array($resp) ? $resp : null, $hs_ms, $info];
}

function hs_fetch_contacts_with_refresh_retry(string $client_id, array &$hs, string &$hsAccess, array $ids, array $phoneProperties = [], array &$diag = [], ?string $preferredPrimary = null) {
  $contacts = hs_fetch_contacts_by_ids($hsAccess, $ids, $phoneProperties, $diag, $preferredPrimary);

  $lastHttp = $diag['contacts_fetch']['last_http'] ?? null;
  if (empty($contacts) && $lastHttp === 401) {
    // refresh + retry once
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = (string)($hs['access_token'] ?? '');
    $contacts = hs_fetch_contacts_by_ids($hsAccess, $ids, $phoneProperties, $diag, $preferredPrimary);
  }

  return $contacts;
}

/**
 * Fetch HubSpot task objects with contact associations.
 *
 * HubSpot's `POST /crm/v3/objects/tasks/batch/read` endpoint does NOT return
 * associations even when you include `associations: ['contacts']` in the body
 * (that parameter is silently ignored). To get task→contact links we have to
 * call the v4 associations batch endpoint separately and merge the results.
 *
 * This function does both:
 *   1. POST /crm/v3/objects/tasks/batch/read  — fetches task properties
 *   2. POST /crm/v4/associations/tasks/contacts/batch/read — fetches associations
 *
 * Then embeds the associations into each task object under
 * `task['associations']['contacts']['results']` to match what callers expect.
 *
 * Batches input in groups of 100 (HubSpot's batch endpoint limit).
 * Returns an array of task objects.
 */
function hs_fetch_tasks_by_ids($accessToken, array $taskIds, array &$diag = []) {
  $tasks = [];
  $diag['tasks_fetch']        = ['ok' => 0, 'fail' => 0, 'last_http' => null];
  $diag['associations_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  if (empty($taskIds)) return $tasks;

  // Task properties we surface — useful for diagnostics and (later) for the
  // call_done handler to construct a meaningful task-completion note if needed.
  $properties = [
    'hs_task_subject',
    'hs_task_status',
    'hs_task_type',
    'hs_timestamp',
    'hubspot_owner_id',
    'hs_queue_membership_ids',
  ];

  $batches = array_chunk(array_values($taskIds), 100);

  // -------------------------------------------------------------------------
  // Step 1: fetch task properties
  // -------------------------------------------------------------------------
  foreach ($batches as $batch) {
    $inputs = array_map(function ($id) { return ['id' => (string)$id]; }, $batch);
    $body = [
      'inputs'     => $inputs,
      'properties' => $properties,
    ];

    list($code, $json, $raw) = hs_api_post_json(
      $accessToken,
      'https://api.hubapi.com/crm/v3/objects/tasks/batch/read',
      $body
    );
    $diag['tasks_fetch']['last_http'] = $code;

    // HubSpot batch endpoints return 200 for full success and 207
    // (Multi-Status) for partial success. Both responses include a 'results'
    // array we should parse — only 4xx/5xx are total failures.
    if (($code !== 200 && $code !== 207) || !is_array($json)) {
      if (($diag['tasks_fetch']['fail'] ?? 0) === 0) {
        log_api_failure_from_tuple($code, $json, $raw, 'hs_fetch_tasks.batch_failed', [
          'batch_size' => count($batch),
        ]);
      }
      $diag['tasks_fetch']['fail'] += count($batch);
      continue;
    }

    $results = $json['results'] ?? [];
    if (is_array($results)) {
      foreach ($results as $task) {
        if (is_array($task)) {
          // Pre-initialize the associations structure so downstream code can
          // safely read $task['associations']['contacts']['results'] even when
          // a task has no associated contacts.
          if (!isset($task['associations'])) $task['associations'] = [];
          if (!isset($task['associations']['contacts'])) {
            $task['associations']['contacts'] = ['results' => []];
          }
          $tasks[] = $task;
          $diag['tasks_fetch']['ok']++;
        }
      }
    }
  }

  // End-of-loop summary — first-batch-only log names one failing batch;
  // this line captures the total scope (N batches failed out of M).
  if (($diag['tasks_fetch']['fail'] ?? 0) > 0) {
    _pb_write_api_log('hs_fetch_tasks.batch_summary', [
      'ok'        => $diag['tasks_fetch']['ok'],
      'fail'      => $diag['tasks_fetch']['fail'],
      'last_http' => $diag['tasks_fetch']['last_http'],
      'total'     => count($taskIds),
    ]);
  }

  if (empty($tasks)) return $tasks;

  // -------------------------------------------------------------------------
  // Step 2: fetch task → contact associations (v4 endpoint, separate call)
  // -------------------------------------------------------------------------
  // Index tasks by ID so we can attach associations efficiently.
  $tasksById = [];
  foreach ($tasks as $i => $t) {
    $tid = (string)($t['id'] ?? '');
    if ($tid !== '') $tasksById[$tid] = $i;
  }

  foreach ($batches as $batch) {
    $inputs = array_map(function ($id) { return ['id' => (string)$id]; }, $batch);
    $body = ['inputs' => $inputs];

    list($code, $json, $raw) = hs_api_post_json(
      $accessToken,
      'https://api.hubapi.com/crm/v4/associations/tasks/contacts/batch/read',
      $body
    );
    $diag['associations_fetch']['last_http'] = $code;

    // HubSpot's v4 associations batch endpoint returns 207 (Multi-Status) when
    // ANY of the input task IDs has no associations attached — that's most
    // batches in practice, since not every task has an associated contact.
    // The response body still includes a `results` array with the tasks that
    // DO have associations, plus a `numErrors` / `errors` block for the
    // ones that don't (or that the API couldn't resolve). We parse `results`
    // regardless; the "errors" are informational and expected.
    if (($code !== 200 && $code !== 207) || !is_array($json)) {
      if (($diag['associations_fetch']['fail'] ?? 0) === 0) {
        log_api_failure_from_tuple($code, $json, $raw, 'hs_fetch_task_associations.batch_failed', [
          'batch_size' => count($batch),
        ]);
      }
      $diag['associations_fetch']['fail'] += count($batch);
      continue;
    }

    $results = $json['results'] ?? [];
    if (!is_array($results)) continue;

    foreach ($results as $row) {
      if (!is_array($row)) continue;
      $fromId = (string)($row['from']['id'] ?? '');
      if ($fromId === '' || !isset($tasksById[$fromId])) continue;

      $taskIdx = $tasksById[$fromId];
      $associatedContacts = [];
      foreach (($row['to'] ?? []) as $toItem) {
        if (!is_array($toItem)) continue;
        // v4 response shape uses `toObjectId`; we map it to {id: ...} so the
        // downstream parser (looking under associations.contacts.results[].id)
        // sees a consistent shape regardless of which API version we used.
        $toId = $toItem['toObjectId'] ?? null;
        if ($toId === null) continue;
        $associatedContacts[] = ['id' => (string)$toId];
      }

      if (!empty($associatedContacts)) {
        $tasks[$taskIdx]['associations']['contacts']['results'] = $associatedContacts;
        $diag['associations_fetch']['ok']++;
      }
    }
  }

  if (($diag['associations_fetch']['fail'] ?? 0) > 0) {
    _pb_write_api_log('hs_fetch_task_associations.batch_summary', [
      'ok'        => $diag['associations_fetch']['ok'] ?? 0,
      'fail'      => $diag['associations_fetch']['fail'],
      'last_http' => $diag['associations_fetch']['last_http'],
      'total'     => count($taskIds),
    ]);
  }

  return $tasks;
}

/**
 * Fetch tasks with auto-refresh on 401 (same pattern as the contacts equivalent).
 */
function hs_fetch_tasks_with_refresh_retry(string $client_id, array &$hs, string &$hsAccess, array $taskIds, array &$diag = []) {
  $tasks = hs_fetch_tasks_by_ids($hsAccess, $taskIds, $diag);

  $lastHttp = $diag['tasks_fetch']['last_http'] ?? null;
  if (empty($tasks) && $lastHttp === 401) {
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = (string)($hs['access_token'] ?? '');
    $tasks = hs_fetch_tasks_by_ids($hsAccess, $taskIds, $diag);
  }

  return $tasks;
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
  list($code, $json, $raw) = hs_api_get_json($accessToken, $url);

  if ($code !== 200 || !is_array($json) || !isset($json['results'])) {
    // Silent fallback to hardcoded phone-field names when HS Properties API
    // fails means customer-mapped custom phone fields (mobile_direct, etc.)
    // silently disappear from dial sessions. Capture HS's error text so the
    // subsequent "wrong number dialed" support ticket is triageable.
    log_api_failure_from_tuple($code, $json, $raw, 'phone_props.api_fail', array_merge($logCtx, [
      'has_results'    => isset($json['results']),
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
function hs_fetch_contacts_by_ids($accessToken, array $contactIds, array $phoneProperties = [], &$diag = [], ?string $preferredPrimary = null) {
  $contacts = [];
  $diag['contacts_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  // Build properties list: base fields + all discovered phone property names
  $baseProps = ['firstname', 'lastname', 'email'];
  $phonePropNames = array_column($phoneProperties, 'name');
  $allProps = array_values(array_unique(array_merge($baseProps, $phonePropNames)));

  foreach ($contactIds as $cid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/contacts/' . rawurlencode($cid) .
           '?properties=' . rawurlencode(implode(',', $allProps));

    list($code, $json, $raw) = hs_api_get_json($accessToken, $url);
    $diag['contacts_fetch']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      // Log the FIRST per-batch failure only (avoid log spam if HS is down and
      // we're iterating hundreds of contacts). $diag already tracks counts.
      if (($diag['contacts_fetch']['fail'] ?? 0) === 0) {
        log_api_failure_from_tuple($code, $json, $raw, 'hs_fetch_contacts.per_record_failed', [
          'contact_id_sample' => (string)$cid,
        ]);
      }
      $diag['contacts_fetch']['fail']++;
      continue;
    }

    $props = $json['properties'] ?? [];
    $phoneData = build_phone_fields_from_props($props, $phoneProperties, $preferredPrimary);

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
      'preferred_primary' => $preferredPrimary,
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

  // End-of-loop summary — first-per-batch log names one failing contact ID
  // but not the total scope. This line completes the picture: N failures out
  // of M total, last HTTP code seen. Without it, support sees "one contact
  // failed" and misses that 496 more silently vanished. See LESSONS.md
  // 2026-08-02 (adversarial review finding #5).
  if (($diag['contacts_fetch']['fail'] ?? 0) > 0) {
    _pb_write_api_log('hs_fetch_contacts.batch_summary', [
      'ok'        => $diag['contacts_fetch']['ok'],
      'fail'      => $diag['contacts_fetch']['fail'],
      'last_http' => $diag['contacts_fetch']['last_http'],
      'total'     => count($contactIds),
    ]);
  }

  return $contacts;
}

/**
 * Fetch company details by HubSpot company IDs
 * Mirrors hs_fetch_contacts_by_ids pattern
 */
function hs_fetch_companies_by_ids($accessToken, array $companyIds, array $phoneProperties = [], &$diag = [], ?string $preferredPrimary = null) {
  $companies = [];
  $diag['companies_fetch'] = ['ok' => 0, 'fail' => 0, 'last_http' => null];

  // Build properties list: base fields + all discovered phone property names
  $baseProps = ['name', 'domain', 'city', 'state'];
  $phonePropNames = array_column($phoneProperties, 'name');
  $allProps = array_values(array_unique(array_merge($baseProps, $phonePropNames)));

  foreach ($companyIds as $cid) {
    $url = 'https://api.hubapi.com/crm/v3/objects/companies/' . rawurlencode($cid) .
           '?properties=' . rawurlencode(implode(',', $allProps));

    list($code, $json, $raw) = hs_api_get_json($accessToken, $url);
    $diag['companies_fetch']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      if (($diag['companies_fetch']['fail'] ?? 0) === 0) {
        log_api_failure_from_tuple($code, $json, $raw, 'hs_fetch_companies.per_record_failed', [
          'company_id_sample' => (string)$cid,
        ]);
      }
      $diag['companies_fetch']['fail']++;
      continue;
    }

    $props = $json['properties'] ?? [];
    $phoneData = build_phone_fields_from_props($props, $phoneProperties, $preferredPrimary);

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
      'preferred_primary' => $preferredPrimary,
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

  if (($diag['companies_fetch']['fail'] ?? 0) > 0) {
    _pb_write_api_log('hs_fetch_companies.batch_summary', [
      'ok'        => $diag['companies_fetch']['ok'],
      'fail'      => $diag['companies_fetch']['fail'],
      'last_http' => $diag['companies_fetch']['last_http'],
      'total'     => count($companyIds),
    ]);
  }

  return $companies;
}

/**
 * Wrapper with token refresh retry for companies
 * Mirrors hs_fetch_contacts_with_refresh_retry pattern
 */
function hs_fetch_companies_with_refresh_retry($client_id, $hs, $hsAccess, array $companyIds, array $phoneProperties = [], &$diag = [], ?string $preferredPrimary = null) {
  $companies = hs_fetch_companies_by_ids($hsAccess, $companyIds, $phoneProperties, $diag, $preferredPrimary);

  // Retry with refresh if 401
  if (empty($companies) && ($diag['companies_fetch']['last_http'] ?? null) === 401) {
    $hs = hs_refresh_access_token_or_fail($client_id, $hs);
    $hsAccess = $hs['access_token'];
    $companies = hs_fetch_companies_by_ids($hsAccess, $companyIds, $phoneProperties, $diag, $preferredPrimary);
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

    list($code, $json, $raw) = hs_api_get_json($accessToken, $url);
    $diag['assoc_resolve']['last_http'] = $code;

    if ($code !== 200 || !is_array($json)) {
      if (($diag['assoc_resolve']['fail'] ?? 0) === 0) {
        log_api_failure_from_tuple($code, $json, $raw, 'hs_resolve_contact_ids.per_record_failed', [
          'object_type'      => $objectType,
          'object_id_sample' => (string)$oid,
        ]);
      }
      $diag['assoc_resolve']['fail']++;
      continue;
    }

    $assoc = $json['associations']['contacts']['results'] ?? [];
    if (!is_array($assoc)) {
      // Silent-increment used to hide this — HubSpot shape drift (results:
      // {items:[...]}) would silently return zero contacts across a batch
      // with no breadcrumb. Log the first occurrence per invocation for
      // triage. See LESSONS.md 2026-07-03 (payload-shape guess class).
      if (($diag['assoc_resolve']['fail'] ?? 0) === 0) {
        _pb_write_api_log('hs_resolve_contact_ids.shape_unexpected', [
          'object_type'      => $objectType,
          'object_id_sample' => (string)$oid,
          'assoc_type'       => gettype($assoc),
          'response_keys'    => array_keys($json),
        ]);
      }
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

  // End-of-loop batch summary — matches the pattern in hs_fetch_contacts /
  // hs_fetch_companies / hs_fetch_tasks / close_fetch_contacts /
  // apollo_fetch_contacts. Without this, the first-per-batch failure log
  // names one object but hides the total scope (e.g. 500 of 500 failed).
  // See LESSONS.md 2026-08-03 adversarial review finding (SRE lens #1).
  if (($diag['assoc_resolve']['fail'] ?? 0) > 0) {
    _pb_write_api_log('hs_resolve_contact_ids.batch_summary', [
      'ok'          => $diag['assoc_resolve']['ok'],
      'fail'        => $diag['assoc_resolve']['fail'],
      'last_http'   => $diag['assoc_resolve']['last_http'],
      'total'       => count($objectIds),
      'object_type' => $objectType,
    ]);
  }

  // Convert to arrays
  $contactIdsList = array_keys($contactIds);

  $map = [];
  foreach ($contactToSourceIds as $cid => $srcSet) {
    $map[$cid] = array_keys($srcSet);
  }

  return [$contactIdsList, $map];
}


// pb_call_dialsession() has been moved to utils.php (shared by all L3 providers).
// The function_exists guard ensures backward compatibility if utils.php is loaded first.


// ============================================================================
// Owner-id enrichment (for per-user call-activity attribution via Salt/PB)
// ============================================================================
//
// Why this exists: HubSpot's `hubspot_owner_id` field on a call/task/note
// activity requires the CRM Owner object's `id`, NOT the auth-directory
// `user_id` from an OAuth token. They're different numbers. Sending user_id
// where owner_id is expected returns HTTP 400 with `INVALID_OWNER_ID`.
//
// For customers whose teammates share ONE PhoneBurner account but each have
// their own HubSpot user, we send `hs_owner_id` in the dial-session
// custom_data so Salt can stamp per-user attribution on the call activity
// they write. See LESSONS.md 2026-08-03 for the empirical research + the
// gotcha we caught (HubSpot's `?userId=` filter on the Owners API is broken;
// must fetch all owners and filter client-side).
//
// Design:
//   - At OAuth completion, fetch user_id via token introspection + owner_id
//     via Owners API + cache both alongside existing token fields.
//   - Lazy backfill for existing customers: when a dial-session builder
//     loads an HS token that lacks owner_id, enrich on the same request.
//     One-time cost per legacy customer, no forced reconnect.
//   - If enrichment fails (network, HubSpot down, no matching owner record),
//     log via log_api_failure_from_tuple and continue — dial-session still
//     works, just without per-user attribution. Next load retries.
//
// Related refs:
//   - memory/reference_hubspot_owner_lookup.md (empirical findings)
//   - Salt-Claude's warning about user_id vs owner_id (see PR #198 description)

/**
 * Pure parser for HubSpot's /oauth/v1/access-tokens/{token} response body.
 * Extracted so it can be unit-tested without hitting the network.
 * Returns [user_id, hub_id, email] or null when the shape is invalid.
 */
function hs_parse_introspect_response($data): ?array {
    if (!is_array($data)) return null;
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $hubId  = isset($data['hub_id'])  ? (int)$data['hub_id']  : 0;
    if ($userId <= 0 || $hubId <= 0) return null;
    return [
        'user_id' => $userId,
        'hub_id'  => $hubId,
        'email'   => (string)($data['user'] ?? ''),
    ];
}

/**
 * Introspect an HS access token — returns [user_id, hub_id, user_email] or
 * null on failure. Wraps the /oauth/v1/access-tokens/{token} endpoint.
 * Pure fetch; caller decides what to persist.
 */
function hs_introspect_access_token(string $accessToken): ?array {
    $url = 'https://api.hubapi.com/oauth/v1/access-tokens/' . rawurlencode($accessToken);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !is_string($raw)) return null;
    return hs_parse_introspect_response(json_decode($raw, true));
}

/**
 * Look up the HubSpot Owner record whose `userId` matches the given user_id.
 * Returns the owner_id (as string) or null if no matching owner exists.
 *
 * IMPORTANT: HubSpot's `?userId=` filter on this endpoint is BROKEN as of
 * 2026-08-03 — it silently returns the unfiltered list. We must fetch all
 * owners and client-side filter. Paginates via ?after= cursor. Capped at
 * 10 pages (5000 owners) for safety — no real portal should exceed that,
 * and if one does we log and give up cleanly.
 */
/**
 * Pure client-side filter — given one page of the Owners API results, find
 * the owner whose userId matches. Returns the owner_id (as string) or null.
 * Extracted so it can be unit-tested; the wrapping paginator handles I/O.
 */
function hs_find_owner_id_in_page(array $ownersPage, int $userId): ?string {
    foreach ($ownersPage as $owner) {
        if (!is_array($owner)) continue;
        $ownerUserId = isset($owner['userId']) ? (int)$owner['userId'] : 0;
        if ($ownerUserId === $userId) {
            $ownerId = (string)($owner['id'] ?? '');
            if ($ownerId !== '') return $ownerId;
        }
    }
    return null;
}

function hs_lookup_owner_id(string $accessToken, int $userId, ?string $endpointLabel = 'hs_owner_lookup'): ?string {
    $after   = null;
    $pageCount = 0;
    $maxPages  = 10; // safety cap

    while ($pageCount < $maxPages) {
        $url = 'https://api.hubapi.com/crm/v3/owners/?limit=500';
        if ($after !== null && $after !== '') {
            $url .= '&after=' . rawurlencode($after);
        }

        list($code, $json, $raw) = hs_api_get_json($accessToken, $url);

        if ($code !== 200 || !is_array($json)) {
            // First-page failure is the only one worth logging with full
            // context; subsequent pagination failures are edge cases.
            if ($pageCount === 0) {
                log_api_failure_from_tuple($code, $json, $raw, $endpointLabel . '.owners_fetch_failed', [
                    'user_id_sample' => $userId,
                ]);
            }
            return null;
        }

        $results = is_array($json['results'] ?? null) ? $json['results'] : [];
        $found = hs_find_owner_id_in_page($results, $userId);
        if ($found !== null) return $found;

        // Next-page cursor. If absent, we've read the whole list.
        $after = $json['paging']['next']['after'] ?? null;
        if ($after === null || $after === '') break;
        $pageCount++;
    }

    // Owner not found across all pages (or hit the safety cap).
    _pb_write_api_log($endpointLabel . '.owner_not_found', [
        'user_id'    => $userId,
        'pages_read' => $pageCount + 1,
        'hit_cap'    => $pageCount >= $maxPages,
    ]);
    return null;
}

/**
 * Ensure the given HS tokens array has `user_id`, `owner_id`, `owner_email`
 * cached. Idempotent: if already present, no-op. If missing, fetches via
 * introspection + owners lookup, merges, saves back to disk, and returns
 * the enriched array (which the caller should use for the rest of the
 * current request).
 *
 * Safe on failure: if enrichment fails (network, no matching owner, etc.),
 * returns the tokens unchanged so the caller can proceed without
 * per-user attribution. Next request retries.
 *
 * $endpointLabel is used for structured log context so support can trace
 * which dial-session path triggered the backfill.
 */
function hs_ensure_owner_cached(string $client_id, array $hs, string $endpointLabel = 'hs_owner_backfill'): array {
    // Already enriched — no-op.
    if (!empty($hs['owner_id']) && !empty($hs['user_id'])) {
        return $hs;
    }

    $accessToken = (string)($hs['access_token'] ?? '');
    if ($accessToken === '') return $hs; // can't enrich without a token

    // Step 1: introspect to get user_id (HS OAuth response doesn't include it).
    $introspect = hs_introspect_access_token($accessToken);
    if (!$introspect) {
        _pb_write_api_log($endpointLabel . '.introspect_failed', [
            'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        ]);
        return $hs;
    }

    // Step 2: fetch owner_id via Owners API (client-side filter).
    $ownerId = hs_lookup_owner_id($accessToken, $introspect['user_id'], $endpointLabel);

    // Step 3: merge + persist. We persist user_id + email even if owner_id
    // wasn't found — no need to re-introspect on the next request. owner_id
    // stays null; ensure_owner_cached will retry the owners lookup next time.
    $hs['user_id']     = $introspect['user_id'];
    $hs['hub_id']      = $hs['hub_id'] ?: $introspect['hub_id']; // don't overwrite an existing hub_id
    $hs['owner_email'] = $introspect['email'];
    if ($ownerId !== null) {
        $hs['owner_id'] = $ownerId;
    }
    save_hs_tokens($client_id, $hs);

    _pb_write_api_log($endpointLabel . '.enriched', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'user_id'        => $introspect['user_id'],
        'owner_id'       => $ownerId,
        'owner_found'    => $ownerId !== null,
    ]);

    return $hs;
}
