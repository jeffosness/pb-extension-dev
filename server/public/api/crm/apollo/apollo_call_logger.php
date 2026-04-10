<?php
// server/public/api/crm/apollo/apollo_call_logger.php
//
// Logs call activities back to Apollo after each PhoneBurner call_done webhook.
// Called from webhooks/call_done.php when crm_name === 'apollo'.
//
// Self-contained: uses direct curl (no bootstrap.php dependency).
// Uses utils.php functions: load_apollo_tokens(), save_apollo_tokens(), cfg(), log_msg()
//
// Key behaviors:
// - Completes the Apollo task (advances contact to next sequence step)
// - Logs call activity with disposition, duration, and notes
// - Optionally removes contact from sequence on positive outcomes

/**
 * Log a completed call to Apollo and complete the associated task.
 *
 * @param array  $state   Session state (contains client_id, contacts_map, crm_name)
 * @param array  $payload Raw call_done webhook payload from PhoneBurner
 * @param array  $lastCall Parsed call data (status, duration, connected, etc.)
 * @param string $status  PB disposition status text (e.g., "No Answer", "Appointment")
 */
function apollo_log_call(array $state, array $payload, array $lastCall, string $status): void {
    $clientId = $state['client_id'] ?? '';
    if ($clientId === '') return;

    $apolloTokens = load_apollo_tokens($clientId);
    if (!is_array($apolloTokens) || empty($apolloTokens['access_token'])) return;

    $accessToken = (string)$apolloTokens['access_token'];

    // -------------------------------------------------------------------------
    // Refresh token if expired (dial sessions can last > 1 hour)
    // -------------------------------------------------------------------------
    $expiresAt = isset($apolloTokens['expires_at']) ? (int)$apolloTokens['expires_at'] : 0;
    if ($expiresAt > 0 && time() >= $expiresAt) {
        $refreshToken = $apolloTokens['refresh_token'] ?? '';
        if ($refreshToken !== '') {
            $apolloCfg = cfg();
            $ch = curl_init('https://app.apollo.io/api/v1/oauth/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $apolloCfg['APOLLO_CLIENT_ID'] ?? '',
                    'client_secret' => $apolloCfg['APOLLO_CLIENT_SECRET'] ?? '',
                    'refresh_token' => $refreshToken,
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT => 10,
            ]);
            $refreshRaw = curl_exec($ch);
            $refreshCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $refreshResp = ($refreshCode >= 200 && $refreshCode < 300 && $refreshRaw)
                ? json_decode($refreshRaw, true) : null;

            if (is_array($refreshResp) && !empty($refreshResp['access_token'])) {
                $accessToken = (string)$refreshResp['access_token'];
                if (empty($refreshResp['refresh_token'])) {
                    $refreshResp['refresh_token'] = $refreshToken;
                }
                $now = time();
                $expiresIn = isset($refreshResp['expires_in']) ? (int)$refreshResp['expires_in'] : 3600;
                $refreshResp['created_at'] = $now;
                $refreshResp['expires_at'] = $now + max(0, $expiresIn - 60);
                save_apollo_tokens($clientId, $refreshResp);
                log_msg('apollo_call_log_token_refresh: success');
            } else {
                log_msg('apollo_call_log_token_refresh: failed (http=' . $refreshCode . ')');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Find the CALLED contact from the payload (not $state['current'])
    // -------------------------------------------------------------------------
    $calledExternalId = trim((string)(
        $payload['contact']['external_id'] ?? $payload['external_id'] ?? ''
    ));

    $contactsMap = $state['contacts_map'] ?? [];
    $mapEntry = ($calledExternalId !== '' && isset($contactsMap[$calledExternalId]))
        ? $contactsMap[$calledExternalId] : null;

    if (!$mapEntry) return;

    $apolloContactId = $calledExternalId;
    $apolloTaskId    = $mapEntry['apollo_task_id'] ?? '';
    $apolloSeqId     = $mapEntry['apollo_sequence_id'] ?? '';

    // -------------------------------------------------------------------------
    // HTTP helpers (self-contained, no bootstrap dependency)
    // -------------------------------------------------------------------------
    $apolloPost = function($url, $body) use ($accessToken) {
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
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $raw ? json_decode($raw, true) : null, $raw];
    };

    $apolloPut = function($url, $body) use ($accessToken) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $raw ? json_decode($raw, true) : null, $raw];
    };

    // -------------------------------------------------------------------------
    // 1) Complete the Apollo task (advances contact to next sequence step)
    // -------------------------------------------------------------------------
    if ($apolloTaskId !== '') {
        // Try POST to mark task complete — Apollo may use POST /v1/tasks/{id}/complete
        // or PUT /v1/tasks/{id} with completed flag. Try both patterns.
        list($taskCode, $taskResp, $taskRaw) = $apolloPost(
            'https://api.apollo.io/v1/tasks/' . rawurlencode($apolloTaskId) . '/complete',
            []
        );

        // If /complete endpoint doesn't exist, try PUT with status
        if ($taskCode === 404 || $taskCode === 405) {
            list($taskCode, $taskResp, $taskRaw) = $apolloPut(
                'https://api.apollo.io/v1/tasks/' . rawurlencode($apolloTaskId),
                ['completed' => true, 'status' => 'completed']
            );
        }

        log_msg('apollo_task_complete: ' . json_encode([
            'http_code'  => $taskCode,
            'success'    => ($taskCode >= 200 && $taskCode < 300),
            'task_id'    => $apolloTaskId,
            'contact_id' => $apolloContactId,
            'pb_status'  => $status,
        ]));
    }

    // -------------------------------------------------------------------------
    // 2) Log call activity to Apollo
    // -------------------------------------------------------------------------
    $callNotes = $payload['call_notes'] ?? [];
    if (!is_array($callNotes)) $callNotes = [];
    $callNotes = array_filter(array_map('trim', $callNotes));

    $pbStatusLower = strtolower($status);
    $pbConnected = strtolower((string)($payload['connected'] ?? '0'));

    // Map PB status to Apollo disposition
    if (strpos($pbStatusLower, 'voicemail') !== false || strpos($pbStatusLower, 'left message') !== false) {
        $apolloDisposition = 'Left Voicemail';
    } elseif (strpos($pbStatusLower, 'busy') !== false) {
        $apolloDisposition = 'Busy';
    } elseif (strpos($pbStatusLower, 'bad number') !== false || strpos($pbStatusLower, 'bad_number') !== false) {
        $apolloDisposition = 'Wrong Number';
    } elseif (strpos($pbStatusLower, 'no answer') !== false || strpos($pbStatusLower, 'did not answer') !== false) {
        $apolloDisposition = 'No Answer';
    } elseif ($pbConnected === '1') {
        $apolloDisposition = 'Connected';
    } else {
        $apolloDisposition = 'No Answer';
    }

    // Build note text
    $noteParts = ['Call via PhoneBurner: ' . ($status ?: 'Unknown')];
    if (!empty($callNotes)) {
        $noteParts[] = 'Notes: ' . implode(' | ', $callNotes);
    }
    $noteText = implode(' — ', $noteParts);

    // Recording URL
    $recordingUrl = trim((string)($payload['recording_url_public'] ?? ''));
    if ($recordingUrl !== '' && strpos($recordingUrl, 'http://') === 0) {
        $recordingUrl = 'https://' . substr($recordingUrl, 7);
    }

    $callData = [
        'contact_id'  => $apolloContactId,
        'disposition'  => $apolloDisposition,
        'duration'     => (int)($lastCall['duration'] ?? 0),
        'direction'    => 'outbound',
        'note'         => $noteText,
    ];

    if ($recordingUrl !== '' && $pbConnected === '1') {
        $callData['recording_url'] = $recordingUrl;
    }

    list($callHttpCode, $callResp, $callRaw) = $apolloPost(
        'https://api.apollo.io/v1/calls',
        $callData
    );

    // If /v1/calls doesn't exist, try /v1/activities
    if ($callHttpCode === 404 || $callHttpCode === 405) {
        $callData['type'] = 'call';
        list($callHttpCode, $callResp, $callRaw) = $apolloPost(
            'https://api.apollo.io/v1/activities',
            $callData
        );
    }

    $logData = [
        'http_code'     => $callHttpCode,
        'success'       => ($callHttpCode >= 200 && $callHttpCode < 300),
        'contact_id'    => $apolloContactId,
        'pb_status'     => $status,
        'pb_connected'  => $payload['connected'] ?? null,
        'disposition'   => $apolloDisposition,
        'has_notes'     => !empty($callNotes),
        'has_recording' => isset($callData['recording_url']),
        'has_task'      => ($apolloTaskId !== ''),
    ];
    if ($callHttpCode >= 400 && $callRaw) {
        $errBody = json_decode($callRaw, true);
        $logData['apollo_error'] = is_array($errBody) ? $errBody : substr($callRaw, 0, 500);
    }
    log_msg('apollo_call_log: ' . json_encode($logData));

    // -------------------------------------------------------------------------
    // 3) Handle sequence exit for positive outcomes
    // -------------------------------------------------------------------------
    $shouldExitSequence = false;
    if (strpos($pbStatusLower, 'appointment') !== false) $shouldExitSequence = true;
    if (strpos($pbStatusLower, 'not interested') !== false) $shouldExitSequence = true;
    if (strpos($pbStatusLower, 'do not call') !== false) $shouldExitSequence = true;

    if ($shouldExitSequence && $apolloSeqId !== '') {
        list($exitCode, $exitResp, $_exitRaw) = $apolloPost(
            'https://api.apollo.io/v1/emailer_campaigns/' . rawurlencode($apolloSeqId) . '/remove_or_stop_contact_ids',
            ['contact_ids' => [$apolloContactId]]
        );

        log_msg('apollo_sequence_exit: ' . json_encode([
            'http_code'   => $exitCode,
            'success'     => ($exitCode >= 200 && $exitCode < 300),
            'sequence_id' => $apolloSeqId,
            'contact_id'  => $apolloContactId,
            'pb_status'   => $status,
        ]));
    }
}
