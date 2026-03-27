<?php
// server/public/api/core/user_settings_save.php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

$goals          = $data['goals'] ?? null;
$crmPatterns    = $data['crm_patterns'] ?? null;
$crmPreferences = $data['crm_preferences'] ?? null;

if ($goals === null && $crmPatterns === null && $crmPreferences === null) {
    api_log('user_settings_save.reject.no_settings');
    api_error('No settings supplied (expected goals, crm_patterns, and/or crm_preferences)', 'bad_request', 400);
}

$memberUserId = resolve_member_user_id_for_client($client_id);
if (!$memberUserId) {
    api_log('user_settings_save.reject.no_member', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    ]);
    api_error('No user settings found for this client. Save a valid PAT first.', 'not_found', 404);
}

$settings = load_user_settings($memberUserId);
if (!is_array($settings)) {
    $now      = date('c');
    $settings = [
        'member_user_id' => $memberUserId,
        'client_ids'     => [$client_id],
        'profile'        => [],
        'crm_patterns'   => [],
        'goals'          => [],
        'created_at'     => $now,
        'updated_at'     => $now,
    ];
}

if (is_array($goals)) {
    $primary   = isset($goals['primary']) ? trim((string)$goals['primary']) : '';
    $secondary = isset($goals['secondary']) ? trim((string)$goals['secondary']) : '';

    if ($primary === '')   $primary = 'Set Appointment';
    if ($secondary === '') $secondary = 'Follow Up';

    $settings['goals'] = [
        'primary'   => $primary,
        'secondary' => $secondary,
    ];
}

if (is_array($crmPatterns)) {
    $settings['crm_patterns'] = $crmPatterns;
}

if (is_array($crmPreferences)) {
    // Merge per-provider preferences (e.g., { hubspot: { preferred_phone_property_contacts: "mobilephone" } })
    // This allows saving one provider's preferences without clobbering another's
    $existing = $settings['crm_preferences'] ?? [];
    if (!is_array($existing)) $existing = [];

    foreach ($crmPreferences as $provider => $prefs) {
        if (!is_string($provider) || !is_array($prefs)) continue;
        // Sanitize provider name
        $provider = preg_replace('/[^a-zA-Z0-9_-]/', '', $provider);
        if ($provider === '') continue;

        $existing[$provider] = array_merge($existing[$provider] ?? [], $prefs);
    }
    $settings['crm_preferences'] = $existing;
}

$settings['updated_at'] = date('c');

save_user_settings($memberUserId, $settings);

api_log('user_settings_save.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
]);

api_ok([
    'goals'           => $settings['goals']           ?? [],
    'crm_patterns'    => $settings['crm_patterns']    ?? [],
    'crm_preferences' => $settings['crm_preferences'] ?? [],
]);
