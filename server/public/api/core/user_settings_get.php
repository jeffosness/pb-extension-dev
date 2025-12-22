<?php
// generic_crm/api/user_settings_get.php
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// Find which PhoneBurner member this browser belongs to
$memberUserId = resolve_member_user_id_for_client($client_id);
if (!$memberUserId) {
    json_response(
        [
            'ok'    => false,
            'error' => 'No user settings found for this client. Save a valid PAT first.',
        ],
        404
    );
}

$settings = load_user_settings($memberUserId);
if (!is_array($settings)) {
    $now = date('c');
    $settings = [
        'member_user_id' => $memberUserId,
        'client_ids'     => [$client_id],
        'profile'        => [],
        'crm_patterns'   => [],
        'goals'          => [],
        'created_at'     => $now,
        'updated_at'     => $now,
    ];
    save_user_settings($memberUserId, $settings);
}

// Only expose safe parts
json_response([
    'ok'             => true,
    'member_user_id' => $settings['member_user_id'] ?? $memberUserId,
    'profile'        => $settings['profile']        ?? [],
    'crm_patterns'   => $settings['crm_patterns']   ?? [],
    'goals'          => $settings['goals']          ?? [],
]);
