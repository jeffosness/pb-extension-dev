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
//     "transcript_id": "5f0c1e9a-...",  // dedupe key (delivery is at-least-once)
//     "summary": "Spoke with ...",      // AI summary, or null
//     "transcript": [ { "speaker":"member|contact", "start_ms":480,
//                       "end_ms":3120, "text":"..." }, ... ],
//     "contact": { "user_id": 1217944038, "phone":"...", "phone_e164":"..." },
//     "agent":   { "user_id": 673245032 },
//     "custom_data": ...
//   }
//
// ALWAYS returns 200 (like call_done) so a logging failure on our side never
// triggers a PhoneBurner retry storm; real problems go to api_log().

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

$callId        = $payload['call_id'] ?? null;
$transcriptId  = (string)($payload['transcript_id'] ?? '');
$segments      = is_array($payload['transcript'] ?? null) ? $payload['transcript'] : [];

api_log('transcript_done', [
    'event'          => $payload['event'] ?? null,
    'has_call_id'    => $callId !== null,
    'has_transcript' => $transcriptId !== '',
    'has_summary'    => !empty($payload['summary']),
    'segment_count'  => count($segments),
]);

if ($callId === null || $transcriptId === '') {
    // Nothing we can correlate on — acknowledge and move on.
    echo 'OK';
    exit;
}

// Correlate back to the activity we logged. The target is authoritative for the
// provider + CRM ids; the session (via ?s=) is kept only as a sanity/fallback.
$target = transcript_target_load($callId);
if (!is_array($target)) {
    api_log('transcript_done.skip', ['reason' => 'no_target_for_call', 'call_id' => (string)$callId]);
    echo 'OK';
    exit;
}

// Authenticate the caller: the ?s= token MUST match the one that created this
// target. The target stores only hash('sha256', session_token) — never the raw
// token — so we compare hashes in constant time. This is the trust boundary: a
// forged request with a guessed call_id but no valid token is rejected. We bind
// to the stored hash (not a live session lookup) so a transcript that lands
// after the session file is cleaned still authenticates.
$incomingHash = hash('sha256', (string)$session_token);
$storedHash   = (string)($target['s_hash'] ?? '');
if ($storedHash === '' || !hash_equals($storedHash, $incomingHash)) {
    api_log('transcript_done.skip', ['reason' => 'session_binding_mismatch', 'call_id' => (string)$callId]);
    echo 'OK';
    exit;
}

// Idempotency: PB delivers api_transcript at-least-once, so claim this
// transcript_id atomically — a redelivery or a concurrent delivery gets false
// and skips, preventing a duplicate write (e.g. a second Forth contact note).
// Done AFTER the binding check so a forger can't pre-claim real transcript_ids.
if (!transcript_claim($transcriptId)) {
    api_log('transcript_done.skip', ['reason' => 'already_processed', 'call_id' => (string)$callId]);
    echo 'OK';
    exit;
}

$provider = (string)($target['provider'] ?? ($target['crm_name'] ?? ''));

// -----------------------------------------------------------------------------
// Provider-isolated transcript logging (mirrors call_done.php's dispatch).
// Each provider owns updating its own CRM activity in its directory.
// On success the target is removed so an at-least-once redelivery is a no-op;
// on failure it is kept so a PB retry (or the next delivery) can still land.
// -----------------------------------------------------------------------------
$enriched = false;

if ($provider === 'close') {
    try {
        require_once __DIR__ . '/../api/crm/close/close_transcript_logger.php';
        $enriched = close_log_transcript($target, $payload);
    } catch (\Throwable $e) {
        api_log('close_transcript_log.error', ['message' => $e->getMessage()]);
    }
}

if ($provider === 'forth') {
    try {
        require_once __DIR__ . '/../api/crm/forth/forth_transcript_logger.php';
        $enriched = forth_log_transcript($target, $payload);
    } catch (\Throwable $e) {
        api_log('forth_transcript_log.error', ['message' => $e->getMessage()]);
    }
}
// Further L3 providers (HubSpot, Apollo) plug in here once their transcript
// writer + target-write exist — see GH #258 (they don't own a call activity,
// so that's a separate design).

if ($enriched) {
    transcript_target_delete($callId);
}

echo 'OK';
