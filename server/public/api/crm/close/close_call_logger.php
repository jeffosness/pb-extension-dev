<?php
// server/public/api/crm/close/close_call_logger.php
//
// Logs call activities back to Close CRM after each PhoneBurner call_done webhook.
// Called from webhooks/call_done.php when crm_name === 'close'.
//
// Self-contained: uses direct curl (no bootstrap.php dependency).
// Uses utils.php functions: load_close_tokens(), save_close_tokens(), cfg(), log_msg()
//
// Features:
// - Creates call activity with disposition, recording, and notes
// - Auto-creates/matches Close Outcomes from PB status text
// - Creates separate Note activity if user entered call notes
// - Handles token refresh for long dial sessions (>1 hour)

/**
 * Log a completed call to Close CRM.
 *
 * @param array $state     Session state (contains client_id, contacts_map, crm_name)
 * @param array $payload   Raw call_done webhook payload from PhoneBurner
 * @param array $lastCall  Parsed call data (status, duration, connected, etc.)
 * @param string $status   PB disposition status text (e.g., "No Answer", "Appointment")
 */
function close_log_call(array $state, array $payload, array $lastCall, string $status): void {
    $clientId = $state['client_id'] ?? '';
    if ($clientId === '') {
        log_msg('close_call_log_skip: no client_id in session state');
        return;
    }

    $closeTokens = load_close_tokens($clientId);
    if (!is_array($closeTokens) || empty($closeTokens['access_token'])) {
        log_msg('close_call_log_skip: no Close tokens for client ' . substr(hash('sha256', $clientId), 0, 12));
        return;
    }

    $accessToken = (string)$closeTokens['access_token'];

    // -------------------------------------------------------------------------
    // Refresh token if expired (dial sessions can last > 1 hour)
    // -------------------------------------------------------------------------
    $expiresAt = isset($closeTokens['expires_at']) ? (int)$closeTokens['expires_at'] : 0;
    if ($expiresAt > 0 && time() >= $expiresAt) {
        $refreshToken = $closeTokens['refresh_token'] ?? '';
        if ($refreshToken !== '') {
            $closeCfg = cfg();
            $ch = curl_init('https://api.close.com/oauth2/token/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $closeCfg['CLOSE_CLIENT_ID'] ?? '',
                    'client_secret' => $closeCfg['CLOSE_CLIENT_SECRET'] ?? '',
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
                save_close_tokens($clientId, $refreshResp);
                log_msg('close_call_log_token_refresh: success');
            } else {
                log_msg('close_call_log_token_refresh: failed (http=' . $refreshCode . ')');
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

    if (!$mapEntry) {
        log_msg('close_call_log_skip: contact not in contacts_map, external_id=' . substr($calledExternalId, 0, 30) . ', map_keys=' . count($contactsMap));
        return;
    }

    $closeContactId = $calledExternalId;
    $closePhone = $mapEntry['phone'] ?? '';

    // Extract lead_id from record_url
    $closeLeadId = '';
    $recordUrl = $mapEntry['record_url'] ?? '';
    if (preg_match('/\/lead\/(lead_[a-zA-Z0-9]+)/', $recordUrl, $m)) {
        $closeLeadId = $m[1];
    }

    if ($closeLeadId === '' || $closeContactId === '') {
        log_msg('close_call_log_skip: missing lead_id=' . ($closeLeadId ?: '(empty)') . ' or contact_id=' . ($closeContactId ?: '(empty)') . ', record_url=' . substr($recordUrl, 0, 80));
        return;
    }

    // -------------------------------------------------------------------------
    // HTTP helpers (self-contained, no bootstrap dependency)
    // -------------------------------------------------------------------------
    $closePost = function($url, $body) use ($accessToken) {
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

    $closeGet = function($url) use ($accessToken) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $raw ? json_decode($raw, true) : null];
    };

    $closePut = function($url, $body) use ($accessToken) {
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
        return [$code, $raw ? json_decode($raw, true) : null];
    };

    // -------------------------------------------------------------------------
    // Resolve Close Outcome ID for the PB status
    // Uses actual PB status text as outcome name. Matches existing Close
    // outcomes by name (case-insensitive), creates if not found.
    // Cache grows dynamically as new PB dispositions are encountered.
    // -------------------------------------------------------------------------
    $pbStatusForOutcome = trim($status);
    $resolvedOutcomeId = null;

    $outcomeCacheDir = dirname(__DIR__, 3) . '/cache';
    if (!is_dir($outcomeCacheDir)) @mkdir($outcomeCacheDir, 0770, true);
    $safeClientHash = substr(hash('sha256', $clientId), 0, 16);
    $outcomeCacheFile = $outcomeCacheDir . '/close_outcomes_' . $safeClientHash . '.json';

    $outcomeCache = [];
    $outcomeCacheTtl = 86400; // 24 hours — re-fetch outcomes daily
    $cacheExpired = false;
    if (is_file($outcomeCacheFile)) {
        $cacheAge = time() - filemtime($outcomeCacheFile);
        if ($cacheAge > $outcomeCacheTtl) {
            $cacheExpired = true;
            log_msg('close_outcome_cache_expired: age=' . $cacheAge . 's, clearing');
        } else {
            $cached = @json_decode(@file_get_contents($outcomeCacheFile), true);
            if (is_array($cached)) $outcomeCache = $cached;
        }
    }

    $outcomeLookupKey = strtolower($pbStatusForOutcome);
    $resolvedOutcomeId = $cacheExpired ? null : ($outcomeCache[$outcomeLookupKey] ?? null);

    if ($pbStatusForOutcome !== '' && !$resolvedOutcomeId) {
        // Cache miss or expired — reset and rebuild from API
        if ($cacheExpired) $outcomeCache = [];
        // Fetch existing outcomes and try case-insensitive match
        list($ocCode, $ocResp) = $closeGet('https://api.close.com/api/v1/outcome/');
        if ($ocCode === 200 && is_array($ocResp) && isset($ocResp['data'])) {
            foreach ($ocResp['data'] as $oc) {
                $ocName = trim((string)($oc['name'] ?? ''));
                $ocId = (string)($oc['id'] ?? '');
                $appliesTo = $oc['applies_to'] ?? [];
                if ($ocName === '' || $ocId === '') continue;
                if (!is_array($appliesTo) || !in_array('calls', $appliesTo)) continue;

                if (strtolower($ocName) === $outcomeLookupKey) {
                    $resolvedOutcomeId = $ocId;
                    break;
                }
            }
        }

        // No match — create the outcome using the PB status name
        if (!$resolvedOutcomeId) {
            list($createCode, $createResp, $_) = $closePost(
                'https://api.close.com/api/v1/outcome/',
                ['name' => $pbStatusForOutcome, 'applies_to' => ['calls']]
            );
            if ($createCode >= 200 && $createCode < 300 && is_array($createResp) && !empty($createResp['id'])) {
                $resolvedOutcomeId = (string)$createResp['id'];
            }
        }

        // Update cache
        if ($resolvedOutcomeId) {
            $outcomeCache[$outcomeLookupKey] = $resolvedOutcomeId;
            @file_put_contents($outcomeCacheFile, json_encode($outcomeCache), LOCK_EX);
        }
    }

    // -------------------------------------------------------------------------
    // Build call activity data
    // -------------------------------------------------------------------------
    $callNotes = $payload['call_notes'] ?? [];
    if (!is_array($callNotes)) $callNotes = [];
    $callNotes = array_filter(array_map('trim', $callNotes));

    // Recording URL (Close requires HTTPS; PB may provide HTTP)
    $recordingUrl = trim((string)($payload['recording_url_public'] ?? ''));
    if ($recordingUrl !== '' && strpos($recordingUrl, 'http://') === 0) {
        $recordingUrl = 'https://' . substr($recordingUrl, 7);
    }
    $includeRecording = true; // TODO: future user setting

    // Map PB status to Close disposition
    $pbStatusLower = strtolower($status);
    $pbConnected = strtolower((string)($payload['connected'] ?? '0'));

    if (strpos($pbStatusLower, 'voicemail') !== false || strpos($pbStatusLower, 'left message') !== false) {
        $closeDisposition = 'vm-left';
    } elseif (strpos($pbStatusLower, 'live voicemail') !== false) {
        $closeDisposition = 'vm-answer';
    } elseif (strpos($pbStatusLower, 'busy') !== false) {
        $closeDisposition = 'busy';
    } elseif (strpos($pbStatusLower, 'bad number') !== false || strpos($pbStatusLower, 'bad_number') !== false) {
        $closeDisposition = 'error';
    } elseif (strpos($pbStatusLower, 'no answer') !== false || strpos($pbStatusLower, 'did not answer') !== false) {
        $closeDisposition = 'no-answer';
    } elseif ($pbConnected === '1') {
        $closeDisposition = 'answered';
    } else {
        $closeDisposition = 'no-answer';
    }

    // Build note (Close requires <body> wrapper)
    $noteParts = ['Call via PhoneBurner: ' . htmlspecialchars($status ?: 'Unknown')];
    if (!empty($callNotes)) {
        $noteParts[] = 'Notes: ' . htmlspecialchars(implode(' | ', $callNotes));
    }
    $noteHtml = '<body><p>' . implode(' &mdash; ', $noteParts) . '</p></body>';

    $callWasAnswered = ($closeDisposition === 'answered');

    $callData = [
        'lead_id'     => $closeLeadId,
        'contact_id'  => $closeContactId,
        'direction'   => 'outbound',
        'disposition' => $closeDisposition,
        'duration'    => (int)($lastCall['duration'] ?? 0),
        'phone'       => $closePhone,
        'note_html'   => $noteHtml,
    ];

    if ($includeRecording && $callWasAnswered && $recordingUrl !== '' && strpos($recordingUrl, 'https://') === 0) {
        $callData['recording_url'] = $recordingUrl;
    }

    // -------------------------------------------------------------------------
    // 1) Create call activity
    // -------------------------------------------------------------------------
    list($httpCode, $callResp, $rawResp) = $closePost(
        'https://api.close.com/api/v1/activity/call/',
        $callData
    );

    $logData = [
        'http_code'      => $httpCode,
        'success'        => ($httpCode >= 200 && $httpCode < 300),
        'lead_id'        => $closeLeadId,
        'contact_id'     => $closeContactId,
        'pb_status'      => $status,
        'pb_connected'   => $payload['connected'] ?? null,
        'disposition'    => $closeDisposition,
        'has_notes'      => !empty($callNotes),
        'has_recording'  => isset($callData['recording_url']),
    ];
    if ($httpCode >= 400 && $rawResp) {
        $errBody = json_decode($rawResp, true);
        $logData['close_error'] = is_array($errBody) ? $errBody : substr($rawResp, 0, 500);
    }
    log_msg('close_call_log: ' . json_encode($logData));

    // -------------------------------------------------------------------------
    // 2) Assign outcome to the call activity via PUT
    // -------------------------------------------------------------------------
    $callActivityId = is_array($callResp) ? ($callResp['id'] ?? null) : null;

    if ($callActivityId && $resolvedOutcomeId) {
        list($putCode, $putResp) = $closePut(
            'https://api.close.com/api/v1/activity/call/' . rawurlencode($callActivityId) . '/',
            ['outcome_id' => $resolvedOutcomeId]
        );
        $outcomeLogData = [
            'http_code'    => $putCode,
            'success'      => ($putCode >= 200 && $putCode < 300),
            'activity_id'  => $callActivityId,
            'outcome_id'   => $resolvedOutcomeId,
            'pb_status'    => $pbStatusForOutcome,
        ];
        if ($putCode >= 400 && is_array($putResp)) {
            $outcomeLogData['close_error'] = $putResp;
        }
        log_msg('close_outcome_set: ' . json_encode($outcomeLogData));

        // If outcome PUT failed with 400, cached ID may be stale — clear it
        if ($putCode === 400) {
            unset($outcomeCache[$outcomeLookupKey]);
            @file_put_contents($outcomeCacheFile, json_encode($outcomeCache), LOCK_EX);
            log_msg('close_outcome_cache_invalidated: ' . $outcomeLookupKey);
        }
    }

    // -------------------------------------------------------------------------
    // 3) If user wrote notes, create a separate Note activity on the lead
    // -------------------------------------------------------------------------
    if (!empty($callNotes)) {
        $noteBody = '<body><p>' . htmlspecialchars(implode("\n", $callNotes)) . '</p></body>';
        list($noteHttpCode, $_noteResp, $_noteRaw) = $closePost(
            'https://api.close.com/api/v1/activity/note/',
            [
                'lead_id'    => $closeLeadId,
                'contact_id' => $closeContactId,
                'note_html'  => $noteBody,
            ]
        );
        log_msg('close_note_log: ' . json_encode([
            'http_code' => $noteHttpCode,
            'success'   => ($noteHttpCode >= 200 && $noteHttpCode < 300),
            'lead_id'   => $closeLeadId,
        ]));
    }
}
