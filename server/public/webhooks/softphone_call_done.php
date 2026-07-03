<?php
// server/public/webhooks/softphone_call_done.php
//
// PhoneBurner generic-softphone webhook: softphone_call_done
// Fired when an embedded click-to-call session is dispositioned.
//
// Authenticity: PhoneBurner signs the raw request body with the softphone
// registration's HMAC secret and sends it as:
//
//     X-PB-Signature: sha256=<hex hmac_sha256(rawBody, secret)>
//
// The secret lives ONLY here (config: SOFTPHONE_HMAC_SECRET), never in the
// extension. We verify with a constant-time compare and reject anything that
// doesn't match — unlike the dial-session webhooks, this endpoint is reachable
// without a session token, so the signature IS the trust boundary.
//
// This handler intentionally stays minimal for the v1 click-to-call test:
// verify → parse → log (non-PII) → 200. Forwarding the disposition into the
// CRM / relaying to the side panel is a follow-up phase.

require_once __DIR__ . '/../utils.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');

// ── Verify HMAC signature ──────────────────────────────────────────────────
$secret    = cfg()['SOFTPHONE_HMAC_SECRET'] ?? '';
$sigHeader = $_SERVER['HTTP_X_PB_SIGNATURE'] ?? '';

$verified = false;
if ($secret !== '' && $sigHeader !== '') {
    $provided = $sigHeader;
    if (stripos($provided, 'sha256=') === 0) {
        $provided = substr($provided, 7); // strip the "sha256=" prefix
    }
    $expected = hash_hmac('sha256', $raw, $secret);
    $verified = hash_equals($expected, $provided);
}

if (!$verified) {
    log_msg('softphone_call_done: signature_invalid ' . json_encode([
        'has_secret' => $secret !== '',
        'has_sig'    => $sigHeader !== '',
    ]));
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// ── Selective, non-PII logging (no names / phones / emails) ─────────────────
// Field names are best-effort guesses until we observe a real payload; the
// DEBUG_MODE dump below captures the actual shape during the e2e test.
// Pull the canonical record identity (crm_id + crm_name) if PB echoes the
// external_crm_data array back — same shape as the dial-session webhooks.
$externalCrm = $payload['external_crm_data'] ?? ($payload['external_crm'] ?? null);
$crmId = null;
$crmName = null;
if (is_array($externalCrm) && isset($externalCrm[0]) && is_array($externalCrm[0])) {
    $crmId   = $externalCrm[0]['crm_id']   ?? null;
    $crmName = $externalCrm[0]['crm_name'] ?? null;
}

$disposition = $payload['disposition'] ?? null;
$duration    = $payload['duration']    ?? null;
$connected   = $payload['connected']   ?? null;
$callId      = $payload['call_id']     ?? null;
$status      = $payload['status']      ?? null;

log_msg('softphone_call_done: ' . json_encode([
    'crm_id'           => $crmId,
    'crm_name'         => $crmName,
    'has_external_crm' => is_array($externalCrm),
    'record_id'        => $payload['recordId']   ?? ($payload['record_id']   ?? null),
    'record_type'      => $payload['recordType'] ?? ($payload['record_type'] ?? null),
    'status'           => $status,
    'disposition'      => $disposition,
    'duration'         => $duration,
    'connected'        => $connected,
    'call_id'          => $callId,
]));

// ── Track disposition to the CRM usage log ─────────────────────────────────
// Best-effort: appends a JSON line to metrics/crm_usage-YYYY-MM-DD.log with
// event_type=click_to_call_done. Paired with the extension-side event_type=
// click_to_call entry from background.js's CLICK_TO_CALL handler, this lets
// the CTC dashboard compare "calls initiated" (clicks) vs "calls
// dispositioned" (this webhook) and surface the drop-off rate — expected
// because a user can close the softphone popup mid-call without dispositioning.
//
// We do NOT have client_id / member_user_id here (the webhook is HMAC-authed
// rather than session-authed, so it doesn't know who the extension user is).
// That's fine for this metric — we only need aggregate counts + crm_id
// distribution + disposition breakdown. Individual attribution isn't the goal.
//
// A write failure MUST NOT block the 200 back to PB. Wrapped tight.
try {
    $publicDir  = dirname(__DIR__); // webhooks -> public
    $metricsDir = $publicDir . '/metrics';
    ensure_dir($metricsDir);

    $logFile = safe_file_path($metricsDir, 'crm_usage-' . date('Y-m-d') . '.log');
    if ($logFile) {
        $trackEntry = [
            'ts'             => date('c'),
            'client_id_hash' => null,        // unknown at webhook time
            'member_user_id' => null,        // unknown at webhook time
            'event_type'     => 'click_to_call_done',
            'crm_id'         => $crmId,
            'crm_name'       => $crmName,
            'host'           => '',
            'path'           => '',
            'level'          => 0,
            'object_type'    => '',
            'launch_source'  => '',
            'selected_count' => 1,
            // CTC-disposition-specific fields (not present on click entries):
            'disposition'    => $disposition,
            'duration'       => $duration,
            'connected'      => $connected,
            'call_id'        => $callId,
            'status'         => $status,
            'ua'             => null,
        ];
        @file_put_contents(
            $logFile,
            json_encode($trackEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
} catch (\Throwable $e) {
    log_msg('softphone_call_done.track_error: ' . $e->getMessage());
}

// Capture the full raw payload ONLY when debugging is explicitly enabled, so we
// can learn the real schema during the test without leaking PII in normal ops.
if (!empty(cfg()['DEBUG_MODE'])) {
    log_msg('softphone_call_done.debug_raw: ' . $raw);
}

http_response_code(200);
echo json_encode(['ok' => true]);
