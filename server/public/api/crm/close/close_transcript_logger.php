<?php
// server/public/api/crm/close/close_transcript_logger.php
//
// Enriches the Close call activity we created in close_call_logger.php with the
// AI summary + full transcript delivered by PhoneBurner's api_transcript webhook
// (EXP-5135), a few minutes after the call.
//
// Called from webhooks/transcript_done.php when the transcript-target record for
// the call says provider === 'close'. Everything needed to find the activity
// comes from that target (written at call_done time) — no session lookup needed:
//   target = { provider, client_id, lead_id, contact_id, activity_id, note_text }
//
// Self-contained (mirrors close_call_logger.php): direct curl, inline token
// refresh. We can't use close_refresh_access_token_or_fail() here — it calls
// api_error() which exits non-200 and would make PhoneBurner retry the webhook.
//
// Updates the activity's note_html in place (Close PUT is a partial update, so
// only the note is touched). The note becomes: original call note + AI summary
// + full transcript. On a 2xx the caller deletes the target so an at-least-once
// redelivery is a no-op.

/**
 * @param array $target  Correlation record stored by close_log_call().
 * @param array $payload Raw api_transcript webhook payload.
 * @return bool          true on a 2xx activity update (caller clears the target).
 */
function close_log_transcript(array $target, array $payload): bool {
    $clientId   = (string)($target['client_id'] ?? '');
    $activityId = (string)($target['activity_id'] ?? '');
    if ($clientId === '' || $activityId === '') {
        api_log('close_transcript_log.skip', ['reason' => 'missing_client_or_activity']);
        return false;
    }
    $clientHash = substr(hash('sha256', $clientId), 0, 12);

    $tokens = load_close_tokens($clientId);
    if (!is_array($tokens) || empty($tokens['access_token'])) {
        api_log('close_transcript_log.skip', ['reason' => 'no_close_tokens', 'client_id_hash' => $clientHash]);
        return false;
    }
    $accessToken = (string)$tokens['access_token'];

    // -------------------------------------------------------------------------
    // Inline token refresh (webhook-safe — never api_error()). Mirrors
    // close_call_logger.php so a transcript landing >1h after a long dial
    // session still has a live token.
    // -------------------------------------------------------------------------
    $expiresAt = isset($tokens['expires_at']) ? (int)$tokens['expires_at'] : 0;
    if ($expiresAt > 0 && time() >= $expiresAt) {
        $refreshToken = $tokens['refresh_token'] ?? '';
        if ($refreshToken !== '') {
            $cfg = cfg();
            $ch  = curl_init('https://api.close.com/oauth2/token/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $cfg['CLOSE_CLIENT_ID'] ?? '',
                    'client_secret' => $cfg['CLOSE_CLIENT_SECRET'] ?? '',
                    'refresh_token' => $refreshToken,
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT    => 10,
            ]);
            $refreshRaw  = curl_exec($ch);
            $refreshInfo = curl_getinfo($ch);
            $refreshErr  = curl_error($ch);
            curl_close($ch);
            $refreshInfo['raw_body'] = is_string($refreshRaw) ? $refreshRaw : '';
            if ($refreshErr !== '') $refreshInfo['curl_error'] = $refreshErr;

            $refreshCode = (int)($refreshInfo['http_code'] ?? 0);
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
            } else {
                // Capture Close's own error text (not just an HTTP code).
                $fail = describe_api_failure($refreshInfo, $refreshResp);
                _pb_write_api_log('close_transcript_log_token_refresh.error', [
                    'status'       => $fail['status'],
                    'provider_msg' => $fail['message'],
                    'body_snippet' => $fail['body_snippet'],
                    'curl_error'   => $fail['curl_error'],
                ]);
                api_log('close_transcript_log.skip', ['reason' => 'token_refresh_failed', 'http_code' => $refreshCode]);
                return false;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Build the enriched note: original call note + AI summary + transcript.
    // All user/AI text is HTML-escaped (note_html is rendered HTML in Close).
    // The transcript is capped so a very long call can't blow past Close's note
    // size limits; the summary is always included first.
    // -------------------------------------------------------------------------
    $summary   = trim((string)($payload['summary'] ?? ''));
    $segments  = is_array($payload['transcript'] ?? null) ? $payload['transcript'] : [];
    $noteText  = trim((string)($target['note_text'] ?? ''));

    $parts = [];
    if ($noteText !== '') {
        $parts[] = '<p>' . htmlspecialchars($noteText) . '</p>';
    }
    if ($summary !== '') {
        $parts[] = '<p><strong>AI Summary</strong><br>' . nl2br(htmlspecialchars($summary)) . '</p>';
    }

    $lines = [];
    foreach ($segments as $seg) {
        if (!is_array($seg)) continue;
        $text = trim((string)($seg['text'] ?? ''));
        if ($text === '') continue;
        $speaker = ((string)($seg['speaker'] ?? '') === 'member') ? 'Agent' : 'Contact';
        $lines[] = '<strong>' . $speaker . ':</strong> ' . htmlspecialchars($text);
    }
    if (!empty($lines)) {
        $transcriptHtml = implode('<br>', $lines);
        // Cap defensively — Close notes are generous but not unbounded.
        if (strlen($transcriptHtml) > 20000) {
            $transcriptHtml = substr($transcriptHtml, 0, 20000) . '… <em>(transcript truncated)</em>';
        }
        $parts[] = '<p><strong>Transcript</strong><br>' . $transcriptHtml . '</p>';
    }

    if ($summary === '' && empty($lines)) {
        // Nothing to add (shouldn't happen — PB only fires when ready).
        api_log('close_transcript_log.skip', ['reason' => 'empty_transcript_and_summary']);
        return false;
    }

    $noteHtml = '<body>' . implode('', $parts) . '</body>';

    // -------------------------------------------------------------------------
    // PUT the enriched note onto the existing call activity (partial update).
    // -------------------------------------------------------------------------
    $ch = curl_init('https://api.close.com/api/v1/activity/call/' . rawurlencode($activityId) . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode(['note_html' => $noteHtml]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw  = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    $info['raw_body'] = is_string($raw) ? $raw : '';
    if ($err !== '') $info['curl_error'] = $err;

    $code = (int)($info['http_code'] ?? 0);
    $ok   = ($code >= 200 && $code < 300);
    $resp = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;

    $logData = [
        'http_code'     => $code,
        'success'       => $ok,
        'activity_id'   => $activityId,
        'has_summary'   => $summary !== '',
        'segment_count' => count($lines),
    ];
    if (!$ok) {
        // Capture Close's own error text (CLAUDE.md external-call failure rule).
        $fail = describe_api_failure($info, $resp);
        $logData['provider_msg'] = $fail['message'];
        $logData['close_error']  = $fail['response'];
        $logData['body_snippet'] = $fail['body_snippet'];
        $logData['curl_error']   = $fail['curl_error'];
    }
    api_log('close_transcript_log', $logData);

    return $ok;
}
