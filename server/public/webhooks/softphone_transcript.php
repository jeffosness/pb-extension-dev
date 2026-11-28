<?php
// server/public/webhooks/softphone_transcript.php
//
// PhoneBurner generic-softphone webhook: softphone_transcript (EXP-5135)
// Fired a few minutes after a click-to-call is recorded + transcribed. We attach
// the AI summary + transcript to the CRM contact we already logged the call on
// at softphone_call_done time.
//
// Set up: this URL goes in the "Transcript webhook URL" field of our softphone
// registration on PhoneBurner's developer softphones page. Nothing is sent
// while that field is blank.
//
// ── TRUST ──────────────────────────────────────────────────────────────────
// Signed with OUR registration secret (config SOFTPHONE_HMAC_SECRET), exactly
// like softphone_call_done.php — no session token on this path, so the
// signature IS the trust boundary. Unsigned/mismatched → 401.
//
// ── PAYLOAD (salt spec softphone-transcript.yaml, audited 2026-11-23) ───────
//   {
//     "event": "transcript.available",
//     "call_id": "a2226680-221a-492f-8986-8b4f3e001312",  // == softphone_call_done.call_id
//     "transcript_id": "5f0c1e9a-...",                     // stable; a repeat is an UPDATE
//     "summary": "Spoke with ...",                          // or null
//     "transcript": [ { "speaker":"member|contact", "start_ms":480, "end_ms":3120, "text":"..." } ],
//     "contact": { "crm_name":"forth", "crm_id":"1271067966", "phone":"...", "phone_e164":"..." },
//     "custom_data": { "pb_user_id":"...", "slug":"..." }
//   }
//
// ── DELIVERY ───────────────────────────────────────────────────────────────
// One POST, 30 s timeout, NO retry from PB. A failed CRM write is queued for
// our own retry cron (scripts/cron/transcript_retry.php). After the signature
// check this always answers 200.
//
// ── SCOPE ──────────────────────────────────────────────────────────────────
// Forth only. HubSpot click-to-call transcripts stay in PhoneBurner by product
// decision (2026-11-27), so non-Forth deliveries are acknowledged and skipped.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/../api/core/bootstrap.php';
require_once __DIR__ . '/../utils.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');

if (!pb_softphone_signature_valid(
    (string)$raw,
    (string)($_SERVER['HTTP_X_PB_SIGNATURE'] ?? ''),
    (string)(cfg()['SOFTPHONE_HMAC_SECRET'] ?? '')
)) {
    api_log('softphone_transcript.signature_invalid', [
        'has_sig' => !empty($_SERVER['HTTP_X_PB_SIGNATURE']),
    ]);
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$respond = static function (): void {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
};

$callId       = $payload['call_id'] ?? null;
$transcriptId = is_scalar($payload['transcript_id'] ?? null) ? (string)$payload['transcript_id'] : '';
$contact      = is_array($payload['contact'] ?? null) ? $payload['contact'] : [];
$crmName      = is_string($contact['crm_name'] ?? null) ? $contact['crm_name'] : '';
$crmId        = is_scalar($contact['crm_id'] ?? null) ? (string)$contact['crm_id'] : '';
$segments     = is_array($payload['transcript'] ?? null) ? $payload['transcript'] : [];

// Identity + counts only — summary/transcript text never reaches the log.
api_log('softphone_transcript', [
    'crm_name'      => $crmName,
    'crm_id'        => $crmId,
    'call_id'       => is_scalar($callId) ? (string)$callId : null,
    'has_summary'   => !empty($payload['summary']),
    'segment_count' => count($segments),
]);

if ($crmName !== 'forth') {
    api_log('softphone_transcript.skip', ['reason' => 'crm_not_enabled', 'crm_name' => $crmName]);
    $respond();
}
if (!is_string($callId) || $callId === '' || $transcriptId === '') {
    api_log('softphone_transcript.skip', ['reason' => 'missing_ids']);
    $respond();
}

// The target was written by forth_log_ctc_call() when the call itself logged.
// Must be a softphone target for the SAME contact the signed payload names.
$target = transcript_target_load($callId);
if (!is_array($target) || ($target['source'] ?? '') !== 'softphone') {
    api_log('softphone_transcript.skip', ['reason' => 'no_target_for_call', 'call_id' => $callId]);
    $respond();
}
if ($crmId === '' || (string)($target['forth_cid'] ?? '') !== $crmId) {
    api_log('softphone_transcript.skip', ['reason' => 'contact_mismatch', 'call_id' => $callId]);
    $respond();
}

$decision = transcript_should_apply($transcriptId, $payload['summary'] ?? null);
if (!$decision['apply']) {
    api_log('softphone_transcript.skip', ['reason' => $decision['reason'], 'call_id' => $callId]);
    $respond();
}

$ok = transcript_apply($target, $payload, $decision['is_update']);
if (!$ok) {
    transcript_retry_store('softphone', $callId, $payload, $decision['is_update']);
}
api_log('softphone_transcript.result', [
    'call_id'   => $callId,
    'provider'  => (string)($target['provider'] ?? ''),
    'is_update' => $decision['is_update'],
    'success'   => $ok,
]);

$respond();
