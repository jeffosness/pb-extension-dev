<?php
// server/public/api/crm/forth/forth_transcript_logger.php
//
// Enriches a Forth contact with the AI summary + transcript delivered by
// PhoneBurner's api_transcript webhook (EXP-5135), minutes after the call.
//
// Called from webhooks/transcript_done.php when the transcript-target record
// says provider === 'forth'. The target (written at call_done time) carries:
//   { provider, client_id, forth_cid, note_text }
//
// Unlike Close (where we edit the call activity's note in place), Forth gets the
// transcript as a NOTE on the contact (POST /v1/contacts/{cid}/notes) — the same
// endpoint forth_call_logger already uses for agent notes. Forth's call-activity
// update path isn't one we use; a contact note is the proven, reliable write and
// keeps the transcript on the same record.
//
// Webhook-safe: reuses forth_try_mint_access_token() (never api_error/exits).
// On a 2xx the caller clears the target so an at-least-once redelivery no-ops.

/**
 * @param array $target  Correlation record stored by forth_log_call().
 * @param array $payload Raw api_transcript webhook payload.
 * @return bool          true on a 2xx note create (caller clears the target).
 */
function forth_log_transcript(array $target, array $payload): bool {
    $clientId = (string)($target['client_id'] ?? '');
    $forthCid = (string)($target['forth_cid'] ?? ($target['contact_id'] ?? ''));
    if ($clientId === '' || !ctype_digit($forthCid)) {
        api_log('forth_transcript_log.skip', ['reason' => 'missing_client_or_cid']);
        return false;
    }
    $clientHash = substr(hash('sha256', $clientId), 0, 12);

    $tokens = load_forth_tokens($clientId);
    if (!is_array($tokens) || empty($tokens['client_id']) || empty($tokens['client_secret'])) {
        api_log('forth_transcript_log.skip', ['reason' => 'no_forth_credentials', 'client_id_hash' => $clientHash]);
        return false;
    }

    require_once __DIR__ . '/forth_helpers.php';

    $apiKey = (string)($tokens['api_key'] ?? '');
    if ($apiKey === '' || forth_token_is_expired($tokens)) {
        $reason = null;
        $minted = forth_try_mint_access_token($clientId, $tokens, $reason);
        if (!is_array($minted) || empty($minted['api_key'])) {
            api_log('forth_transcript_log.skip', [
                'reason'         => 'token_mint_failed:' . ($reason ?? '?'),
                'client_id_hash' => $clientHash,
            ]);
            return false;
        }
        $apiKey = (string)$minted['api_key'];
    }

    // Build the note (Forth notes are plain text).
    $summary  = trim((string)($payload['summary'] ?? ''));
    $segments = is_array($payload['transcript'] ?? null) ? $payload['transcript'] : [];

    $content = forth_build_transcript_note_content($summary, $segments);
    if ($content === null) {
        api_log('forth_transcript_log.skip', ['reason' => 'empty_transcript_and_summary']);
        return false;
    }

    list($code, $resp, $raw, $info) = forth_api_post_json(
        $apiKey,
        FORTH_API_BASE . 'contacts/' . (int)$forthCid . '/notes',
        ['content' => $content, 'note_type' => 1, 'public' => true]
    );
    $ok = ($code >= 200 && $code < 300);

    $logData = [
        'http_code'     => $code,
        'success'       => $ok,
        'contact_id'    => (int)$forthCid,
        'has_summary'   => $summary !== '',
        'segment_count' => count($segments),
    ];
    if (!$ok) {
        // Capture Forth's own error text (CLAUDE.md external-call failure rule).
        $fail = describe_api_failure($info, $resp);
        $logData['provider_msg'] = $fail['message'];
        $logData['curl_error']   = $fail['curl_error'];
    }
    api_log('forth_transcript_log', $logData);

    return $ok;
}

/**
 * Build the Forth contact-note body (plain text): AI summary + transcript. Pure
 * (no I/O) so it's unit-testable. Returns null when there's nothing to add.
 *
 * The transcript is capped with mb_strcut (byte budget, never splits a UTF-8
 * sequence) so a long call can't produce invalid UTF-8 that would make the
 * downstream json_encode() fail and drop the note.
 *
 * @param array<int,array<string,mixed>> $segments
 */
function forth_build_transcript_note_content(string $summary, array $segments): ?string
{
    $lines = [];
    foreach ($segments as $seg) {
        if (!is_array($seg)) continue;
        $text = trim((string)($seg['text'] ?? ''));
        if ($text === '') continue;
        $speaker = ((string)($seg['speaker'] ?? '') === 'member') ? 'Agent' : 'Contact';
        $lines[] = $speaker . ': ' . $text;
    }

    if ($summary === '' && $lines === []) {
        return null;
    }

    $content = 'PhoneBurner call transcript';
    if ($summary !== '') {
        $content .= "\n\nAI Summary:\n" . $summary;
    }
    if ($lines !== []) {
        $transcript = implode("\n", $lines);
        if (strlen($transcript) > 20000) {
            $transcript = mb_strcut($transcript, 0, 20000) . "\n… (transcript truncated)";
        }
        $content .= "\n\nTranscript:\n" . $transcript;
    }

    return $content;
}
