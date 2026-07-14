<?php
// server/public/webhooks/call_done.php
//
// PhoneBurner webhook: api_calldone
// Fired by PhoneBurner when a call in a dial session is dispositioned.
// Updates session state + per-day per-agent stats so SSE + overlay can show progress.
//
// ── PAYLOAD SHAPE (real example, captured 2026-06-18) ──────────────────────
// This is the DIAL-SESSION webhook envelope. It is DIFFERENT from the
// softphone_call_done.php envelope — do not cross-pollinate. Key differences:
//   * agent identity here is `payload.agent.user_id` (softphone uses
//     `payload.custom_data.pb_user_id`).
//   * `custom_data` here is an empty ARRAY (softphone sends it as an OBJECT).
//   * `call_id` here is an INTEGER (softphone sends a UUID string).
//   * `contact` here is RICH — user_id, lead_id, external_id, phones[], notes,
//     custom_fields, call_history[] (softphone sends only crm_id/crm_name/phone).
//   * `ds_id` (dial session ID) is present here, absent on softphone.
//
// Trimmed example (dropping heavy PII fields for readability — those are still
// present in real payloads, so treat this envelope as PII-bearing and only log
// selectively):
//
//   {
//     "status": "Interested",                // disposition text
//     "duration": 11,                        // seconds
//     "start_time": "2026-06-18 10:51:04",
//     "end_time":   "2026-06-18 10:51:15",
//     "call_id": 3024188937,                 // integer
//     "ds_id":   46858573,                   // dial session ID
//     "connected": "1",                      // stringified 0/1
//     "recording_link":         "/recording/…/recording.mp3",
//     "recording_link_public":  "/recording/pub/…mp3",
//     "recording_url":          "http://www.phoneburner.com/recording/…mp3",
//     "recording_url_public":   "http://www.phoneburner.com/recording/pub/…mp3",
//     "direction": "outbound",
//     "call_notes": [],                      // agent-typed strings (PII)
//     "contact": {                           // PII-heavy — see below
//       "user_id": 1217944038,               // PB internal contact id
//       "lead_id": "16138032",
//       "external_id": "",                   // populated if we set external_crm_data
//       "phone": "…", "first_name": "…", "last_name": "…",
//       "phones": [...], "primary_address": {...}, "addresses": [...],
//       "description": "…", "notes": "…entire call history in text…",
//       "primary_email": "…", "emails": ["…"],
//       "account": { "company_name": null, "title": null },
//       "tags": [], "calls": { "total_calls": 22, "last_call_time": "…" }
//     },
//     "owner": { "owner_id": 673245032, "first_name": "…", "last_name": "…",
//                "username": "…@…", "email": "…@…" },
//     "agent": { "user_id": "673245032", "first_name": "…", "last_name": "…",
//                "username": "…@…", "email": "…@…" },
//     "custom_fields": { "Manager": "…", "Custom Date": "…", ... },
//     "typed_custom_fields": [ { "type": "6", "name": "Manager", "value": "…" }, ... ],
//     "custom_data": [],                     // EMPTY ARRAY on dial-session (unlike softphone)
//     "outbound_caller_id": "+18885577291",
//     "call_history": [ { "call_id": "…", "datetime": "…", "phone_number": "…",
//                         "connected": true|false }, ... ],
//     "lead_source": { "lead_source_id": 22126, "lead_source_name": "API" },
//     "total_call_attempts": 19,
//     "follow_up": "2026-06-25 00:00:00",
//     "folder": { "id": "13186", "name": "Contacts" },
//     "events": { "last_event": null, "next_event": null }
//   }
//
// PII fields to keep OUT of any casual log line: contact.first_name /
// last_name / phone / phones / primary_address / addresses / description /
// notes / primary_email / emails, call_notes, owner.email, agent.email.
// The initial log line at the top of the file only records status / connected
// / duration / has_agent for exactly this reason.

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
// CRM-specific call logging (provider-isolated, fire-and-forget)
// Each provider owns its own call logging logic in its directory.
// Add new providers here as they implement call logging.
// -----------------------------------------------------------------------------
$crmName = $state['crm_name'] ?? '';

if ($crmName === 'close') {
    try {
        require_once __DIR__ . '/../api/crm/close/close_call_logger.php';
        close_log_call($state, $payload, $lastCall, $status);
    } catch (\Throwable $e) {
        log_msg('close_call_log_error: ' . $e->getMessage());
    }
}

if ($crmName === 'hubspot') {
    try {
        require_once __DIR__ . '/../api/crm/hubspot/hs_call_logger.php';
        hubspot_log_call($state, $payload, $lastCall, $status);
    } catch (\Throwable $e) {
        log_msg('hubspot_call_log_error: ' . $e->getMessage());
    }
}

if ($crmName === 'apollo') {
    try {
        require_once __DIR__ . '/../api/crm/apollo/apollo_call_logger.php';
        apollo_log_call($state, $payload, $lastCall, $status);
    } catch (\Throwable $e) {
        log_msg('apollo_call_log_error: ' . $e->getMessage());
    }
}

echo 'OK';
