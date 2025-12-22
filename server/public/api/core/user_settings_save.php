<?php
// generic_crm/api/user_settings_save.php
require_once __DIR__ . '/../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

// New: preferred structure from the extension
$goals       = $data['goals']        ?? null;

// Legacy: we still accept crm_patterns for backwards compatibility,
// but we don't require it anymore.
$crmPatterns = $data['crm_patterns'] ?? null;

if ($goals === null && $crmPatterns === null) {
    json_response(
        [
            'ok'    => false,
            'error' => 'No settings supplied (expected goals and/or crm_patterns)',
        ],
        400
    );
}

// Resolve which PhoneBurner member we're talking about
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

// Load existing settings or initialize a default structure
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

// ---- Update GOALS (new behavior) ----
if (is_array($goals)) {
    $primary   = isset($goals['primary']) ? trim((string)$goals['primary']) : '';
    $secondary = isset($goals['secondary']) ? trim((string)$goals['secondary']) : '';

    if ($primary === '') {
        $primary = 'Set Appointment';
    }
    if ($secondary === '') {
        $secondary = 'Follow Up';
    }

    $settings['goals'] = [
        'primary'   => $primary,
        'secondary' => $secondary,
    ];
}

// ---- Optional: still honour crm_patterns if present (legacy) ----
if (is_array($crmPatterns)) {
    $settings['crm_patterns'] = $crmPatterns;
}

$settings['updated_at'] = date('c');

save_user_settings($memberUserId, $settings);

json_response([
    'ok'           => true,
    'goals'        => $settings['goals']        ?? [],
    'crm_patterns' => $settings['crm_patterns'] ?? [],
]);
