<?php
// server/public/api/core/token_summary_lib.php
//
// Shared computation for the dashboard's "Token Security (last 24h)" section.
//
// Two callers:
//   1) metrics/crm_usage_dashboard.php — renders the section server-side at
//      page load for fast first paint (unchanged behavior).
//   2) api/core/token_summary_stats.php — returns the same computation as
//      JSON so the dashboard's JS auto-refresh can update the section
//      without a full page reload.
//
// Extracted from the inline PHP block in crm_usage_dashboard.php on
// 2026-07-17 so the auto-refresh path could reuse the same logic without
// drift. Do NOT put HTML-encoding inside strings here — return raw text
// and let each caller escape as appropriate for its output surface
// (server-side htmlspecialchars call, or JS textContent).
//
// IMPORTANT: do not write literal PHP close tags inside these comments.
// PHP treats "close tag inside // comment" as an actual close, which
// terminates PHP mode mid-file and turns everything below into raw
// output. That bug caused the initial deploy of this file to serve
// source code from api/core/token_summary_stats.php instead of JSON.

require_once __DIR__ . '/../../utils.php';

/**
 * Return the per-provider whitelist of script names that are EXPECTED to
 * read tokens. Anything outside this list gets flagged as an anomaly.
 *
 * KEEP THIS IN SYNC when adding a new endpoint that calls load_pb_token(),
 * load_hs_tokens(), load_close_tokens(), or load_apollo_tokens() — the
 * CLAUDE.md Security Checklist reminds you to update this array. See
 * LESSONS.md 2026-07-09 for the class of drift this catches.
 */
function token_read_whitelist(): array
{
    return [
        'pb' => [
            'state', 'oauth_pb_save', 'oauth_pb_clear', 'session_stop',
            'dialsession_from_scan', 'pb_dialsession_selection',
            'pb_dialsession_from_list', 'pb_dialsession_from_tasks',
            'hs_call_logger', 'close_call_logger', 'apollo_call_logger',
            'call_done', 'contact_displayed', 'refresh_sse_code',
            'user_settings_get', 'user_settings_save', 'track_crm_usage',
            'apollo_sequences', 'apollo_sequence_tasks',
            'hs_lists', 'hs_phone_properties',
            // v0.8.0 click-to-call flow. Both endpoints call load_pb_token():
            //   softphone_auth_code — mints a single-use code for the extension
            //   softphone           — exchanges the code and embeds the PAT in
            //                         the softphone iframe src
            'softphone_auth_code', 'softphone',
        ],
        'hubspot' => [
            'state', 'oauth_hs_finish', 'oauth_disconnect',
            'pb_dialsession_selection', 'pb_dialsession_from_list',
            'pb_dialsession_from_tasks', 'hs_lists', 'hs_phone_properties',
            'hs_call_logger', 'call_done', 'contact_displayed',
            // v0.8.2 CTC-completes-task flow (PR #172) added HubSpot token
            // reads on both softphone endpoints. See LESSONS.md 2026-07-09.
            'softphone_auth_code', 'softphone_call_done',
        ],
        'close' => [
            'state', 'oauth_close_finish', 'oauth_disconnect',
            'pb_dialsession_selection', 'close_call_logger',
            'call_done', 'contact_displayed',
        ],
        'apollo' => [
            'state', 'oauth_apollo_finish', 'oauth_disconnect', 'save_api_key',
            'pb_dialsession_selection', 'pb_dialsession_from_tasks',
            'apollo_sequences', 'apollo_sequence_tasks',
            'apollo_call_logger', 'call_done', 'contact_displayed',
        ],
    ];
}

/**
 * Read the token-audit log for the last 24 hours and return the summary +
 * detected anomalies in a shape the dashboard can render (server-side or
 * JS-side).
 *
 * Return shape:
 *   [
 *     'reads'       => int,
 *     'writes'      => int,
 *     'deletes'     => int,
 *     'errors'      => int,
 *     'by_prov'     => ['pb' => int, 'hubspot' => int, 'close' => int, 'apollo' => int],
 *     'anomalies'   => [ { t, evt, prov, ep, ip, cid, res, why }, ... ],
 *     'log_missing' => bool,       // true if audit-log file doesn't exist yet
 *     'audit_path'  => string,     // path we tried to read (for the empty-state hint)
 *   ]
 */
function compute_token_summary(): array
{
    // Anomaly-detection tunables. Adjust these in one place if the dashboard
    // starts crying wolf — or going quiet — about real-world traffic patterns.
    //
    // Why the carve-outs:
    //   - `state.php` is the popup connection-probe. Every popup open reads
    //     all 4 providers, and a non-connected user generates "misses" by
    //     design. Including state in burst/miss thresholds buries real signal
    //     under normal popup polling.
    //   - The original miss rule fired on (1 IP × many misses on 1 provider),
    //     but actual enumeration looks like (1 IP × many DISTINCT client_ids).
    //     One client repeatedly missing one provider = user without that CRM
    //     connected, opening popup a lot.
    //   - The enumeration rule ALSO ignores IPs that had ANY successful token
    //     reads in the window. A corporate NAT with N employees produces the
    //     same distinct-cid signature as an enumeration attacker, but a NAT
    //     will have some employees who successfully authenticate — an actual
    //     spray-attacker won't. Adding this compensating check killed the
    //     "6 employees behind an AWS VPN" false positive we hit 2026-07-06.
    //     The trade-off: an attacker who successfully steals one token during
    //     a spray campaign is no longer flagged by THIS rule, but they'd
    //     already have escalated to a bigger concern (successful token theft),
    //     which is a different alert to build.
    $BURST_THRESHOLD             = 50;          // reads/5min from same client
    $BURST_EXCLUDE_ENDPOINTS     = ['state'];   // endpoints exempt from burst
    $ENUM_DISTINCT_CIDS_PER_IP   = 5;           // distinct cids/IP triggers enum
    $DELETE_SPIKE_THRESHOLD      = 10;          // deletes/hour
    $DELETE_SPIKE_BUCKET_SECONDS = 3600;

    $whitelist = token_read_whitelist();
    $audit_path = audit_token_log_path();

    $summary = [
        'reads'       => 0,
        'writes'      => 0,
        'deletes'     => 0,
        'errors'      => 0,
        'by_prov'     => ['pb' => 0, 'hubspot' => 0, 'close' => 0, 'apollo' => 0],
        'anomalies'   => [],
        'log_missing' => false,
        'audit_path'  => $audit_path,
    ];

    if (!is_file($audit_path)) {
        $summary['log_missing'] = true;
        return $summary;
    }

    $cutoff = time() - 86400;

    // Burst detection: tally reads per (client_id_hash, 5-min bucket)
    $burst_buckets = [];
    // Miss tracking: per IP, count + the set of distinct client_ids that missed
    $miss_by_ip = [];
    // Delete spike tracking: per 1-hour bucket
    $delete_buckets = [];

    $fh = @fopen($audit_path, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $r = json_decode(trim($line), true);
            if (!is_array($r)) continue;
            $t = strtotime($r['t'] ?? '') ?: 0;
            if ($t < $cutoff) continue;

            $evt  = $r['evt']  ?? '';
            $prov = $r['prov'] ?? '';
            $ep   = $r['ep']   ?? '';
            $res  = $r['res']  ?? '';
            $cid  = $r['cid']  ?? '';
            $ip   = $r['ip']   ?? '';

            if ($evt === 'read')   $summary['reads']++;
            if ($evt === 'write')  $summary['writes']++;
            if ($evt === 'delete') $summary['deletes']++;
            if ($res === 'error')  $summary['errors']++;

            if (isset($summary['by_prov'][$prov])) {
                $summary['by_prov'][$prov]++;
            }

            // Anomaly check 1: endpoint not in whitelist for this provider.
            // Raw text in `why` — caller escapes for its output surface.
            if ($evt === 'read' && isset($whitelist[$prov])
                && !in_array($ep, $whitelist[$prov], true)) {
                $summary['anomalies'][] = $r + [
                    'why' => 'Endpoint "' . $ep . '" is not in the whitelist for ' . $prov . ' token reads',
                ];
            }

            // Anomaly check 2: burst — many reads from same client in 5 min.
            // Skip endpoints that are expected to be polled (e.g. state.php).
            if ($evt === 'read' && $cid !== ''
                && !in_array($ep, $BURST_EXCLUDE_ENDPOINTS, true)) {
                $bucket = $cid . '|' . floor($t / 300);
                if (!isset($burst_buckets[$bucket])) {
                    $burst_buckets[$bucket] = ['count' => 0, 'sample' => $r];
                }
                $burst_buckets[$bucket]['count']++;
            }

            // Anomaly check 3: enumeration. True enumeration = one IP
            // probing MANY DISTINCT client_ids AND finding zero. Track
            // both raw miss counts + distinct cids per IP, AND whether
            // the IP produced ANY successful reads (a real spray hits
            // nothing; a corporate NAT hits plenty for its connected
            // employees).
            if ($evt === 'read' && $ip !== '') {
                if (!isset($miss_by_ip[$ip])) {
                    $miss_by_ip[$ip] = [
                        'count'       => 0,
                        'sample'      => $r,
                        'cids'        => [],
                        'has_success' => false,
                    ];
                }
                if ($res === 'missing') {
                    $miss_by_ip[$ip]['count']++;
                    if ($cid !== '') $miss_by_ip[$ip]['cids'][$cid] = true;
                } elseif ($res === 'ok') {
                    $miss_by_ip[$ip]['has_success'] = true;
                }
            }

            // Anomaly check 4: delete spike — many tokens deleted in a
            // short window. Could indicate mass-disconnect (legit, e.g. a
            // bulk-cleanup script) or compromise-and-cleanup (concerning).
            if ($evt === 'delete') {
                $bucket = (int) floor($t / $DELETE_SPIKE_BUCKET_SECONDS);
                if (!isset($delete_buckets[$bucket])) {
                    $delete_buckets[$bucket] = ['count' => 0, 'sample' => $r];
                }
                $delete_buckets[$bucket]['count']++;
            }
        }
        fclose($fh);
    }

    // Promote burst buckets over threshold into anomalies
    foreach ($burst_buckets as $bucket => $info) {
        if ($info['count'] > $BURST_THRESHOLD) {
            $summary['anomalies'][] = $info['sample'] + [
                'why' => 'Read burst: ' . $info['count'] . ' reads from same client in a 5-minute window (excluding state.php polling)',
            ];
        }
    }

    // Promote IPs probing MANY DISTINCT client_ids — true enumeration shape.
    // See tunables block above for the "has_success" compensating filter
    // and its trade-off rationale.
    foreach ($miss_by_ip as $ip => $info) {
        if ($info['has_success']) continue;
        $distinct = count($info['cids']);
        if ($distinct >= $ENUM_DISTINCT_CIDS_PER_IP) {
            $summary['anomalies'][] = $info['sample'] + [
                'why' => 'Possible enumeration: ' . $info['count'] . ' missing-token reads from IP ' . $ip . ' across ' . $distinct . ' distinct client_ids in 24h (no successful reads from this IP in the window)',
            ];
        }
    }

    // Promote delete spikes — many tokens deleted within a 1-hour bucket
    foreach ($delete_buckets as $bucket => $info) {
        if ($info['count'] > $DELETE_SPIKE_THRESHOLD) {
            $summary['anomalies'][] = $info['sample'] + [
                'why' => 'Delete spike: ' . $info['count'] . ' token deletions within a 1-hour window — possible mass-disconnect or compromise-and-cleanup',
            ];
        }
    }

    return $summary;
}
