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
// task row (currently only HubSpot; future CRMs can plug in), it passes
// task_id + normalized phone + crm_name here. We drop a small "intent"
// record so softphone_call_done.php can look it up on the webhook fire
// (which only carries pb_user_id + phone) and close the task.
//
// This is the bridge we ended up with because PhoneBurner drops arbitrary
// custom_data we try to pass through the DIAL postMessage (confirmed
// empirically 2026-07-08). See utils.php's ctc_intent_* helpers for the
// storage shape and FIFO semantics. See issue #170 for the full design.
//
// Adding task-completion for a new CRM (Close, Apollo, etc.):
//   1. Add a case to ctc_supported_crm_config() below with the CRM's token
//      loader + task_id validator.
//   2. Add a dispatch case to softphone_call_done.php's consume block that
//      calls the CRM's task-completer function.
//   3. Implement the CRM's task-completer in api/crm/{crm}/{crm}_call_logger.php
//      following the hubspot_complete_task_for_client() shape.
// No changes needed to the intent storage layer — it's CRM-agnostic.
//
// Best-effort: never blocks the auth-code mint. If pb_user_id resolution
// or intent write fails, the auth code + softphone dial still work — the
// customer just doesn't get automatic task completion.
$task_id  = isset($data['task_id'])  ? (string)$data['task_id']  : '';
$phone    = isset($data['phone'])    ? (string)$data['phone']    : '';
$crm_name = isset($data['crm_name']) ? (string)$data['crm_name'] : '';

$intent_written = false;
if ($task_id !== '' && $phone !== '' && $crm_name !== '') {
    $crmCfg = ctc_supported_crm_config($crm_name);
    if ($crmCfg !== null) {
        // Confirm the CRM is connected — if not, skip the intent write.
        // The webhook would find a record it can't act on, and a stale
        // entry would sit in the queue for the TTL.
        $tokens = ($crmCfg['token_loader'])($client_id);
        $connected = is_array($tokens) && !empty($tokens['access_token']);

        if ($connected) {
            $pb_user_id = resolve_member_user_id_for_client($client_id);
            if ($pb_user_id) {
                // Per-CRM task_id shape validation — belt-and-suspenders
                // against a malicious client smuggling arbitrary payloads
                // into the intent file.
                if (preg_match($crmCfg['task_id_pattern'], $task_id)) {
                    $intent_written = ctc_intent_write(
                        (string)$pb_user_id,
                        $phone,
                        $client_id,
                        $task_id,
                        $crm_name
                    );
                }
            }
        }
    }
}

api_log('softphone_auth_code.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'has_task_id'    => $task_id !== '',
    'has_phone'      => $phone !== '',
    'crm_name'       => $crm_name,
    'intent_written' => $intent_written,
]);

api_ok_flat(['code' => $code]);

/**
 * CRM registry for CTC task-completion. Adding a new provider is a matter
 * of adding a case here + a matching dispatch in softphone_call_done.php.
 * See CRMS.md for the full walkthrough.
 *
 * Returns null for unrecognized CRMs so unknown values fail closed
 * (no intent written for a CRM we don't know how to complete tasks in).
 */
function ctc_supported_crm_config(string $crm_name): ?array
{
    switch ($crm_name) {
        case 'hubspot':
            return [
                'token_loader'    => 'load_hs_tokens',
                // HubSpot task ids are numeric object ids (see the DOM's
                // <tr data-test-id="row-{taskId}"> on both task views).
                'task_id_pattern' => '/^\d{1,20}$/',
            ];
        // case 'close':
        //   return [
        //       'token_loader'    => 'load_close_tokens',
        //       'task_id_pattern' => '/^[a-zA-Z0-9_-]{1,64}$/', // Close task ids look like "task_abc123"
        //   ];
        // case 'apollo':
        //   return [ ... ];
    }
    return null;
}
