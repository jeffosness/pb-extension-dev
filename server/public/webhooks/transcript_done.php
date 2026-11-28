<?php
// server/public/webhooks/transcript_done.php
//
// PhoneBurner webhook: api_transcript (dial-session Call Transcript, EXP-5135)
// Fired a few minutes AFTER a recorded call once its transcript + AI summary
// are ready. We use it to enrich the CRM call activity we already created at
// call_done time with the AI summary (and full transcript).
//
// ── TRUST ──────────────────────────────────────────────────────────────────
// The real trust boundary is the per-session token we handed PhoneBurner at
// launch (?s=<session_token>) — an unguessable 128-bit value, like call_done.php.
// BUT unlike call_done (whose writes all come from the session the token
// resolves to), our CRM target comes from a call_id-keyed file. So presence of
// a token is not enough: we require the token to MATCH the one that created the
// target. The target stores only hash('sha256', token); we compare hashes. A
// forged request with a guessed call_id but no valid token is rejected. The
// dial-session transcript webhook is also HMAC-signed when the account set a
// Webhooks secret, but that secret is the customer's (not ours), so we don't
// rely on it.
//
// ── CORRELATION ──────────────────────────────────────────────────────────────
// The payload carries `call_id` (identical to call_done's) but NOT the CRM
// record id. At call_done time each provider logger dropped a transcript-target
// record keyed by that call_id (see transcript_target_store in utils.php). We
// read it back here to know which provider + activity to update. Missing target
// = the call was never logged by us (or already enriched) → clean no-op.
//
// ── PAYLOAD SHAPE (dial-session api_transcript) ──────────────────────────────
//   {
//     "event": "transcript.available",
//     "call_id": 3024188937,            // integer, == call_done.call_id
//     "ds_id":   46858573,
//     "transcript_id": "5f0c1e9a-...",  // stable id; a repeat is an UPDATE
//     "summary": "Spoke with ...",      // AI summary, or null
//     "transcript": [ { "speaker":"member|contact", "start_ms":480,
//                       "end_ms":3120, "text":"..." }, ... ],
//     "contact": { "user_id": 1217944038, "phone":"...", "phone_e164":"..." },
//     "agent":   { "user_id": 673245032 },
//     "custom_data": ...
//   }
//
// ── DELIVERY (as shipped, salt spec audited 2026-11-23) ─────────────────────
// One POST per transcript, NO retry from PB (any 2xx/3xx/4xx/5xx is final).
// A New Dialer Window call may send the same transcript_id again once its AI
// summary arrives or changes — handled as an update (transcript_should_apply).
// A failed CRM write is queued for our own retry cron. Always returns 200.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/../api/core/bootstrap.php';
require_once __DIR__ . '/../utils.php';

$session_token = $_GET['s'] ?? null;
if (!$session_token) {
    http_response_code(400);
    echo 'Missing session token';
    exit;
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$callId        = is_scalar($payload['call_id'] ?? null) ? $payload['call_id'] : null;
$transcriptId  = is_scalar($payload['transcript_id'] ?? null) ? (string)$payload['transcript_id'] : '';
$segments      = is_array($payload['transcript'] ?? null) ? $payload['transcript'] : [];

api_log('transcript_done', [
    'event'          => $payload['event'] ?? null,
    'has_call_id'    => $callId !== null,
    'has_transcript' => $transcriptId !== '',
    'has_summary'    => !empty($payload['summary']),
    'segment_count'  => count($segments),
]);

// The target key binds call_id to THIS session (transcript_dial_target_key), so
// only the session that logged the call can address its target — a forged
// call_done on another session writes a different key.
$targetKey = transcript_dial_target_key($callId, $session_token);
if ($targetKey === null || $transcriptId === '') {
    // Nothing we can correlate on — acknowledge and move on.
    echo 'OK';
    exit;
}

$target = transcript_target_load($targetKey);
if (!is_array($target)) {
    api_log('transcript_done.skip', ['reason' => 'no_target_for_call', 'call_id' => (string)$callId, 'transcript_id' => $transcriptId]);
    echo 'OK';
    exit;
}

// Defence in depth: the target also stores hash('sha256', session_token) (never
// the raw token); compare in constant time. With the session-bound key this
// can only fail on a corrupted record. Bound to the stored hash, not a live
// session lookup, so a transcript landing after the session file is swept
// still authenticates.
$storedHash = (string)($target['s_hash'] ?? '');
if ($storedHash === '' || !hash_equals($storedHash, hash('sha256', (string)$session_token))) {
    api_log('transcript_done.skip', ['reason' => 'session_binding_mismatch', 'call_id' => (string)$callId]);
    echo 'OK';
    exit;
}

$ids = transcript_log_ids($target, $callId, $transcriptId);

// Repeat handling: PB sends ONE delivery per transcript and never retries, but
// may send the same transcript_id again when the AI summary arrives or changes
// (a repeat is an UPDATE). Decided under a per-transcript lock.
$decision = transcript_should_apply($targetKey, $transcriptId, $payload['summary'] ?? null);
if (!$decision['apply']) {
    api_log('transcript_done.skip', $ids + ['reason' => $decision['reason']]);
    echo 'OK';
    exit;
}

// Provider dispatch lives in transcript_apply() (utils.php), shared with the
// click-to-call receiver and the retry cron. The target is kept (not deleted)
// so a later summary update can still find it; the cleanup sweep removes it.
$enriched = transcript_apply($target, $payload, $decision['is_update']);
$queued   = false;
if (!$enriched) {
    // No PB retry exists — queue our own (scripts/cron/transcript_retry.php).
    // If even that fails, roll the marker back so a later repeat can still land.
    $queued = transcript_retry_store('dial', $targetKey, $callId, $payload, $decision['is_update']);
    if (!$queued) {
        transcript_marker_restore($targetKey, $transcriptId, $decision['prev_fp']);
    }
}
api_log('transcript_done.result', $ids + [
    'is_update' => $decision['is_update'],
    'success'   => $enriched,
    'queued'    => $queued,
]);

echo 'OK';
