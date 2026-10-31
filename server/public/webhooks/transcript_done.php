<?php
// server/public/webhooks/transcript_done.php
//
// PhoneBurner webhook: api_transcript (dial-session Call Transcript, EXP-5135)
// Fired a few minutes AFTER a recorded call once its transcript + AI summary
// are ready. We use it to enrich the CRM call activity we already created at
// call_done time with the AI summary (and full transcript).
//
// ── TRUST ──────────────────────────────────────────────────────────────────
// Reachable only with the per-session token we handed PhoneBurner at launch
// (?s=<session_token>), exactly like call_done.php — that token IS the trust
// boundary. The dial-session transcript webhook is ALSO HMAC-signed when the
// account set a Webhooks signing secret, but that secret is the customer's
// (set in their PB settings), not ours, so we can't rely on it. We verify
// X-PB-Signature opportunistically only if we're ever given the secret.
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

// Defense-in-depth on top of the ?s= token: if a live session exists for this
// token, it must own this call's target (same client_id). This forces a forger
// to hold BOTH a valid session token AND a call_id logged under that same
// session, not just a guessed call_id. Absent session (rare — transcripts land
// minutes after the call, while the session is still live) falls back to the
// target alone, which itself required a genuine earlier call_done.
$state = load_session_state($session_token);
if (is_array($state) && !empty($state['client_id']) && !empty($target['client_id'])
    && !hash_equals((string)$state['client_id'], (string)$target['client_id'])) {
    api_log('transcript_done.skip', ['reason' => 'session_target_client_mismatch', 'call_id' => (string)$callId]);
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
