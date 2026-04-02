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
$payload = json_decode($raw, true);

log_msg('call_done: ' . json_encode([
    'has_payload'  => is_array($payload),
    'status'       => $payload['status'] ?? null,
    'connected'    => $payload['connected'] ?? null,
    'duration'     => $payload['duration'] ?? null,
    'has_agent'    => isset($payload['agent']),
]));
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
$state['last_activity_unix'] = time(); // Track webhook activity for SSE timeout

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

// -----------------------------------------------------------------------------
// Close CRM: log call activity back to Close (fire-and-forget, non-blocking)
// -----------------------------------------------------------------------------
if (($state['crm_name'] ?? '') === 'close') {
    try {
        $closeClientId = $state['client_id'] ?? '';
        if ($closeClientId !== '') {
            $closeTokens = load_close_tokens($closeClientId);
            if (is_array($closeTokens) && !empty($closeTokens['access_token'])) {
                $closeAccess = (string)$closeTokens['access_token'];

                // Refresh token if expired (dial sessions can last > 1 hour)
                $closeExpiresAt = isset($closeTokens['expires_at']) ? (int)$closeTokens['expires_at'] : 0;
                if ($closeExpiresAt > 0 && time() >= $closeExpiresAt) {
                    $closeRefresh = $closeTokens['refresh_token'] ?? '';
                    if ($closeRefresh !== '') {
                        $closeCfg = cfg();
                        $refreshResp = null;
                        $ch = curl_init('https://api.close.com/oauth2/token/');
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => http_build_query([
                                'grant_type'    => 'refresh_token',
                                'client_id'     => $closeCfg['CLOSE_CLIENT_ID'] ?? '',
                                'client_secret' => $closeCfg['CLOSE_CLIENT_SECRET'] ?? '',
                                'refresh_token' => $closeRefresh,
                            ]),
                            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                            CURLOPT_TIMEOUT => 10,
                        ]);
                        $refreshRaw = curl_exec($ch);
                        $refreshCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($refreshCode >= 200 && $refreshCode < 300 && $refreshRaw) {
                            $refreshResp = json_decode($refreshRaw, true);
                        }

                        if (is_array($refreshResp) && !empty($refreshResp['access_token'])) {
                            $closeAccess = (string)$refreshResp['access_token'];
                            // Preserve refresh_token if not returned
                            if (empty($refreshResp['refresh_token'])) {
                                $refreshResp['refresh_token'] = $closeRefresh;
                            }
                            $now = time();
                            $expiresIn = isset($refreshResp['expires_in']) ? (int)$refreshResp['expires_in'] : 3600;
                            $refreshResp['created_at'] = $now;
                            $refreshResp['expires_at'] = $now + max(0, $expiresIn - 60);
                            save_close_tokens($closeClientId, $refreshResp);
                            log_msg('close_call_log_token_refresh: success');
                        } else {
                            log_msg('close_call_log_token_refresh: failed (http=' . $refreshCode . ')');
                        }
                    }
                }

                // Find the current contact's Close IDs from contacts_map
                $currentContact = $state['current'] ?? [];
                $lookupKey = $currentContact['lookup_key'] ?? null;
                $mapEntry = ($lookupKey && isset($state['contacts_map'][$lookupKey]))
                    ? $state['contacts_map'][$lookupKey]
                    : null;

                if ($mapEntry) {
                    $closeContactId = $mapEntry['crm_identifier'] ?? '';
                    $closePhone = $mapEntry['phone'] ?? '';

                    // Extract lead_id from record_url
                    $closeLeadId = '';
                    $recordUrl = $mapEntry['record_url'] ?? '';
                    if (preg_match('/\/lead\/(lead_[a-zA-Z0-9]+)/', $recordUrl, $m)) {
                        $closeLeadId = $m[1];
                    }

                    if ($closeLeadId !== '' && $closeContactId !== '') {
                        // Build note from PB status
                        $noteHtml = '<p>Call via PhoneBurner: ' . htmlspecialchars($status ?: 'Unknown') . '</p>';
                        if (($lastCall['duration'] ?? 0) > 0) {
                            $noteHtml .= '<p>Duration: ' . (int)$lastCall['duration'] . ' seconds</p>';
                        }

                        $callData = [
                            'lead_id'    => $closeLeadId,
                            'contact_id' => $closeContactId,
                            'direction'  => 'outbound',
                            'status'     => 'completed',
                            'duration'   => (int)($lastCall['duration'] ?? 0),
                            'phone'      => $closePhone,
                            'note_html'  => $noteHtml,
                        ];

                        // Direct curl POST — avoids close_helpers.php which depends
                        // on bootstrap.php functions (api_error, cfg) not available
                        // in webhook context
                        $ch = curl_init('https://api.close.com/api/v1/activity/call/');
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => json_encode($callData),
                            CURLOPT_HTTPHEADER     => [
                                'Authorization: Bearer ' . $closeAccess,
                                'Content-Type: application/json',
                                'Accept: application/json',
                            ],
                            CURLOPT_TIMEOUT => 10,
                        ]);
                        $rawResp = curl_exec($ch);
                        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        $logData = [
                            'http_code'   => $httpCode,
                            'success'     => ($httpCode >= 200 && $httpCode < 300),
                            'lead_id'     => $closeLeadId,
                            'contact_id'  => $closeContactId,
                            'pb_status'   => $status,
                        ];
                        // Include Close error response for debugging 400s
                        if ($httpCode >= 400 && $rawResp) {
                            $errBody = json_decode($rawResp, true);
                            $logData['close_error'] = is_array($errBody) ? $errBody : substr($rawResp, 0, 500);
                        }
                        log_msg('close_call_log: ' . json_encode($logData));
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Non-blocking: log error but don't fail the webhook
        log_msg('close_call_log_error: ' . $e->getMessage());
    }
}

echo 'OK';
