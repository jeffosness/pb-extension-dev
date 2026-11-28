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
// + full transcript. The PUT rebuilds the whole note from the target, so an
// UPDATE (same transcript_id re-sent with a new summary) simply rewrites it —
// idempotent, no duplicate. A false return makes the caller queue a retry.
//
// Known + accepted (2026-11-27): because the note is rebuilt from the call-time
// snapshot, an edit a rep makes to this activity's note in the few minutes
// before the transcript lands is overwritten. Reps have normally moved on to
// the next contact by then. Revisit (GET the live note, replace only our
// section) if that turns out not to hold.

/**
 * @param array $target  Correlation record stored by close_log_call().
 * @param array $payload Raw api_transcript webhook payload.
 * @return bool          true on a 2xx activity update (false → caller queues a retry).
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

    $noteHtml = close_build_transcript_note_html($noteText, $summary, $segments);
    if ($noteHtml === null) {
        // Nothing to add (shouldn't happen — PB only fires when ready).
        api_log('close_transcript_log.skip', ['reason' => 'empty_transcript_and_summary']);
        return false;
    }

    // -------------------------------------------------------------------------
    // PUT the enriched note onto the existing call activity (partial update).
    // -------------------------------------------------------------------------
    $body = json_encode(['note_html' => $noteHtml]);
    if ($body === false) {
        // Shouldn't happen (builder keeps valid UTF-8), but never send an empty
        // body to Close — log and skip so the target is retried/swept.
        api_log('close_transcript_log.skip', ['reason' => 'json_encode_failed']);
        return false;
    }

    $ch = curl_init('https://api.close.com/api/v1/activity/call/' . rawurlencode($activityId) . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
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
        'segment_count' => count($segments),
    ];
    if (!$ok) {
        // Capture Close's own error text (CLAUDE.md external-call failure rule).
        // Message + curl error only: a validation error can echo note_html back,
        // and that holds the transcript.
        $fail = describe_api_failure($info, $resp);
        $logData['provider_msg'] = $fail['message'];
        $logData['curl_error']   = $fail['curl_error'];
    }
    api_log('close_transcript_log', $logData);

    return $ok;
}

/**
 * Build the enriched call-activity note HTML: original call note + AI summary +
 * transcript. Pure (no I/O) so it's unit-testable. All user/AI-derived text is
 * HTML-escaped (note_html is rendered HTML in Close). Returns null when there is
 * nothing to add (no summary and no usable segments).
 *
 * The transcript is capped at ~20 KB by dropping whole lines, so a long call
 * can't split a UTF-8 sequence or an HTML entity (invalid UTF-8 would make
 * json_encode() fail and silently drop the whole update).
 *
 * @param array<int,array<string,mixed>> $segments
 */
function close_build_transcript_note_html(string $noteText, string $summary, array $segments): ?string
{
    // Built line by line under a byte budget, so truncation happens between
    // whole lines and can never cut through an HTML entity or tag.
    $lines     = [];
    $bytes     = 0;
    $truncated = false;
    foreach ($segments as $seg) {
        if (!is_array($seg)) continue;
        $text = trim((string)($seg['text'] ?? ''));
        if ($text === '') continue;
        $speaker = ((string)($seg['speaker'] ?? '') === 'member') ? 'Agent' : 'Contact';
        $line    = '<strong>' . $speaker . ':</strong> ' . htmlspecialchars($text);
        if ($bytes + strlen($line) > 20000) {
            // Fit what we can of this line: cut the RAW text (UTF-8-safe), then
            // escape, shrinking until the escaped line fits the budget.
            $room = 20000 - $bytes;
            $cut  = $text;
            while ($cut !== '' && strlen($line) > $room) {
                $cut  = mb_strcut($cut, 0, intdiv(strlen($cut), 2));
                $line = '<strong>' . $speaker . ':</strong> ' . htmlspecialchars($cut);
            }
            if ($cut !== '') {
                $lines[] = $line;
            }
            $truncated = true;
            break;
        }
        $lines[] = $line;
        $bytes  += strlen($line) + 4;
    }

    // Nothing new to add if there's neither a summary nor a transcript — don't
    // re-PUT just the original note.
    if ($summary === '' && $lines === []) {
        return null;
    }

    $parts = [];
    if ($noteText !== '') {
        $parts[] = '<p>' . htmlspecialchars($noteText) . '</p>';
    }
    if ($summary !== '') {
        $parts[] = '<p><strong>AI Summary</strong><br>' . nl2br(htmlspecialchars($summary)) . '</p>';
    }
    if ($lines !== []) {
        $transcriptHtml = implode('<br>', $lines);
        if ($truncated) {
            $transcriptHtml .= '<br>… <em>(transcript truncated)</em>';
        }
        $parts[] = '<p><strong>Transcript</strong><br>' . $transcriptHtml . '</p>';
    }

    return '<body>' . implode('', $parts) . '</body>';
}
