<?php
// generic_crm/api/oauth_pb_save.php
require_once __DIR__ . '/../../utils.php';

$data = json_input();
$client_id = get_client_id_or_fail($data);
$pat = $data['pat'] ?? null;

if (!$pat) {
    json_response(['ok' => false, 'error' => 'Missing PAT'], 400);
}

// 1) Validate the PAT by calling /members/me on PhoneBurner
list($info, $body) = pb_api_call($pat, 'GET', '/members/me');

// If curl failed or we didn't get a 200, treat this as a PAT error
if (!$info || ($info['http_code'] ?? 0) !== 200 || !isset($body['members']['members'])) {
    $details = 'Unable to validate PAT with PhoneBurner.';
    if (is_array($body) && isset($body['error'])) {
        $details = $body['error'];
    }

    json_response(
        [
            'ok'     => false,
            'error'  => 'PAT validation failed',
            'details'=> $details,
        ],
        400
    );
}

$member = $body['members']['members'];

// Prefer member_user_id, fall back to user_id if needed
$memberUserId = (string)($member['member_user_id'] ?? $member['user_id'] ?? '');

if ($memberUserId === '') {
    json_response(
        [
            'ok'    => false,
            'error' => 'Could not determine PhoneBurner member_user_id from /members/me',
        ],
        500
    );
}

// 2) Save the PAT exactly as before (keyed by client_id)
save_pb_token($client_id, $pat);

// 3) Save/update a per-user settings file keyed by member_user_id
save_user_profile($client_id, $memberUserId, $member);

// 4) Return success + a little profile info (optional, safe to ignore on the front-end)
json_response([
    'ok'             => true,
    'member_user_id' => $memberUserId,
    'profile'        => [
        'username'      => $member['username']      ?? null,
        'first_name'    => $member['first_name']    ?? null,
        'last_name'     => $member['last_name']     ?? null,
        'email_address' => $member['email_address'] ?? null,
    ],
]);
