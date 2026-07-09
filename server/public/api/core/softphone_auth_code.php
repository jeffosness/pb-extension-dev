<?php
// server/public/api/core/softphone_auth_code.php
//
// Mints a single-use, short-TTL code that the hosted softphone page
// (softphone.php) exchanges — server-side — for the user's PhoneBurner bearer
// token. The token itself is NEVER returned to the browser or placed in a URL
// the extension controls; only this opaque single-use code travels in the open.
// (CLAUDE.md golden rule: no credentials in URLs — use temp codes.)
//
// The code stores the client_id (not the PAT), so the PAT never lands in the
// temp-code cache either — softphone.php resolves it from TOKENS_DIR.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
rate_limit_or_fail($client_id, 60);

$pat = load_pb_token($client_id);
if (empty($pat)) {
    api_error('No PhoneBurner connection for this client', 'not_connected', 400);
}

// Single-use, 5-minute code → client_id. softphone.php exchanges it once.
$code = temp_code_store($client_id, 300);

// ── CTC intent write (task-completion bridge) ─────────────────────────────
// When the extension mints an auth code for a click-to-call originating on a
// HubSpot task row, it passes task_id + normalized phone here. We drop a
// small "intent" record so softphone_call_done.php can look it up on the
// webhook fire (which only carries pb_user_id + phone) and close the task.
//
// This is the bridge we ended up with because PhoneBurner drops arbitrary
// custom_data we try to pass through the DIAL postMessage (confirmed
// empirically 2026-07-08). See utils.php's ctc_intent_* helpers for the
// storage shape and FIFO semantics. See issue #170 for the full design.
//
// Best-effort: never blocks the auth-code mint. If pb_user_id resolution
// or intent write fails, the auth code + softphone dial still work — the
// customer just doesn't get automatic task completion.
$task_id = isset($data['task_id']) ? (string)$data['task_id'] : '';
$phone   = isset($data['phone'])   ? (string)$data['phone']   : '';
$intent_written = false;
if ($task_id !== '' && $phone !== '') {
    // hs_task_status transitions require the customer's HubSpot OAuth to be
    // connected. If it isn't, don't write an intent — the webhook would find
    // it but have no way to complete the task, and a stale record would sit
    // in the queue for the TTL.
    $hs = load_hs_tokens($client_id);
    if (is_array($hs) && !empty($hs['access_token'])) {
        $pb_user_id = resolve_member_user_id_for_client($client_id);
        if ($pb_user_id) {
            // Only whitelisted task ids (numeric HubSpot IDs) — belt-and-
            // suspenders against a malicious client trying to smuggle an
            // arbitrary payload into the intent file.
            if (preg_match('/^\d{1,20}$/', $task_id)) {
                $intent_written = ctc_intent_write(
                    (string)$pb_user_id,
                    $phone,
                    $client_id,
                    $task_id
                );
            }
        }
    }
}

api_log('softphone_auth_code.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'has_task_id'    => $task_id !== '',
    'has_phone'      => $phone !== '',
    'intent_written' => $intent_written,
]);

api_ok_flat(['code' => $code]);
