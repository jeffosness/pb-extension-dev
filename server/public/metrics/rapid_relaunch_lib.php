<?php
// server/public/metrics/rapid_relaunch_lib.php
//
// Detects "rapid re-launches": the same extension install (client_id) creating
// two dial sessions within a few seconds of each other. GH #261.
//
// Why it matters: each launch registers its session with the follow-me widget,
// and the widget follows whichever registered LAST. If the user then dials in
// the other dialer window, PhoneBurner's webhooks land on a session the widget
// isn't listening to and the widget silently goes quiet.
//
// Everything here reads data the server already writes, so this needs no
// extension release:
//   - sessions/<token>.json   client_id, created_at, crm_name, and whether PB
//                             ever reached it (current / last_call / stats).
//                             Retained 7 days by the cleanup cron.
//   - metrics/sse_usage-YYYY-MM-DD.log   sse.connect events show which session
//                             the widget actually connected to.
//
// Output only ever carries hashes (first 12 of sha256), never the raw
// client_id, session token or any contact data.
//
// Pure functions (no HTTP, no globals) so tests/RapidRelaunchTest.php can
// exercise them against temp-dir fixtures.

declare(strict_types=1);

const RR_DEFAULT_WINDOW_SEC = 60;
// Gap at or under which a pair is most likely an accidental double-click. Wider
// gaps are mostly deliberate re-launches (wrong list, retry), which a guard
// must NOT block. First week of prod data: nearly all pairs were 8-59s.
const RR_DOUBLE_CLICK_SEC   = 5;
const RR_MAX_SESSION_FILES  = 20000; // hard cap so a runaway sessions/ dir can't hang the dashboard

function rr_hash(string $s): string {
    return substr(hash('sha256', $s), 0, 12);
}

/**
 * Parse a session's created_at (ISO 8601 from date('c'); tolerate unix ints).
 */
function rr_parse_created($v): ?int {
    if (is_int($v) || (is_string($v) && ctype_digit($v))) return (int)$v;
    if (!is_string($v) || $v === '') return null;
    $ts = strtotime($v);
    return $ts === false ? null : $ts;
}

/**
 * Summarize one decoded session file. Returns null when it lacks the fields we
 * need (client_id / created_at), e.g. a half-written or very old file.
 */
function rr_session_row(string $token, array $state): ?array {
    $clientId = $state['client_id'] ?? null;
    $created  = rr_parse_created($state['created_at'] ?? null);
    if (!is_string($clientId) || $clientId === '' || $created === null) return null;

    $calls = (int)($state['stats']['total_calls'] ?? 0);
    // Launch endpoints initialise current + last_call to null; only PB's
    // contact_displayed / call_done webhooks fill them in.
    $gotWebhooks = !empty($state['current']) || !empty($state['last_call']) || $calls > 0;

    return [
        'session_hash' => rr_hash($token),
        'client_hash'  => rr_hash($clientId),
        'created'      => $created,
        'crm_name'     => is_string($state['crm_name'] ?? null) ? $state['crm_name'] : 'unknown',
        'calls'        => $calls,
        'got_webhooks' => $gotWebhooks,
    ];
}

/**
 * Read every session file in $dir created at or after $sinceTs.
 * Returns ['rows' => [...], 'scanned' => int, 'truncated' => bool].
 */
function rr_read_sessions(string $dir, int $sinceTs, int $maxFiles = RR_MAX_SESSION_FILES): array {
    $out = ['rows' => [], 'scanned' => 0, 'truncated' => false];
    if (!is_dir($dir)) return $out;

    // Session filenames are bare [A-Za-z0-9] tokens (see session_file_path()).
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $token = basename($file, '.json');
        if (!preg_match('/^[A-Za-z0-9]+$/', $token)) continue;
        // created_at <= mtime, so anything last touched before the window is out.
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $sinceTs) continue;

        if ($out['scanned'] >= $maxFiles) { $out['truncated'] = true; break; }
        $out['scanned']++;

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') continue;
        $state = json_decode($raw, true);
        if (!is_array($state)) continue;

        $row = rr_session_row($token, $state);
        if ($row && $row['created'] >= $sinceTs) $out['rows'][] = $row;
    }
    return $out;
}

/**
 * Set of session hashes the follow-me widget connected to (any sse.connect),
 * read from the daily SSE usage logs for the given Y-m-d dates.
 */
function rr_read_widget_sessions(string $metricsDir, array $dates): array {
    $seen = [];
    foreach ($dates as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $file = $metricsDir . '/sse_usage-' . $d . '.log';
        if (!is_file($file)) continue;
        $fh = @fopen($file, 'rb');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            // Cheap pre-filter: most lines are heartbeats/disconnects.
            if (strpos($line, 'sse.connect') === false) continue;
            $j = json_decode($line, true);
            if (!is_array($j) || ($j['event'] ?? '') !== 'sse.connect') continue;
            $h = $j['session_token_hash'] ?? '';
            if (is_string($h) && $h !== '') $seen[$h] = true;
        }
        fclose($fh);
    }
    return $seen;
}

/**
 * Pair up consecutive sessions from the same client created <= $windowSec apart.
 *
 * A pair is flagged `widget_mismatch` when PB reached one session but the
 * widget only ever connected to the other one: the exact failure in GH #261.
 *
 * @param array $rows       rr_session_row() results
 * @param array $widgetSeen session_hash => true, from rr_read_widget_sessions()
 */
function rr_find_pairs(array $rows, array $widgetSeen, int $windowSec = RR_DEFAULT_WINDOW_SEC): array {
    $byClient = [];
    foreach ($rows as $r) $byClient[$r['client_hash']][] = $r;

    $pairs = [];
    foreach ($byClient as $list) {
        if (count($list) < 2) continue;
        usort($list, fn($a, $b) => $a['created'] <=> $b['created']);
        for ($i = 1; $i < count($list); $i++) {
            $a = $list[$i - 1];
            $b = $list[$i];
            $gap = $b['created'] - $a['created'];
            if ($gap > $windowSec) continue;

            $side = function (array $s) use ($widgetSeen): array {
                return [
                    'session_hash'     => $s['session_hash'],
                    'crm_name'         => $s['crm_name'],
                    'calls'            => $s['calls'],
                    'got_webhooks'     => $s['got_webhooks'],
                    'widget_connected' => isset($widgetSeen[$s['session_hash']]),
                ];
            };
            $first  = $side($a);
            $second = $side($b);

            $mismatch =
                ($first['got_webhooks'] && !$first['widget_connected'] && $second['widget_connected'] && !$second['got_webhooks']) ||
                ($second['got_webhooks'] && !$second['widget_connected'] && $first['widget_connected'] && !$first['got_webhooks']);

            $pairs[] = [
                'at'              => date('c', $a['created']),
                'at_unix'         => $a['created'],
                'gap_sec'         => $gap,
                'kind'            => $gap <= RR_DOUBLE_CLICK_SEC ? 'double_click' : 'relaunch',
                'client_hash'     => $a['client_hash'],
                'first'           => $first,
                'second'          => $second,
                'widget_mismatch' => $mismatch,
            ];
        }
    }
    usort($pairs, fn($x, $y) => $y['at_unix'] <=> $x['at_unix']);
    return $pairs;
}

/**
 * @param array $sessionsByCrm crm_name => sessions created in the window (the
 *                             per-CRM denominator, so a busy CRM doesn't look
 *                             worse just for having more sessions)
 */
function rr_summarize(array $pairs, int $sessionsInWindow, array $sessionsByCrm = []): array {
    $clients = [];
    $mismatches = 0;
    $doubleClicks = 0;
    $byCrm = [];
    foreach ($sessionsByCrm as $crm => $n) {
        $byCrm[$crm] = ['sessions' => (int)$n, 'pairs' => 0, 'double_click' => 0, 'relaunch' => 0, 'widget_mismatch' => 0, 'users' => []];
    }
    foreach ($pairs as $p) {
        $clients[$p['client_hash']] = true;
        if ($p['widget_mismatch']) $mismatches++;
        if ($p['kind'] === 'double_click') $doubleClicks++;

        // Attribute the pair to the second launch's CRM (the one the user went on to use).
        $crm = $p['second']['crm_name'];
        $byCrm[$crm] = $byCrm[$crm] ?? ['sessions' => 0, 'pairs' => 0, 'double_click' => 0, 'relaunch' => 0, 'widget_mismatch' => 0, 'users' => []];
        $byCrm[$crm]['pairs']++;
        $byCrm[$crm][$p['kind']]++;
        if ($p['widget_mismatch']) $byCrm[$crm]['widget_mismatch']++;
        $byCrm[$crm]['users'][$p['client_hash']] = true;
    }
    foreach ($byCrm as $crm => $row) {
        if ($row['pairs'] === 0) { unset($byCrm[$crm]); continue; }
        $byCrm[$crm]['users'] = count($row['users']);
    }
    uasort($byCrm, fn($a, $b) => $b['pairs'] <=> $a['pairs']);

    return [
        'pairs'              => count($pairs),
        'double_click'       => $doubleClicks,
        'relaunch'           => count($pairs) - $doubleClicks,
        'double_click_sec'   => RR_DOUBLE_CLICK_SEC,
        'widget_mismatch'    => $mismatches,
        'users_affected'     => count($clients),
        'sessions_in_window' => $sessionsInWindow,
        'by_crm'             => $byCrm,
    ];
}

function rr_sessions_by_crm(array $rows): array {
    $out = [];
    foreach ($rows as $r) $out[$r['crm_name']] = ($out[$r['crm_name']] ?? 0) + 1;
    return $out;
}
