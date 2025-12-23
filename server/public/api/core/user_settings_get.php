<?php
// server/public/api/core/user_settings_get.php
//
// Returns saved settings for the PhoneBurner member associated with this browser client_id.
// If no settings file exists yet for that member_user_id, initializes a default structure.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// Find which PhoneBurner member this browser belongs to
$memberUserId = resolve_member_user_id_for_client($client_id);
if (!$memberUserId) {
    api_log('user_settings_get.reject.no_member', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    ]);
    api_error('No user settings found for this client. Save a valid PAT first.', 'not_found', 404);
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

    api_log('user_settings_get.init_defaults', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'member_user_id' => $memberUserId,
    ]);
}

api_log('user_settings_get.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'member_user_id' => $settings['member_user_id'] ?? $memberUserId,
]);

// Only expose safe parts
api_ok([
    'member_user_id' => $settings['member_user_id'] ?? $memberUserId,
    'profile'        => $settings['profile']        ?? [],
    'crm_patterns'   => $settings['crm_patterns']   ?? [],
    'goals'          => $settings['goals']          ?? [],
]);
