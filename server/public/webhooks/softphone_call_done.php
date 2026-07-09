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
// ── PAYLOAD SHAPE (real example, captured 2026-06-28) ──────────────────────
// The softphone webhook uses a DIFFERENT envelope than the dial-session
// call_done.php webhook. Don't pattern-match from that file — the fields
// here are:
//
//   {
//     "status": "Connected",                      // disposition text
//     "duration": 73,                             // seconds
//     "start_time": "2026-06-28 16:21:07",
//     "end_time":   "2026-06-28 16:22:20",
//     "call_id": "a2226680-221a-492f-8986-8b4f3e001312",
//     "direction": "outbound",
//     "call_notes": ["Spoke with the decision maker..."],
//     "recording_url_public": "https://app.phoneburner.com/recording/pub/9b1c2a7f.mp3",
//     "follow_up": "2026-07-06 09:00:00",
//     "contact": {
//       "crm_name": "hubspot",                    // maps to our crm_name
//       "crm_id":   "199251909638",               // maps to our crm_id
//       "phone":    "+19012954326"
//     },
//     "custom_data": {
//       "pb_user_id": "21791",                    // agent's PhoneBurner member_user_id
//       "slug":       "<softphone-slug>"          // which softphone registration
//     }
//   }
//
// Notably ABSENT (vs dial-session call_done.php): no `payload.agent.user_id`,
// no `payload.disposition`, no `payload.external_crm_data`, no `recordId`, no
// `ds_id`. The contact object here is minimal (just crm_id/crm_name/phone) —
// nothing like the rich contact record dial-session gives us. Also
// `custom_data` on this envelope is an OBJECT with pb_user_id + slug, while
// on the dial-session envelope it's an empty ARRAY. See call_done.php header
// for the dial-session shape side-by-side. Do NOT cross-pollinate the two.
//
// Anything else showing up in real payloads → add to this comment so the
// next editor doesn't have to reverse-engineer it.

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

// ── Extract fields from the payload (real shape captured in file header) ────
// Non-PII: identity fields (crm_id, crm_name, pb_user_id), timing (duration,
// status), and the recording URL. call_notes contains user-typed text and
// contact.phone contains a phone number — those STAY OUT of any log line we
// might inspect casually.
$contact = is_array($payload['contact'] ?? null) ? $payload['contact'] : [];
$customData = is_array($payload['custom_data'] ?? null) ? $payload['custom_data'] : [];

$crmId   = $contact['crm_id']   ?? null;
$crmName = $contact['crm_name'] ?? null;

$status      = $payload['status']    ?? null;   // e.g. "Connected", "No Answer", "Voicemail"
$duration    = $payload['duration']  ?? null;   // seconds
$callId      = $payload['call_id']   ?? null;
$direction   = $payload['direction'] ?? null;

// Agent identifier — PB places our custom_data slug + pb_user_id back on the
// webhook (confirmed by the 2026-06-28 sample payload). This gives us per-
// agent CTC disposition tracking without threading anything ourselves.
$agentMemberUserId = null;
if (isset($customData['pb_user_id']) && $customData['pb_user_id'] !== '') {
    $agentMemberUserId = (string)$customData['pb_user_id'];
}

log_msg('softphone_call_done: ' . json_encode([
    'crm_id'         => $crmId,
    'crm_name'       => $crmName,
    'has_contact'    => is_array($payload['contact'] ?? null),
    'has_custom_data'=> is_array($payload['custom_data'] ?? null),
    'pb_user_id'     => $agentMemberUserId,       // canonical: agent identity
    'status'         => $status,
    'duration'       => $duration,
    'direction'      => $direction,
    'call_id'        => $callId,
    'has_recording'  => !empty($payload['recording_url_public']),
    'has_call_notes' => is_array($payload['call_notes'] ?? null) && count($payload['call_notes']) > 0,
    'has_follow_up'  => !empty($payload['follow_up']),
]));

// ── Track disposition to the CRM usage log ─────────────────────────────────
// Best-effort: appends a JSON line to metrics/crm_usage-YYYY-MM-DD.log with
// event_type=click_to_call_done. Paired with the extension-side event_type=
// click_to_call entry from background.js's CLICK_TO_CALL handler, this lets
// the CTC dashboard compare "calls initiated" (clicks) vs "calls
// dispositioned" (this webhook) and surface the drop-off rate — expected
// because a user can close the softphone popup mid-call without dispositioning.
//
// Agent identity comes from custom_data.pb_user_id (PB echoes what we put in
// custom_data at softphone launch back on every call webhook). That gives us
// per-agent tracking without threading a correlation ID ourselves. If a
// future payload arrives without custom_data — say, from a manual PB test —
// the entry lands with member_user_id=null and aggregates under "unknown".
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
            'client_id_hash' => null,                  // unknown at webhook time (HMAC-authed, not session-authed)
            'member_user_id' => $agentMemberUserId,    // from payload.custom_data.pb_user_id
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
            'status'         => $status,               // e.g. "Connected", "No Answer"
            'duration'       => $duration,
            'direction'      => $direction,
            'call_id'        => $callId,
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

// ── CTC-completes-task: consume the intent bridge and dispatch to the
// popped intent's CRM-specific task-completer ───────────────────────────
// The extension writes an intent record at softphone_auth_code mint time
// keyed by (pb_user_id, normalized phone) whenever a CTC click carries a
// task_id + crm_name. Here we look it up, pull out client_id + task_id +
// crm_name, dispatch to the right provider's task-completer, and log a
// ctc_task_completed audit event to the daily crm_usage log (naturally
// rotated, no cron needed).
//
// The dispatch below is intentionally structured as a switch so adding
// Close / Apollo / future CRMs is a one-case addition. The intent
// storage layer + consume path are CRM-agnostic — see utils.php +
// softphone_auth_code.php + CRMS.md.
//
// FIFO queue at the same key handles the "same phone across two back-to-
// back task clicks" case correctly because PB's softphone is
// single-call-per-agent: calls disposition in dial order.
//
// Best-effort. A failure here MUST NOT block the 200 response back to PB —
// PB will retry the webhook on non-2xx which would double-log everything.
try {
    $contactPhone = is_string($contact['phone'] ?? null) ? $contact['phone'] : '';
    if ($agentMemberUserId !== null && $contactPhone !== '') {
        $popped = ctc_intent_consume((string)$agentMemberUserId, $contactPhone);
        if (is_array($popped)) {
            $intentClientId = (string)($popped['client_id'] ?? '');
            $intentTaskId   = (string)($popped['task_id']   ?? '');
            $intentCrmName  = (string)($popped['crm_name']  ?? '');

            $completed = false;
            if ($intentClientId !== '' && $intentTaskId !== '' && $intentCrmName !== '') {
                // Per-CRM dispatch. Add a new case here when adding
                // task-completion for another provider — see CRMS.md.
                switch ($intentCrmName) {
                    case 'hubspot':
                        require_once __DIR__ . '/../api/crm/hubspot/hs_call_logger.php';
                        $completed = hubspot_complete_task_for_client(
                            $intentClientId,
                            $intentTaskId
                        );
                        break;
                    // case 'close':
                    //   require_once __DIR__ . '/../api/crm/close/close_call_logger.php';
                    //   $completed = close_complete_task_for_client(
                    //       $intentClientId, $intentTaskId
                    //   );
                    //   break;
                    default:
                        log_msg('ctc_intent_dispatch: no completer for crm_name=' . $intentCrmName);
                        break;
                }
            }
            // Audit line piggybacks on the same daily crm_usage log that's
            // already rotated. Dashboard picks up the new event_type for
            // free (event_type=ctc_task_completed). crm_name here is the
            // intent's (not the webhook payload's) — that's the value the
            // task was actually completed under.
            try {
                if (isset($logFile) && $logFile) {
                    $auditEntry = [
                        'ts'             => date('c'),
                        'client_id_hash' => substr(hash('sha256', $intentClientId), 0, 12),
                        'member_user_id' => $agentMemberUserId,
                        'event_type'     => 'ctc_task_completed',
                        'crm_id'         => $crmId,
                        'crm_name'       => $intentCrmName ?: $crmName,
                        'host'           => '',
                        'path'           => '',
                        'level'          => 3,
                        'object_type'    => 'task',
                        'launch_source'  => 'click_to_call',
                        'selected_count' => 1,
                        'task_id'        => $intentTaskId,
                        'complete_ok'    => $completed,
                        'call_id'        => $callId,
                        'ua'             => null,
                    ];
                    @file_put_contents(
                        $logFile,
                        json_encode($auditEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
                        FILE_APPEND | LOCK_EX
                    );
                }
            } catch (\Throwable $e) {
                log_msg('softphone_call_done.ctc_audit_error: ' . $e->getMessage());
            }
        }
    }
} catch (\Throwable $e) {
    log_msg('softphone_call_done.ctc_complete_error: ' . $e->getMessage());
}

// Capture the full raw payload ONLY when debugging is explicitly enabled, so we
// can learn the real schema during the test without leaking PII in normal ops.
if (!empty(cfg()['DEBUG_MODE'])) {
    log_msg('softphone_call_done.debug_raw: ' . $raw);
}

http_response_code(200);
echo json_encode(['ok' => true]);
