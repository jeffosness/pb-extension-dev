<?php
// generic_crm/utils.php

function cfg()
{
    static $cfg;
    if (!$cfg) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function ensure_dir($dir)
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function log_msg($msg)
{
    $cfg = cfg();
    if (!empty($cfg['LOG_FILE'])) {
        $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
        file_put_contents($cfg['LOG_FILE'], $line, FILE_APPEND);
    }
}

function json_input()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    return $data;
}

function json_response($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *'); 
    echo json_encode($data);
    exit;
}

/**
 * We’ll have the extension send a client_id with every API call.
 */
function get_client_id_or_fail($from = null)
{
    $client_id = null;

    // 1) Prefer explicit value from parsed JSON/body
    if ($from && isset($from['client_id'])) {
        $client_id = $from['client_id'];
    }
    // 2) Fallback: URL ?client_id=...
    elseif (isset($_GET['client_id'])) {
        $client_id = $_GET['client_id'];
    }
    // 3) Fallback: X-Client-Id header (used by HubSpot popup hsPost)
    elseif (!empty($_SERVER['HTTP_X_CLIENT_ID'])) {
        $client_id = $_SERVER['HTTP_X_CLIENT_ID'];
    }

    if (!$client_id) {
        json_response(['ok' => false, 'error' => 'Missing client_id'], 400);
    }

    // Keep it filesystem-safe
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $client_id);
}


function token_file_path($client_id)
{
    $dir = cfg()['TOKENS_DIR'];
    ensure_dir($dir);
    return $dir . '/' . $client_id . '.json';
}

function save_pb_token($client_id, $pat)
{
    $path = token_file_path($client_id);
    $data = [
        'pat' => $pat,
        'saved_at' => date('c'),
    ];
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

function load_pb_token($client_id)
{
    $path = token_file_path($client_id);
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    return $data['pat'] ?? null;
}

function clear_pb_token($client_id)
{
    $path = token_file_path($client_id);
    if (file_exists($path)) {
        unlink($path);
    }
}

// -------------------------------------------------------------------------
// HubSpot token helpers (per client_id, stored under TOKENS_DIR/hubspot)
// -------------------------------------------------------------------------

function hs_token_file_path($client_id)
{
    $dir = rtrim(cfg()['TOKENS_DIR'], '/\\') . '/hubspot';
    ensure_dir($dir);
    return $dir . '/' . $client_id . '.json';
}

function save_hs_tokens($client_id, array $tokens)
{
    $path = hs_token_file_path($client_id);
    $tokens['saved_at'] = date('c');
    file_put_contents($path, json_encode($tokens, JSON_PRETTY_PRINT));
}

function load_hs_tokens($client_id)
{
    $path = hs_token_file_path($client_id);
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    return $data;
}

function clear_hs_tokens($client_id)
{
    $path = hs_token_file_path($client_id);
    if (file_exists($path)) {
        @unlink($path);
    }
}


function get_user_settings_dir()
{
    $cfg = cfg();
    return isset($cfg['USER_SETTINGS_DIR'])
        ? $cfg['USER_SETTINGS_DIR']
        : __DIR__ . '/user_settings';
}

/**
 * Find the PhoneBurner member_user_id for a given client_id by scanning
 * user_settings/*.json and looking for a matching client_ids entry.
 *
 * Returns member_user_id (string) or null if not found.
 */
function resolve_member_user_id_for_client($client_id)
{
    $dir = get_user_settings_dir();
    if (!is_dir($dir)) {
        return null;
    }

    $files = glob($dir . '/*.json');
    if (!$files) {
        return null;
    }

    foreach ($files as $file) {
        $json = file_get_contents($file);
        if ($json === false || $json === '') {
            continue;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            continue;
        }

        $clientIds = $data['client_ids'] ?? [];
        if (!is_array($clientIds)) {
            continue;
        }

        if (in_array($client_id, $clientIds, true)) {
            // Found the matching user
            return isset($data['member_user_id'])
                ? (string)$data['member_user_id']
                : null;
        }
    }

    return null;
}

/**
 * Save user settings back to disk (full overwrite for that member_user_id).
 */
function save_user_settings($memberUserId, array $settings)
{
    $path = user_settings_file_path($memberUserId);
    file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT));
}



function user_settings_file_path($memberUserId)
{
    // Use the configured USER_SETTINGS_DIR if present; fall back to ./user_settings
    $cfg = cfg();
    $dir = isset($cfg['USER_SETTINGS_DIR'])
        ? $cfg['USER_SETTINGS_DIR']
        : __DIR__ . '/user_settings';

    ensure_dir($dir);

    // Keep filenames safe
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$memberUserId);

    return $dir . '/' . $safeId . '.json';
}

function load_user_settings($memberUserId)
{
    $path = user_settings_file_path($memberUserId);
    if (!file_exists($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Save or update the per-user settings file when a PAT is saved.
 *
 * - Keyed by PhoneBurner member_user_id (agent id)
 * - Tracks which browser client_ids are associated with this PB user
 * - Stores a "profile" section from /members/me
 * - Leaves room for crm_patterns and goals later
 */
function save_user_profile($client_id, $memberUserId, $memberData)
{
    $path = user_settings_file_path($memberUserId);

    // Load existing settings if present
    $existing = load_user_settings($memberUserId);

    $now = date('c');

    $settings = [
        'member_user_id' => (string)$memberUserId,
        'client_ids'     => [],
        'profile'        => [],
        // Placeholders for future config:
        'crm_patterns'   => [],
        'goals'          => [],
        'created_at'     => $now,
        'updated_at'     => $now,
    ];

    if (is_array($existing)) {
        // Preserve existing data (patterns, goals, timestamps)
        $settings['crm_patterns'] = $existing['crm_patterns'] ?? [];
        $settings['goals']        = $existing['goals']        ?? [];
        $settings['created_at']   = $existing['created_at']   ?? $now;
        $settings['client_ids']   = $existing['client_ids']   ?? [];
    }

    // Merge this client_id into the list (avoid duplicates)
    if (!in_array($client_id, $settings['client_ids'], true)) {
        $settings['client_ids'][] = $client_id;
    }

    // Store some basic profile info from /members/me
    $settings['profile'] = [
        'username'      => $memberData['username']      ?? null,
        'first_name'    => $memberData['first_name']    ?? null,
        'last_name'     => $memberData['last_name']     ?? null,
        'email_address' => $memberData['email_address'] ?? null,
        'display_name'  => $memberData['display_name']  ?? null,
    ];

    $settings['updated_at'] = $now;

    file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT));
}


function session_file_path($session_token)
{
    $safe = preg_replace('/[^a-zA-Z0-9]/', '', $session_token);
    return __DIR__ . '/sessions/' . $safe . '.json';
}

function save_session_state($session_token, array $state)
{
    $path = session_file_path($session_token);
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, json_encode($state));
}

function load_session_state($session_token)
{
    $path = session_file_path($session_token);
    if (!file_exists($path)) {
        return null;
    }
    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}


// -----------------------------------------------------------------------------
// Compatibility wrappers for older HubSpot helpers
// -----------------------------------------------------------------------------

/**
 * Backwards-compatible alias for json_response().
 * NOTE: Some HubSpot scripts expect `json_out($payload, $statusCode)`.
 */
function json_out($data, $status = 200)
{
    return json_response($data, $status);
}

/**
 * Backwards-compatible alias for json_input().
 * Older HubSpot code calls this read_json().
 */
function read_json()
{
    return json_input();
}


/**
 * Simple helper for posting application/x-www-form-urlencoded and
 * decoding JSON – used for HubSpot OAuth token exchange.
 */
function http_post_form($url, array $fields)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($err) {
        log_msg("http_post_form error: $err");
        return [0, null];
    }

    $status = isset($info['http_code']) ? (int)$info['http_code'] : 0;
    $data   = json_decode($resp, true);

    return [$status, $data];
}



/**
 * Basic PhoneBurner API call helper using PAT.
 * $method: 'GET', 'POST', 'PUT', etc.
 * $path: e.g. '/dialsession' (we’ll assemble with PB_API_BASE).
 */
function pb_api_call($pat, $method, $path, $body = null)
{
    $url = rtrim(cfg()['PB_API_BASE'], '/') . '/' . ltrim($path, '/');

    $headers = [
        'Authorization: Bearer ' . $pat,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if (!is_null($body)) {
        $payload = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        log_msg("pb_api_call error: $err");
        return [null, ['error' => $err]];
    }

    $data = json_decode($resp, true);
    return [$info, $data];
}
