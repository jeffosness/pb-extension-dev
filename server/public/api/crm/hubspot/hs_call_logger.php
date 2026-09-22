<?php
// server/public/api/crm/hubspot/hs_call_logger.php
//
// Marks HubSpot tasks complete after each PhoneBurner call_done webhook
// — but only for sessions launched from the Task Queue feature.
//
// Called from webhooks/call_done.php when crm_name === 'hubspot'.
// Gated internally by $state['launch_source'] === 'queue-tasks', so other
// HubSpot session types (selection-based, list-based) — which never set that
// field — are naturally inert when this function runs.
//
// Uses direct curl (matching close_/apollo_/forth_call_logger.php).
// The existing hs_helpers.php refresh function can't be reused here because
// it calls api_error() to terminate the HTTP response on failure — webhooks
// must always return 200 to avoid PhoneBurner retries doubling the log entry.
//
// api_log() IS available (webhooks now include bootstrap.php as of #228 phase 1).
// Diagnostic entries use api_log() directly.
//
// Requires the crm.objects.contacts.write OAuth scope (HubSpot's tasks
// endpoint is gated by the contacts scope). Customers on legacy demo-org
// tokens without that scope will see 403 from HubSpot here; we log the
// failure quietly and the extension's "reconnect for task queue" prompt
// (added in PR D) is the user-facing recovery path.

/**
 * Log a completed call to HubSpot by marking associated tasks complete.
 *
 * @param array  $state    Session state (contains client_id, contacts_map, launch_source)
 * @param array  $payload  Raw call_done webhook payload from PhoneBurner
 * @param array  $lastCall Parsed call data (status, duration, connected, etc.)
 * @param string $status   PB disposition status text (e.g., "No Answer", "Appointment")
 */
function hubspot_log_call(array $state, array $payload, array $lastCall, string $status): void {
    // Granular logging at each early-return so we can debug missing auto-completes
    // by grepping api.log for 'hs_call_log' event names.
    api_log('hs_call_log.invoked', ['launch_source' => $state['launch_source'] ?? null]);

    // Gate: only fire for Task Queue dial sessions. Selection/list flows have
    // no hs_task_ids in contacts_map and never set launch_source.
    if (($state['launch_source'] ?? '') !== 'queue-tasks') {
        api_log('hs_call_log.skip', ['reason' => 'launch_source_not_queue_tasks']);
        return;
    }

    $clientId = $state['client_id'] ?? '';
    if ($clientId === '') {
        api_log('hs_call_log.skip', ['reason' => 'no_client_id_in_state']);
        return;
    }

    $hsTokens = load_hs_tokens($clientId);
    if (!is_array($hsTokens)) {
        api_log('hs_call_log.skip', ['reason' => 'no_hubspot_tokens']);
        return;
    }

    // -------------------------------------------------------------------------
    // Refresh token if expired (dial sessions can last > 30 min).
    // Mirrors the dual-credential fallback in hs_refresh_access_token_or_fail
    // (hs_helpers.php) but inline here so this file stays self-contained.
    // -------------------------------------------------------------------------
    $expiresAt = isset($hsTokens['expires_at']) ? (int)$hsTokens['expires_at'] : 0;
    if ($expiresAt > 0 && time() >= $expiresAt) {
        $refreshed = hs_call_logger_refresh($clientId, $hsTokens);
        if (!is_array($refreshed)) {
            api_log('hs_call_log.skip', ['reason' => 'token_refresh_failed']);
            return;
        }
        $hsTokens = $refreshed;
    }

    $accessToken = (string)($hsTokens['access_token'] ?? '');
    if ($accessToken === '') {
        api_log('hs_call_log.skip', ['reason' => 'empty_access_token_after_refresh']);
        return;
    }

    // -------------------------------------------------------------------------
    // Find the CALLED contact from the payload.
    //
    // PB's `payload.contact.external_id` is PhoneBurner's OWN identifier
    // (often a Salesforce-style ID from PB's internal Salesforce sync), NOT
    // the HubSpot ID we stored in `external_crm_data` when creating the
    // session. AND: PB's call_done payload does not include external_crm_data
    // at all (unlike contact_displayed, which does).
    //
    // Strategy, in order:
    //   1) Iterate any external_crm / external_crm_data PB sends in the
    //      webhook (forward-compat — if PB ever starts including it).
    //   2) Try the plain external_id as a candidate (works for providers
    //      that explicitly set it at session creation, e.g. Apollo).
    //   3) Below the candidate loop: fetch the PB contact via
    //      /rest/1/contacts/{user_id} and read external_crm_data from there.
    //
    // We deliberately do NOT set external_id on PB contacts at session
    // creation for HubSpot — that path would disturb PB dedup/merge and
    // risks conflicts with the HubSpot Data Sync app and PB's native
    // HubSpot activity logger that many customers already rely on.
    //
    // Also: do NOT use $state['current'] — the contact_displayed webhook for
    // the NEXT contact fires BEFORE call_done, so $state['current'] points to
    // the wrong person at this moment.
    // -------------------------------------------------------------------------
    $candidates = [];

    $ecd = $payload['external_crm']
        ?? $payload['external_crm_data']
        ?? ($payload['contact']['external_crm'] ?? null)
        ?? ($payload['contact']['external_crm_data'] ?? null)
        ?? null;

    if (is_array($ecd)) {
        foreach ($ecd as $row) {
            if (!is_array($row)) continue;
            $crmId = trim((string)($row['crm_id'] ?? ''));
            if ($crmId !== '') $candidates[] = $crmId;
        }
    }

    // Fall back to PB's plain external_id last — works for Apollo (which sets
    // it explicitly) but not for HubSpot, so list it after the crm_id lookups.
    $legacyExtId = trim((string)(
        $payload['contact']['external_id'] ?? $payload['external_id'] ?? ''
    ));
    if ($legacyExtId !== '' && !in_array($legacyExtId, $candidates, true)) {
        $candidates[] = $legacyExtId;
    }

    $contactsMap = $state['contacts_map'] ?? [];
    $mapEntry = null;
    $calledExternalId = '';
    foreach ($candidates as $cand) {
        if (isset($contactsMap[$cand])) {
            $mapEntry = $contactsMap[$cand];
            $calledExternalId = $cand;
            break;
        }
    }

    // -------------------------------------------------------------------------
    // Fallback: PB contact API lookup via user_id.
    //
    // PB's call_done payload does NOT include external_crm_data — only its
    // own internal `external_id` (often a Salesforce-style ID from PB's
    // Salesforce sync). But the PB contact RECORD does carry external_crm_data,
    // so we fetch the contact by user_id and pull external_crm_data from there.
    //
    // This is the canonical pattern (see close_call_logger.php). It avoids
    // setting `external_id` on PB contacts at session creation, which would
    // disturb PB's dedup/merge behavior and potentially conflict with the
    // HubSpot Data Sync app and PB's built-in HubSpot activity logger.
    // -------------------------------------------------------------------------
    if (!$mapEntry) {
        $pbUserId = trim((string)($payload['contact']['user_id'] ?? $payload['user_id'] ?? ''));
        if ($pbUserId !== '') {
            $pat = load_pb_token($clientId);
            if ($pat) {
                list($pbInfo, $pbResp) = pb_api_call($pat, 'GET', '/contacts/' . rawurlencode($pbUserId));
                $pbHttpCode = (int)($pbInfo['http_code'] ?? 0);

                if ($pbHttpCode === 200 && is_array($pbResp)) {
                    // PB's /contacts/{id} response wraps the record under
                    // `contacts.contacts[0]` (confirmed via curl on the PB API).
                    // Fall back to flat access too, in case PB ever changes shape.
                    $pbRecord = $pbResp['contacts']['contacts'][0]
                        ?? ($pbResp['contacts'][0] ?? null)
                        ?? $pbResp;
                    $pbEcd = (is_array($pbRecord)
                        ? ($pbRecord['external_crm_data'] ?? $pbRecord['external_crm'] ?? null)
                        : null);

                    if (is_array($pbEcd)) {
                        foreach ($pbEcd as $row) {
                            if (!is_array($row)) continue;
                            $crmId = trim((string)($row['crm_id'] ?? ''));
                            if ($crmId !== '' && isset($contactsMap[$crmId])) {
                                $mapEntry = $contactsMap[$crmId];
                                $calledExternalId = $crmId;
                                api_log('hs_call_log.pb_lookup.matched', [
                                    'user_id' => $pbUserId,
                                    'crm_id'  => $crmId,
                                ]);
                                break;
                            }
                        }
                        if (!$mapEntry) {
                            api_log('hs_call_log.pb_lookup.no_match', [
                                'user_id'   => $pbUserId,
                                'ecd_count' => count($pbEcd),
                            ]);
                        }
                    } else {
                        api_log('hs_call_log.pb_lookup.missing_ecd', ['user_id' => $pbUserId]);
                    }
                } else {
                    api_log('hs_call_log.pb_lookup.api_error', [
                        'user_id'   => $pbUserId,
                        'http_code' => $pbHttpCode,
                    ]);
                }
            } else {
                api_log('hs_call_log.pb_lookup.skip', ['reason' => 'no_pb_pat']);
            }
        }
    }

    if (!$mapEntry) {
        $contactsMapKeys = is_array($contactsMap) ? array_slice(array_keys($contactsMap), 0, 5) : [];
        api_log('hs_call_log.skip', [
            'reason'         => 'no_candidate_matched_contacts_map',
            'candidates'     => $candidates,
            'map_keys_head'  => $contactsMapKeys,
        ]);
        return;
    }

    $taskIds = $mapEntry['hs_task_ids'] ?? [];
    if (!is_array($taskIds) || empty($taskIds)) {
        api_log('hs_call_log.skip', [
            'reason'      => 'no_hs_task_ids_on_map_entry',
            'external_id' => $calledExternalId,
        ]);
        return;
    }

    api_log('hs_call_log.completing_tasks', [
        'task_count'  => count($taskIds),
        'external_id' => $calledExternalId,
    ]);

    // -------------------------------------------------------------------------
    // PATCH each associated task to COMPLETED.
    // One contact may have multiple open call tasks in the queue (e.g., "First
    // Call" and "Follow Up" both pending for the same person). We mark them
    // all complete since a single dial nominally satisfies all of them.
    // -------------------------------------------------------------------------
    $completed = 0;
    $failed    = 0;
    foreach ($taskIds as $taskId) {
        $taskId = trim((string)$taskId);
        if ($taskId === '') continue;

        if (hs_call_logger_complete_task($accessToken, $taskId)) {
            $completed++;
        } else {
            $failed++;
        }
    }

    api_log('hs_call_log.tasks_result', [
        'completed'   => $completed,
        'failed'      => $failed,
        'external_id' => $calledExternalId,
        'status'      => substr($status, 0, 30),
    ]);
}

/**
 * Complete a single HubSpot task for a given client_id, without any
 * dial-session state.
 *
 * Loads the client's HS OAuth tokens, refreshes if expired (dual-cred
 * fallback), and PATCHes the task to hs_task_status=COMPLETED. Used by
 * the CTC-completes-task flow in softphone_call_done.php — the softphone
 * webhook has no dial-session state, but it has task_id + client_id from
 * the CTC intent bridge.
 *
 * Returns true on 2xx, false on any failure. Failures logged, never thrown.
 * Mirrors the load/refresh/complete chunk of hubspot_log_call() but skips
 * the disposition-lookup and contact-resolution steps.
 */
function hubspot_complete_task_for_client(string $clientId, string $taskId): bool {
    if ($clientId === '' || $taskId === '') return false;

    $hsTokens = load_hs_tokens($clientId);
    if (!is_array($hsTokens)) {
        api_log('hs_task_complete.skip', ['reason' => 'no_hubspot_tokens']);
        return false;
    }

    // Refresh if expired. hs_call_logger_refresh handles the dual-credential
    // fallback and returns null on any failure.
    $expiresAt = isset($hsTokens['expires_at']) ? (int)$hsTokens['expires_at'] : 0;
    if ($expiresAt > 0 && time() >= $expiresAt) {
        $refreshed = hs_call_logger_refresh($clientId, $hsTokens);
        if (!is_array($refreshed)) {
            api_log('hs_task_complete.skip', ['reason' => 'token_refresh_failed']);
            return false;
        }
        $hsTokens = $refreshed;
    }

    $accessToken = (string)($hsTokens['access_token'] ?? '');
    if ($accessToken === '') {
        api_log('hs_task_complete.skip', ['reason' => 'empty_access_token_after_refresh']);
        return false;
    }

    $ok = hs_call_logger_complete_task($accessToken, $taskId);
    api_log('hs_task_complete.result', [
        'task_id' => $taskId,
        'ok'      => $ok,
    ]);
    return $ok;
}

/**
 * PATCH a single HubSpot task to mark it COMPLETED.
 * Returns true on 2xx, false on any error. Failures are logged but never thrown.
 *
 * 403 typically means the customer's tokens don't have the contacts.write scope
 * (e.g., they're still on legacy demo-org tokens). The extension's reconnect
 * prompt is the recovery path; here we just record and continue.
 */
function hs_call_logger_complete_task(string $accessToken, string $taskId): bool {
    $url  = 'https://api.hubapi.com/crm/v3/objects/tasks/' . rawurlencode($taskId);
    $body = json_encode(['properties' => ['hs_task_status' => 'COMPLETED']]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return true;
    }

    api_log('hs_call_log.task_complete_failed', [
        'task_id'   => $taskId,
        'http_code' => $code,
    ]);
    return false;
}

/**
 * Refresh access token with dual-credential fallback.
 *
 * Mirrors hs_refresh_access_token_or_fail() in hs_helpers.php but returns
 * null on failure instead of api_error()-exiting (we're inside a webhook
 * handler; exiting would break the response to PhoneBurner). Callers must
 * gracefully handle a null return (typically: log the customer-visible
 * call activity as un-loggable, skip the task-complete PATCH).
 *
 * On failure, still logs a structured api_log breadcrumb via
 * log_api_failure_from_tuple so support can diagnose which side of the
 * refresh failed. See LESSONS.md 2026-08-02 for the class of gap this closes.
 */
function hs_call_logger_refresh(string $clientId, array $hsTokens): ?array {
    $cfg = cfg();
    $primaryId     = $cfg['HS_CLIENT_ID']        ?? null;
    $primarySecret = $cfg['HS_CLIENT_SECRET']    ?? null;
    $legacyId      = $cfg['HS_LEGACY_CLIENT_ID']     ?? null;
    $legacySecret  = $cfg['HS_LEGACY_CLIENT_SECRET'] ?? null;

    if (!$primaryId || !$primarySecret) return null;

    $refreshToken = $hsTokens['refresh_token'] ?? '';
    if ($refreshToken === '') return null;

    $clientIdHash = substr(hash('sha256', (string)$clientId), 0, 12);

    // Attempt 1: primary credentials
    list($code, $resp, $raw) = hs_call_logger_post_refresh($refreshToken, $primaryId, $primarySecret);
    $usedLegacy = false;

    // Attempt 2: legacy fallback if primary returned 4xx AND legacy creds exist
    if (($code >= 400 && $code < 500) && $legacyId && $legacySecret) {
        list($code, $resp, $raw) = hs_call_logger_post_refresh($refreshToken, $legacyId, $legacySecret);
        $usedLegacy = true;
        if ($code >= 200 && $code < 300 && is_array($resp)) {
            api_log('hs_call_log_token_refresh.legacy_creds_success', []);
        }
    }

    if ($code < 200 || $code >= 300 || !is_array($resp)) {
        // Capture HubSpot's own error text so support can distinguish
        // invalid_grant / expired refresh_token / network timeout /
        // account-suspended without shell access to the box.
        log_api_failure_from_tuple($code, $resp, $raw, 'hs_call_log_token_refresh.failed', [
            'client_id_hash' => $clientIdHash,
            'tried_legacy'   => $usedLegacy,
        ]);
        // Redundant with the tuple log above (same event, richer fields); the summary
        // line stays for behavior parity with the pre-migration log_msg. Phase 4 will
        // dedup the double-log pattern across all providers.
        api_log('hs_call_log_token_refresh.failed', ['http_code' => $code]);
        return null;
    }

    // Preserve refresh_token if HubSpot didn't return a new one
    if (empty($resp['refresh_token'])) {
        $resp['refresh_token'] = $refreshToken;
    }

    $now = time();
    $expiresIn = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 1800;
    $resp['created_at'] = $now;
    $resp['expires_at'] = $now + max(0, $expiresIn - 60);

    save_hs_tokens($clientId, $resp);
    return $resp;
}

/**
 * Internal: POST to HubSpot's token endpoint with a specific client_id/secret.
 * Returns [http_status, decoded_response_array_or_null, raw_body_string].
 * $raw is preserved so hs_call_logger_refresh's failure path can hand it
 * to log_api_failure_from_tuple for diagnostic capture.
 */
function hs_call_logger_post_refresh(string $refreshToken, string $clientId, string $clientSecret): array {
    $ch = curl_init('https://api.hubapi.com/oauth/v1/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => 10,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawStr = is_string($raw) ? $raw : '';
    $resp = ($rawStr !== '' && $code >= 200 && $code < 500) ? json_decode($rawStr, true) : null;
    return [$code, is_array($resp) ? $resp : null, $rawStr];
}
