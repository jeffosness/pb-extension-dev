<?php
// server/public/api/crm/forth/forth_call_logger.php
//
// Logs call activity back to Forth CRM after each PhoneBurner call_done webhook.
// Called from webhooks/call_done.php when crm_name === 'forth'.
//
// Self-contained (webhook context — no bootstrap.php). Uses utils.php functions:
//   load_forth_tokens(), save_forth_tokens(), describe_api_failure(),
//   _pb_write_api_log(), _pb_scrub_tokens(), log_msg(), load_pb_token(), pb_api_call()
// Reuses the PURE helpers from forth_helpers.php (no api_log/api_error inside):
//   forth_token_is_expired(), forth_api_post_json(), forth_fetch_disposition_map(),
//   forth_map_pb_status_to_disposition_id(), forth_format_duration_hms(), FORTH_API_BASE
//
// Primary logging target is Forth's native Call Activity object (POST /v1/calls),
// which surfaces in the contact's "Calls" tab and the "Last Call Activity" column.
// User-entered call notes are additionally posted to /v1/contacts/{cid}/notes.
//
// STATUS: dormant until crm_name === 'forth' sessions exist (registry still lists
// Forth as generic L1). Untested end-to-end pending live API credentials. TODOs:
//   - dialer_id: PhoneBurner is not yet in Forth's dialer_id list — omitted until
//     Forth adds a "PhoneBurner" entry (raise with Forth). See GH issue.
//   - assigned_agent: only sent when the dial-session captured the Forth userID;
//     wire this in pb_dialsession_selection.php (contacts_map['assigned_agent']).

/**
 * Log a completed call to Forth CRM.
 *
 * @param array  $state    Session state (client_id, contacts_map, crm_name)
 * @param array  $payload  Raw call_done webhook payload from PhoneBurner
 * @param array  $lastCall Parsed call data (status, duration, connected, ...)
 * @param string $status   PB disposition status text (e.g. "No Answer")
 */
function forth_log_call(array $state, array $payload, array $lastCall, string $status): void {
    $clientId = $state['client_id'] ?? '';
    if ($clientId === '') {
        log_msg('forth_call_log_skip: no client_id in session state');
        return;
    }
    $clientHash = substr(hash('sha256', $clientId), 0, 12);

    $tokens = load_forth_tokens($clientId);
    if (!is_array($tokens) || empty($tokens['client_id']) || empty($tokens['client_secret'])) {
        log_msg('forth_call_log_skip: no Forth credentials for client ' . $clientHash);
        return;
    }

    require_once __DIR__ . '/forth_helpers.php';

    // -------------------------------------------------------------------------
    // Ensure a valid api_key. Mint inline (webhook-safe logging) rather than
    // calling forth_mint_access_token_or_fail(), which uses api_log/api_error
    // (unavailable in the webhook context). Mirrors close_call_logger.php.
    // -------------------------------------------------------------------------
    $apiKey = (string)($tokens['api_key'] ?? '');
    if (forth_token_is_expired($tokens)) {
        $ch = curl_init(FORTH_API_BASE . 'auth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'client_id'     => (string)$tokens['client_id'],
                'client_secret' => (string)$tokens['client_secret'],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $mintRaw  = curl_exec($ch);
        $mintInfo = curl_getinfo($ch);
        $mintErr  = curl_error($ch);
        curl_close($ch);
        $mintInfo['raw_body'] = is_string($mintRaw) ? $mintRaw : '';
        if ($mintErr !== '') $mintInfo['curl_error'] = $mintErr;

        $mintCode = (int)($mintInfo['http_code'] ?? 0);
        // Decode regardless of status so the failure branch hands describe_api_failure
        // the DECODED error body (preserves Forth's own message). Success is still
        // gated on a non-empty api_key below.
        $mintResp = (is_string($mintRaw) && $mintRaw !== '') ? json_decode($mintRaw, true) : null;
        $mintInner = (is_array($mintResp) && isset($mintResp['response']) && is_array($mintResp['response']))
            ? $mintResp['response'] : $mintResp;
        $newKey = is_array($mintInner) ? (string)($mintInner['api_key'] ?? '') : '';

        if ($newKey !== '') {
            $now = time();
            $expiresIn = isset($mintInner['expires_in']) ? (int)$mintInner['expires_in'] : 864000;
            $tokens['api_key']    = $newKey;
            $tokens['created_at'] = $now;
            $tokens['expires_at'] = $now + max(0, $expiresIn - 3600);
            save_forth_tokens($clientId, $tokens);
            $apiKey = $newKey;
            log_msg('forth_call_log_token_mint: success');
        } else {
            // Capture Forth's own error text (not just an HTTP code). Route
            // through _pb_write_api_log so it works without bootstrap.
            $fail = describe_api_failure($mintInfo, $mintResp);
            _pb_write_api_log('forth_call_log_token_mint.error', [
                'status'       => $fail['status'],
                'provider_msg' => $fail['message'],
                'response'     => $fail['response'],
                'body_snippet' => $fail['body_snippet'],
                'curl_error'   => $fail['curl_error'],
            ]);
            log_msg('forth_call_log_token_mint: failed (http=' . $mintCode . ')');
        }
    }

    if ($apiKey === '') {
        log_msg('forth_call_log_skip: no valid api_key for client ' . $clientHash);
        return;
    }

    // -------------------------------------------------------------------------
    // Find the CALLED contact from the payload. Robust multi-path lookup — PB
    // includes external_crm_data on contact_displayed but NOT on call_done, so
    // fall back to external_id and then a PB contact API fetch. The contacts_map
    // key is the Forth cid. See reference_pb_webhook_contact_id_lookup.
    // -------------------------------------------------------------------------
    $contactsMap = $state['contacts_map'] ?? [];
    $calledExternalId = '';

    $ecd =
        $payload['external_crm'] ??
        $payload['external_crm_data'] ??
        ($payload['contact']['external_crm'] ?? null) ??
        ($payload['contact']['external_crm_data'] ?? null) ??
        null;

    if (is_array($ecd)) {
        foreach ($ecd as $row) {
            if (!is_array($row)) continue;
            $crmId = trim((string)($row['crm_id'] ?? ''));
            if ($crmId !== '' && isset($contactsMap[$crmId])) { $calledExternalId = $crmId; break; }
        }
        if ($calledExternalId === '') {
            foreach ($ecd as $row) {
                if (!is_array($row)) continue;
                $crmId = trim((string)($row['crm_id'] ?? ''));
                if ($crmId !== '') { $calledExternalId = $crmId; break; }
            }
        }
    }

    if ($calledExternalId === '') {
        $calledExternalId = trim((string)(
            $payload['contact']['external_id'] ?? $payload['external_id'] ?? ''
        ));
    }

    $mapEntry = ($calledExternalId !== '' && isset($contactsMap[$calledExternalId]))
        ? $contactsMap[$calledExternalId] : null;

    // Fallback: PB contact lookup by user_id to recover external_crm_data.
    if (!$mapEntry) {
        $pbUserId = trim((string)($payload['contact']['user_id'] ?? ''));
        if ($pbUserId !== '') {
            $pat = load_pb_token($clientId);
            if ($pat) {
                list($pbInfo, $pbContact) = pb_api_call($pat, 'GET', '/contacts/' . rawurlencode($pbUserId));
                $pbHttp = (int)($pbInfo['http_code'] ?? 0);
                if ($pbHttp === 200 && is_array($pbContact)) {
                    $pbEcd = $pbContact['external_crm_data'] ?? $pbContact['external_crm'] ?? null;
                    if (is_array($pbEcd)) {
                        foreach ($pbEcd as $row) {
                            if (!is_array($row)) continue;
                            $crmId = trim((string)($row['crm_id'] ?? ''));
                            if ($crmId !== '' && isset($contactsMap[$crmId])) {
                                $calledExternalId = $crmId;
                                $mapEntry = $contactsMap[$crmId];
                                log_msg('forth_call_log_pb_lookup: matched via PB contact API, user_id=' . $pbUserId);
                                break;
                            }
                        }
                    }
                } else {
                    // The PB lookup itself failed (e.g. expired PAT / non-200) —
                    // capture PB's own error text so this doesn't silently reduce
                    // to "contact not in contacts_map" below. CLAUDE.md external-
                    // call failure-logging rule.
                    $pbFail = describe_api_failure($pbInfo, $pbContact);
                    _pb_write_api_log('forth_call_log_pb_lookup.error', [
                        'status'       => $pbFail['status'],
                        'provider_msg' => $pbFail['message'],
                        'body_snippet' => $pbFail['body_snippet'],
                        'curl_error'   => $pbFail['curl_error'],
                    ]);
                }
            }
        }
    }

    if (!$mapEntry) {
        log_msg('forth_call_log_skip: contact not in contacts_map, external_id=' . substr($calledExternalId, 0, 30) . ', map_keys=' . count($contactsMap));
        return;
    }

    // Forth contactID must be numeric.
    if (!ctype_digit((string)$calledExternalId)) {
        log_msg('forth_call_log_skip: non-numeric Forth contactID=' . substr((string)$calledExternalId, 0, 30));
        return;
    }
    $forthContactId = (int)$calledExternalId;

    // -------------------------------------------------------------------------
    // Build the Call Activity payload (POST /v1/calls)
    // -------------------------------------------------------------------------
    $callNotes = $payload['call_notes'] ?? [];
    if (!is_array($callNotes)) $callNotes = [];
    $callNotes = array_values(array_filter(array_map('trim', $callNotes)));

    $noteText = 'Call via PhoneBurner: ' . ($status !== '' ? $status : 'Unknown');
    if (!empty($callNotes)) {
        $noteText .= ' — Notes: ' . implode(' | ', $callNotes);
    }

    $dispoMap = forth_fetch_disposition_map($apiKey);
    $dispoId  = forth_map_pb_status_to_disposition_id($status, (string)($payload['connected'] ?? '0'), $dispoMap);

    // call_disposition is a REQUIRED field on POST /calls (per Forth API docs).
    // If the PB status didn't map, fall back to the account's "No Answer"
    // disposition, else the first available one — never omit it.
    if ($dispoId === null && !empty($dispoMap)) {
        $dispoId = $dispoMap['no answer'] ?? reset($dispoMap);
    }
    if ($dispoId === null) {
        // Dispositions couldn't be fetched (already logged by the fetch helper).
        // A POST without call_disposition would 400, so skip rather than fire a
        // doomed request. The call still exists in PB; only the Forth mirror is
        // skipped this once.
        log_msg('forth_call_log_skip: call_disposition required but disposition list unavailable for client ' . $clientHash);
        return;
    }

    $callData = [
        'contactID'        => $forthContactId,
        'call_type'        => 'Outgoing',
        'call_disposition' => $dispoId,
        'duration'         => forth_format_duration_hms((int)($lastCall['duration'] ?? 0)),
        'notes'            => $noteText,
        // created_at / dialer_id omitted — both optional per the API docs.
    ];

    // Recording (Forth example uses https). Upgrade PB http→https defensively.
    $recordingUrl = trim((string)($payload['recording_url_public'] ?? ''));
    if ($recordingUrl !== '' && strpos($recordingUrl, 'http://') === 0) {
        $recordingUrl = 'https://' . substr($recordingUrl, 7);
    }
    $connected = strtolower((string)($payload['connected'] ?? '0')) === '1';
    if ($connected && $recordingUrl !== '' && strpos($recordingUrl, 'https://') === 0) {
        $callData['recording_url'] = $recordingUrl;
    }

    // Attribute to the assigned rep when the dial session captured a Forth userID.
    if (!empty($mapEntry['assigned_agent']) && ctype_digit((string)$mapEntry['assigned_agent'])) {
        $callData['assigned_agent'] = (int)$mapEntry['assigned_agent'];
    }

    // NOTE: dialer_id intentionally omitted — Forth has no "PhoneBurner" dialer_id
    // yet. Sending a wrong id would mislabel the call source. See GH issue.

    // -------------------------------------------------------------------------
    // 1) Create the call activity
    // -------------------------------------------------------------------------
    list($httpCode, $callResp, $rawResp, $callInfo) = forth_api_post_json($apiKey, FORTH_API_BASE . 'calls', $callData);

    $callOk  = ($httpCode >= 200 && $httpCode < 300);
    $logData = [
        'http_code'     => $httpCode,
        'success'       => $callOk,
        'contact_id'    => $forthContactId,
        'pb_status'     => $status,
        'pb_connected'  => $payload['connected'] ?? null,
        'disposition'   => $dispoId,
        'has_notes'     => !empty($callNotes),
        'has_recording' => isset($callData['recording_url']),
    ];
    if (!$callOk) {
        // ANY non-2xx (incl. http_code 0 from timeout/DNS/TLS). Route through
        // describe_api_failure so the logged body is token-scrubbed AND
        // PII-redacted — a Forth 4xx often echoes submitted fields, and our
        // `notes` embeds agent-typed call notes. $callInfo carries the curl error.
        $fail = describe_api_failure($callInfo, $callResp);
        $logData['provider_msg'] = $fail['message'];
        $logData['forth_error']  = $fail['response'];
        $logData['body_snippet'] = $fail['body_snippet'];
        $logData['curl_error']   = $fail['curl_error'];
    }
    log_msg('forth_call_log: ' . json_encode($logData));

    // -------------------------------------------------------------------------
    // 2) If the user wrote call notes, also post them as a Note on the contact
    //    (POST /v1/contacts/{contact_id}/notes). note_type=1, public=true.
    // -------------------------------------------------------------------------
    if (!empty($callNotes)) {
        list($noteCode, $_noteResp, $_noteRaw, $noteInfo) = forth_api_post_json(
            $apiKey,
            FORTH_API_BASE . 'contacts/' . $forthContactId . '/notes',
            [
                'content'   => implode("\n", $callNotes),
                'note_type' => 1,
                'public'    => true,
            ]
        );
        $noteOk  = ($noteCode >= 200 && $noteCode < 300);
        $noteLog = [
            'http_code'  => $noteCode,
            'success'    => $noteOk,
            'contact_id' => $forthContactId,
        ];
        if (!$noteOk) {
            // Any non-2xx — capture Forth's own error text + curl error.
            $nfail = describe_api_failure($noteInfo, $_noteResp);
            $noteLog['provider_msg'] = $nfail['message'];
            $noteLog['curl_error']   = $nfail['curl_error'];
        }
        log_msg('forth_note_log: ' . json_encode($noteLog));
    }
}

/**
 * Log a click-to-call (softphone) call to Forth — the SESSION-LESS variant.
 *
 * Called from webhooks/softphone_call_done.php on a CTC call, where there is NO
 * dial session (no contacts_map). Everything needed is passed in directly:
 *   - $client_id : recovered from the CTC intent bridge (carries client_id)
 *   - $forth_cid : the Forth contact id (crm_id from the softphone webhook)
 *   - $payload   : the softphone_call_done payload (status/duration/notes/recording)
 *
 * Same POST /calls shape as forth_log_call(), just fed from the softphone payload
 * instead of session state. Returns true on a 2xx call-activity create.
 * assigned_agent is omitted (no session to carry the owning agent) — a future
 * enhancement could Get Contact to recover assigned_to.
 */
function forth_log_ctc_call(string $client_id, string $forth_cid, array $payload, string $status): bool {
    if ($client_id === '' || !ctype_digit($forth_cid)) {
        log_msg('forth_ctc_log_skip: bad client_id/cid');
        return false;
    }
    $clientHash = substr(hash('sha256', $client_id), 0, 12);

    $tokens = load_forth_tokens($client_id);
    if (!is_array($tokens) || empty($tokens['client_id']) || empty($tokens['client_secret'])) {
        log_msg('forth_ctc_log_skip: no Forth credentials for client ' . $clientHash);
        return false;
    }

    require_once __DIR__ . '/forth_helpers.php';

    // Inline mint (webhook context — no bootstrap; mirrors forth_log_call).
    $apiKey = (string)($tokens['api_key'] ?? '');
    if (forth_token_is_expired($tokens)) {
        $ch = curl_init(FORTH_API_BASE . 'auth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'client_id'     => (string)$tokens['client_id'],
                'client_secret' => (string)$tokens['client_secret'],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $mintRaw  = curl_exec($ch);
        $mintInfo = curl_getinfo($ch);
        $mintErr  = curl_error($ch);
        curl_close($ch);
        $mintInfo['raw_body'] = is_string($mintRaw) ? $mintRaw : '';
        if ($mintErr !== '') $mintInfo['curl_error'] = $mintErr;

        $mintCode  = (int)($mintInfo['http_code'] ?? 0);
        $mintResp  = (is_string($mintRaw) && $mintRaw !== '') ? json_decode($mintRaw, true) : null;
        $mintInner = (is_array($mintResp) && isset($mintResp['response']) && is_array($mintResp['response']))
            ? $mintResp['response'] : $mintResp;
        $newKey = is_array($mintInner) ? (string)($mintInner['api_key'] ?? '') : '';
        if ($newKey !== '') {
            $now = time();
            $expiresIn = isset($mintInner['expires_in']) ? (int)$mintInner['expires_in'] : 864000;
            $tokens['api_key']    = $newKey;
            $tokens['created_at'] = $now;
            $tokens['expires_at'] = $now + max(0, $expiresIn - 3600);
            save_forth_tokens($client_id, $tokens);
            $apiKey = $newKey;
        } else {
            $fail = describe_api_failure($mintInfo, $mintResp);
            _pb_write_api_log('forth_ctc_log_token_mint.error', [
                'status'       => $fail['status'],
                'provider_msg' => $fail['message'],
                'body_snippet' => $fail['body_snippet'],
                'curl_error'   => $fail['curl_error'],
            ]);
        }
    }
    if ($apiKey === '') {
        log_msg('forth_ctc_log_skip: no valid api_key for client ' . $clientHash);
        return false;
    }

    $forthContactId = (int)$forth_cid;

    // Notes text from the softphone payload (same shape as the dial-session path).
    $callNotes = $payload['call_notes'] ?? [];
    if (!is_array($callNotes)) $callNotes = [];
    $callNotes = array_values(array_filter(array_map('trim', $callNotes)));
    $noteText = 'Call via PhoneBurner (click-to-call): ' . ($status !== '' ? $status : 'Unknown');
    if (!empty($callNotes)) $noteText .= ' — Notes: ' . implode(' | ', $callNotes);

    // The softphone (CTC) payload has NO `connected` boolean — only `status`
    // text (e.g. "Connected", "No Answer"). Derive the connected flag from it so
    // disposition mapping works (otherwise "Connected" mismaps to "No Answer").
    $connectedFlag = (stripos($status, 'connect') !== false) ? '1' : '0';

    // call_disposition is REQUIRED — map, then fall back to "No Answer"/first.
    $dispoMap = forth_fetch_disposition_map($apiKey);
    $dispoId  = forth_map_pb_status_to_disposition_id($status, $connectedFlag, $dispoMap);
    if ($dispoId === null && !empty($dispoMap)) {
        $dispoId = $dispoMap['no answer'] ?? reset($dispoMap);
    }
    if ($dispoId === null) {
        log_msg('forth_ctc_log_skip: call_disposition unavailable for client ' . $clientHash);
        return false;
    }

    $callData = [
        'contactID'        => $forthContactId,
        'call_type'        => 'Outgoing',
        'call_disposition' => $dispoId,
        'duration'         => forth_format_duration_hms((int)($payload['duration'] ?? 0)),
        'notes'            => $noteText,
    ];

    // Recording: PB only provides recording_url_public for connected calls, and
    // the CTC payload has no `connected` flag to gate on — so send it whenever
    // present + https (don't gate on a field the softphone payload lacks).
    $recordingUrl = trim((string)($payload['recording_url_public'] ?? ''));
    if ($recordingUrl !== '' && strpos($recordingUrl, 'http://') === 0) {
        $recordingUrl = 'https://' . substr($recordingUrl, 7);
    }
    if ($recordingUrl !== '' && strpos($recordingUrl, 'https://') === 0) {
        $callData['recording_url'] = $recordingUrl;
    }

    list($httpCode, $callResp, $rawResp, $callInfo) = forth_api_post_json($apiKey, FORTH_API_BASE . 'calls', $callData);
    $callOk = ($httpCode >= 200 && $httpCode < 300);

    $logData = [
        'http_code'   => $httpCode,
        'success'     => $callOk,
        'contact_id'  => $forthContactId,
        'pb_status'   => $status,
        'disposition' => $dispoId,
        'source'      => 'click_to_call',
    ];
    if (!$callOk) {
        $fail = describe_api_failure($callInfo, $callResp);
        $logData['provider_msg'] = $fail['message'];
        $logData['forth_error']  = $fail['response'];
        $logData['curl_error']   = $fail['curl_error'];
    }
    log_msg('forth_ctc_log: ' . json_encode($logData));

    // User notes → also a Note on the contact.
    if (!empty($callNotes)) {
        list($noteCode, $_nR, $_nRaw, $noteInfo) = forth_api_post_json(
            $apiKey,
            FORTH_API_BASE . 'contacts/' . $forthContactId . '/notes',
            ['content' => implode("\n", $callNotes), 'note_type' => 1, 'public' => true]
        );
        if ($noteCode < 200 || $noteCode >= 300) {
            $nfail = describe_api_failure($noteInfo, $_nR);
            log_msg('forth_ctc_note_log: ' . json_encode(['http_code' => $noteCode, 'success' => false, 'provider_msg' => $nfail['message']]));
        }
    }

    return $callOk;
}
