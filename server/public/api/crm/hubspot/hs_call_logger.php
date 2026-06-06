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
// Self-contained: uses direct curl (no bootstrap.php dependency, matching
// the close_call_logger.php and apollo_call_logger.php pattern). The
// existing hs_helpers.php refresh function can't be reused here because it
// calls api_error()/api_log() which require bootstrap.php — loading
// bootstrap.php from a webhook handler would change the request lifecycle
// (CORS headers, JSON content-type, etc.).
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
    // by grepping app.log for 'hs_call_log:'.
    log_msg('hs_call_log: invoked launch_source=' . ($state['launch_source'] ?? '(none)'));

    // Gate: only fire for Task Queue dial sessions. Selection/list flows have
    // no hs_task_ids in contacts_map and never set launch_source.
    if (($state['launch_source'] ?? '') !== 'queue-tasks') {
        log_msg('hs_call_log: skipping — launch_source is not queue-tasks');
        return;
    }

    $clientId = $state['client_id'] ?? '';
    if ($clientId === '') {
        log_msg('hs_call_log: skipping — no client_id in state');
        return;
    }

    $hsTokens = load_hs_tokens($clientId);
    if (!is_array($hsTokens)) {
        log_msg('hs_call_log: skipping — no HubSpot tokens for client');
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
            log_msg('hs_call_log: token refresh failed, skipping task completion');
            return;
        }
        $hsTokens = $refreshed;
    }

    $accessToken = (string)($hsTokens['access_token'] ?? '');
    if ($accessToken === '') {
        log_msg('hs_call_log: skipping — empty access_token after load/refresh');
        return;
    }

    // -------------------------------------------------------------------------
    // Find the CALLED contact from the payload.
    //
    // CRITICAL: PB's `payload.contact.external_id` is PhoneBurner's OWN
    // identifier (often a Salesforce-style ID from PB's internal Salesforce
    // sync), NOT the HubSpot ID we stored in `external_crm_data` when creating
    // the session. We have to iterate the external_crm array PB returns in
    // the webhook payload and find the entry with our HubSpot crm_id.
    //
    // Mirrors the lookup logic in webhooks/contact_displayed.php's
    // extract_contact_lookup_key() — for the same reason: PB's external_id
    // can't be relied on to round-trip.
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

    if (!$mapEntry) {
        $contactsMapKeys = is_array($contactsMap) ? array_slice(array_keys($contactsMap), 0, 5) : [];
        log_msg('hs_call_log: skipping — no candidate matched contacts_map. candidates=['
            . implode(',', $candidates)
            . '] map_keys=[' . implode(',', $contactsMapKeys) . ']');
        return;
    }

    $taskIds = $mapEntry['hs_task_ids'] ?? [];
    if (!is_array($taskIds) || empty($taskIds)) {
        log_msg('hs_call_log: skipping — no hs_task_ids on map entry for contact ' . $calledExternalId);
        return;
    }

    log_msg('hs_call_log: completing ' . count($taskIds) . ' task(s) for contact ' . $calledExternalId);

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

    log_msg('hs_call_log: tasks_completed=' . $completed
        . ' tasks_failed=' . $failed
        . ' contact=' . $calledExternalId
        . ' status=' . substr($status, 0, 30));
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

    log_msg('hs_call_log: task_complete_failed task=' . $taskId . ' http=' . $code);
    return false;
}

/**
 * Refresh access token with dual-credential fallback.
 *
 * Mirrors hs_refresh_access_token_or_fail() in hs_helpers.php but is
 * self-contained (no bootstrap dependency, no api_error()/api_log() calls).
 * Returns the refreshed tokens array on success, null on any failure.
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

    // Attempt 1: primary credentials
    list($code, $resp) = hs_call_logger_post_refresh($refreshToken, $primaryId, $primarySecret);

    // Attempt 2: legacy fallback if primary returned 4xx AND legacy creds exist
    if (($code >= 400 && $code < 500) && $legacyId && $legacySecret) {
        list($code, $resp) = hs_call_logger_post_refresh($refreshToken, $legacyId, $legacySecret);
        if ($code >= 200 && $code < 300 && is_array($resp)) {
            log_msg('hs_call_log: refreshed via legacy creds');
        }
    }

    if ($code < 200 || $code >= 300 || !is_array($resp)) {
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
 * Returns [http_status, decoded_response_array_or_null].
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

    $resp = ($raw && $code >= 200 && $code < 500) ? json_decode($raw, true) : null;
    return [$code, is_array($resp) ? $resp : null];
}
