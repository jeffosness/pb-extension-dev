<?php
// server/public/api/core/oauth_pb_save.php
//
// Saves a PhoneBurner Personal Access Token (PAT) for this browser client_id.
// Validates PAT via PhoneBurner /members/me, then:
// 1) saves PAT keyed by client_id
// 2) saves/updates a per-user settings profile keyed by member_user_id

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);
$pat       = $data['pat'] ?? null;

if (!$pat) {
    api_log('oauth_pb_save.reject.missing_pat', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    ]);
    api_error('Missing PAT', 'bad_request', 400);
}

// 1) Validate the PAT by calling /members/me on PhoneBurner
$startValidate = microtime(true);
[$info, $body] = pb_api_call($pat, 'GET', '/members/me');
$validateMs    = (int) round((microtime(true) - $startValidate) * 1000);

// If curl failed or we didn't get a 200, treat this as a PAT error
if (!$info || ($info['http_code'] ?? 0) !== 200 || !isset($body['members']['members'])) {
    $details = 'Unable to validate PAT with PhoneBurner.';
    if (is_array($body) && isset($body['error'])) {
        $details = (string)$body['error'];
    }

    api_log('oauth_pb_save.reject.pat_validation_failed', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'http_code'      => $info['http_code'] ?? null,
        'validate_ms'    => $validateMs,
        // never log $pat
    ]);

    api_error('PAT validation failed', 'pat_invalid', 400, [
        'details' => $details,
    ]);
}

$member = $body['members']['members'];

// Prefer member_user_id, fall back to user_id if needed
$memberUserId = (string)($member['member_user_id'] ?? $member['user_id'] ?? '');

if ($memberUserId === '') {
    api_log('oauth_pb_save.error.missing_member_user_id', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'validate_ms'    => $validateMs,
    ]);
    api_error('Could not determine PhoneBurner member_user_id from /members/me', 'server_error', 500);
}

// 2) Save the PAT exactly as before (keyed by client_id)
save_pb_token($client_id, $pat);

// 3) Save/update a per-user settings file keyed by member_user_id
save_user_profile($client_id, $memberUserId, $member);

api_log('oauth_pb_save.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'member_user_id' => $memberUserId,
    'validate_ms'    => $validateMs,
]);

// 4) Return success + a little profile info (optional, safe to ignore on the front-end)
api_ok([
    'member_user_id' => $memberUserId,
    'profile'        => [
        'username'      => $member['username']      ?? null,
        'first_name'    => $member['first_name']    ?? null,
        'last_name'     => $member['last_name']     ?? null,
        'email_address' => $member['email_address'] ?? null,
    ],
]);
