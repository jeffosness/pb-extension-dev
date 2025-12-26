<?php
// server/public/webhooks/call_done.php
//
// PhoneBurner webhook: api_calldone
// Updates session state + per-day per-agent stats so SSE + overlay can show progress.

require_once __DIR__ . '/../utils.php';

$session_token = $_GET['s'] ?? null;
if (!$session_token) {
    http_response_code(400);
    echo 'Missing session token';
    exit;
}

$raw = file_get_contents('php://input');
log_msg('call_done: ' . $raw);

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$state = load_session_state($session_token) ?? [];

// Build lastCall snapshot
$lastCall = [
    'received_at'  => date('c'),
    'raw'          => $payload,
    'status'       => $payload['status']    ?? null,
    'duration'     => $payload['duration']  ?? null,
    'call_id'      => $payload['call_id']   ?? null,
    'ds_id'        => $payload['ds_id']     ?? null,
    'connected'    => $payload['connected'] ?? null,
    'webhook_type' => 'call_done',
];

if (isset($payload['contact'])) {
    $contact = $payload['contact'];
    $lastCall['contact_name']  = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
    $lastCall['contact_phone'] = $contact['phone'] ?? null;
}

if (isset($payload['custom_data'])) {
    $lastCall['custom_data'] = $payload['custom_data'];
}

$state['last_call']       = $lastCall;
$state['last_event_type'] = 'call_done';

// stats
if (!isset($state['stats']) || !is_array($state['stats'])) {
    $state['stats'] = [
        'total_calls'  => 0,
        'connected'    => 0,
        'appointments' => 0,
        'by_status'    => [],
    ];
} else {
    if (!isset($state['stats']['by_status']) || !is_array($state['stats']['by_status'])) {
        $state['stats']['by_status'] = [];
    }
}

$stats        = $state['stats'];
$status       = $lastCall['status'] ?? '';
$connectedVal = $lastCall['connected'] ?? null;

// 1) Total calls
$stats['total_calls'] = ($stats['total_calls'] ?? 0) + 1;

// 2) Connected
if (strtolower((string)$connectedVal) === '1') {
    $stats['connected'] = ($stats['connected'] ?? 0) + 1;
}

// 3) Appointments (basic heuristic)
$statusLower = strtolower($status);
if (str_contains($statusLower, 'set appointment') || str_contains($statusLower, 'appointment')) {
    $stats['appointments'] = ($stats['appointments'] ?? 0) + 1;
}

// 4) by_status bucket (for custom goals on client side)
if ($status !== '') {
    if (!isset($stats['by_status'][$status])) {
        $stats['by_status'][$status] = 0;
    }
    $stats['by_status'][$status]++;
}

$state['stats'] = $stats;

// --- Daily per-agent stats ---
if (isset($payload['agent']) && isset($payload['agent']['user_id'])) {
    $agentId = (string)$payload['agent']['user_id'];

    // Use the call's own timestamps rather than the server clock.
    $dateKey = null;

    if (!empty($payload['end_time'])) {
        $dateKey = substr($payload['end_time'], 0, 10);
    } elseif (!empty($payload['start_time'])) {
        $dateKey = substr($payload['start_time'], 0, 10);
    } else {
        $dateKey = gmdate('Y-m-d');
    }

    $dailyDir = __DIR__ . '/../daily_stats';
    if (!is_dir($dailyDir)) {
        @mkdir($dailyDir, 0775, true);
    }

    $dailyFile = $dailyDir . '/' . $dateKey . '_' . $agentId . '.json';

    $dailyData = [
        'agent_id' => $agentId,
        'date'     => $dateKey,
        'stats'    => [
            'total_calls'  => 0,
            'connected'    => 0,
            'appointments' => 0,
            'by_status'    => [],
        ],
    ];

    if (file_exists($dailyFile)) {
        $decoded = json_decode(file_get_contents($dailyFile), true);
        if (is_array($decoded) && isset($decoded['stats']) && is_array($decoded['stats'])) {
            $dailyData['stats'] = array_merge($dailyData['stats'], $decoded['stats']);
            if (!isset($dailyData['stats']['by_status']) || !is_array($dailyData['stats']['by_status'])) {
                $dailyData['stats']['by_status'] = [];
            }
        }
    }

    $dailyData['stats']['total_calls']++;

    if (strtolower((string)($lastCall['connected'] ?? '')) === '1' || ($lastCall['connected'] ?? null) === 1) {
        $dailyData['stats']['connected']++;
    }

    $statusLabel = $lastCall['status'] ?? '';
    $statusLower = strtolower($statusLabel);

    if ($statusLabel && (str_contains($statusLower, 'set appointment') || str_contains($statusLower, 'appointment'))) {
        $dailyData['stats']['appointments']++;
    }

    if ($statusLabel) {
        if (!isset($dailyData['stats']['by_status'][$statusLabel])) {
            $dailyData['stats']['by_status'][$statusLabel] = 0;
        }
        $dailyData['stats']['by_status'][$statusLabel]++;
    }

    file_put_contents($dailyFile, json_encode($dailyData));

    // Mirror daily stats into SSE session state
    $state['daily_stats'] = $dailyData['stats'];
}

save_session_state($session_token, $state);

echo 'OK';
