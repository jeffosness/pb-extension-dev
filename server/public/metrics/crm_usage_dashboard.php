<?php
// server/public/metrics/crm_usage_dashboard.php
//
// Dashboard that visualizes:
// - ../api/core/crm_usage_stats.php    (CRM usage events)
// - ../api/core/sse_usage_stats.php    (SSE dial session metrics)
// - ../api/core/daily_agent_stats.php  (call productivity from daily_stats)
//
// Uses core/bootstrap.php for shared hardening/CORS/OPTIONS behavior.
// IMPORTANT: This page is HTML, not JSON, so we opt out of JSON headers.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/../api/core/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

api_log('crm_usage_dashboard.view', [
  'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 120) : null,
]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>CRM Usage Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

  <style>
    :root {
      --bg: #f5f5f7;
      --card: #fff;
      --border: #e0e0e0;
      --text: #1a1a1a;
      --text-muted: #666;
      --accent: #0066cc;
      --accent-dark: #0052a3;
      --green: #16a34a;
      --orange: #ea580c;
      --purple: #7c3aed;
      --red: #dc2626;
    }
    * { box-sizing: border-box; }
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: var(--bg);
      margin: 0;
      padding: 0;
      color: var(--text);
    }
    .container {
      max-width: 1200px;
      margin: 32px auto;
      background: var(--card);
      padding: 24px 28px 32px;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    /* Header */
    h1 { margin-top: 0; margin-bottom: 4px; font-size: 28px; font-weight: 800; }
    .subtitle { margin-top: 0; color: var(--text-muted); font-size: 14px; }

    /* Controls bar */
    .controls-bar {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin: 16px 0;
      padding: 12px 16px;
      background: #f8f9fa;
      border-radius: 8px;
      border: 1px solid var(--border);
    }
    .controls-bar .separator {
      width: 1px;
      height: 24px;
      background: #d0d0d0;
      margin: 0 4px;
    }
    .filter-btn {
      padding: 6px 14px;
      border-radius: 6px;
      border: 2px solid var(--border);
      background: var(--card);
      cursor: pointer;
      font-weight: 600;
      font-size: 12px;
      transition: all 0.15s ease;
    }
    .filter-btn:hover { background: #f0f0f0; border-color: #c0c0c0; }
    .filter-btn.active {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent-dark);
    }
    .filter-btn.active:hover { background: var(--accent-dark); }
    .custom-range {
      display: none;
      align-items: center;
      gap: 6px;
      font-size: 12px;
    }
    .custom-range.visible { display: flex; }
    .custom-range input[type="date"] {
      padding: 4px 8px;
      border: 1px solid var(--border);
      border-radius: 4px;
      font-size: 12px;
    }
    .custom-range button {
      padding: 4px 12px;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
      font-weight: 600;
    }
    .auto-refresh {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 12px;
      color: var(--text-muted);
      margin-left: auto;
    }
    .auto-refresh select {
      padding: 2px 4px;
      border: 1px solid var(--border);
      border-radius: 4px;
      font-size: 11px;
    }
    .refresh-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--green);
      animation: pulse 2s infinite;
      display: none;
    }
    .refresh-dot.active { display: inline-block; }
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.3; }
    }
    .last-refreshed {
      font-size: 11px;
      color: #999;
    }
    .export-btn {
      padding: 5px 12px;
      background: #f0f0f0;
      border: 1px solid var(--border);
      border-radius: 4px;
      cursor: pointer;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-muted);
    }
    .export-btn:hover { background: #e0e0e0; }

    /* Alerts */
    .alert {
      padding: 12px 16px;
      border-radius: 6px;
      margin: 16px 0;
      font-size: 13px;
    }
    .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

    /* Section titles */
    .section-title {
      margin-top: 32px;
      margin-bottom: 14px;
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
      border-bottom: 2px solid #e8e8e8;
      padding-bottom: 8px;
    }

    /* Stat grids */
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
    .grid-5 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr; gap: 12px; }

    /* Stat cards */
    .stat {
      background: linear-gradient(135deg, #fafafa 0%, #ffffff 100%);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 14px 16px;
      font-size: 14px;
      transition: all 0.15s ease;
    }
    .stat:hover {
      border-color: var(--accent);
      box-shadow: 0 3px 10px rgba(0, 102, 204, 0.08);
      transform: translateY(-1px);
    }
    .stat .label {
      color: var(--text-muted);
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .stat .value {
      font-size: 24px;
      font-weight: 700;
      margin-top: 6px;
      color: var(--text);
    }

    /* Executive summary (large) cards */
    .stat-lg {
      background: var(--card);
      border: 1px solid var(--border);
      border-left: 4px solid var(--accent);
      border-radius: 8px;
      padding: 18px 20px;
      transition: all 0.15s ease;
    }
    .stat-lg:hover {
      box-shadow: 0 4px 12px rgba(0,0,0,0.06);
      transform: translateY(-1px);
    }
    .stat-lg .label {
      color: var(--text-muted);
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .stat-lg .value {
      font-size: 32px;
      font-weight: 800;
      margin-top: 6px;
      color: var(--text);
    }
    .stat-lg.green  { border-left-color: var(--green); }
    .stat-lg.orange { border-left-color: var(--orange); }
    .stat-lg.purple { border-left-color: var(--purple); }
    .stat-lg.red    { border-left-color: var(--red); }

    /* Charts */
    .chart-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin: 16px 0;
    }
    .chart-box {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 16px;
    }
    .chart-box h3 {
      margin: 0 0 12px;
      font-size: 14px;
      font-weight: 600;
      color: var(--text-muted);
    }
    .chart-container {
      position: relative;
      height: 260px;
    }

    /* Tables */
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      margin: 12px 0 20px;
      font-size: 13px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 6px;
      overflow: hidden;
    }
    th {
      background: linear-gradient(180deg, #f8f9fa 0%, #eef0f2 100%);
      font-weight: 600;
      padding: 10px 14px;
      text-align: left;
      border-bottom: 2px solid #dee2e6;
      color: #495057;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }
    td { padding: 8px 14px; border-bottom: 1px solid #f0f0f0; }
    tr:last-child td { border-bottom: none; }
    tbody tr:nth-child(even) td { background: #fafbfc; }
    tr:hover td { background: #f0f3f5; }
    tbody tr:nth-child(even):hover td { background: #e8ecef; }
    td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
    td.pct { text-align: right; color: var(--text-muted); font-size: 12px; }

    /* Collapsible sections */
    details { margin-top: 24px; }
    details summary.section-title {
      cursor: pointer;
      user-select: none;
      list-style: none;
    }
    details summary.section-title::before {
      content: "\25B6";
      display: inline-block;
      margin-right: 8px;
      font-size: 12px;
      transition: transform 0.2s;
    }
    details[open] summary.section-title::before {
      transform: rotate(90deg);
    }

    .muted { color: #999; font-size: 12px; }
    .empty-msg { color: #999; font-size: 13px; font-style: italic; padding: 12px 0; }
    code { background: #f1f1f1; padding: 2px 5px; border-radius: 3px; font-size: 90%; }

    /* Responsive */
    @media (max-width: 900px) {
      .grid-5 { grid-template-columns: 1fr 1fr 1fr; }
      .grid-4 { grid-template-columns: 1fr 1fr; }
      .chart-row { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
      .grid, .grid-3, .grid-4, .grid-5 { grid-template-columns: 1fr; }
      .stat-lg .value { font-size: 24px; }
      .controls-bar { flex-direction: column; align-items: stretch; }
      .auto-refresh { margin-left: 0; }
    }

    /* ---------------------------------------------------------------- */
    /* Tab navigation                                                   */
    /* ---------------------------------------------------------------- */
    .tab-nav {
      display: flex;
      gap: 2px;
      margin: 36px 0 0;
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
      position: sticky;
      top: 0;
      background: var(--bg, #ffffff);
      z-index: 10;
      padding: 8px 0 0;
    }
    .tab-btn {
      padding: 10px 18px;
      background: transparent;
      border: none;
      border-bottom: 2px solid transparent;
      font-size: 14px;
      font-weight: 600;
      color: var(--text-muted);
      cursor: pointer;
      transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
      margin-bottom: -1px;
      border-radius: 6px 6px 0 0;
      font-family: inherit;
    }
    .tab-btn:hover {
      color: var(--text);
      background: rgba(0, 0, 0, 0.025);
    }
    .tab-btn.active {
      color: var(--accent);
      border-bottom-color: var(--accent);
      background: rgba(0, 102, 204, 0.04);
    }
    .tab-pane {
      display: none;
    }
    .tab-pane.active {
      display: block;
      animation: tabFadeIn 0.15s ease;
    }
    @keyframes tabFadeIn {
      from { opacity: 0; transform: translateY(2px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    /* Mobile: tabs scroll horizontally rather than wrap */
    @media (max-width: 720px) {
      .tab-nav {
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
        margin: 24px -16px 0;
        padding: 8px 16px 0;
      }
      .tab-btn {
        white-space: nowrap;
        flex-shrink: 0;
      }
    }
    /* Section title spacing inside tabs — first one should hug the tab nav */
    .tab-pane > .section-title:first-child {
      margin-top: 20px;
    }
  </style>
</head>
<body>
<div class="container">
  <h1>CRM Usage Dashboard</h1>
  <p class="subtitle">Extension usage, dial session activity, and call productivity metrics</p>

  <div id="message"></div>

  <div id="content" style="display:none;">

    <!-- Controls Bar -->
    <div class="controls-bar">
      <button class="filter-btn active" data-range="today">Today</button>
      <button class="filter-btn" data-range="7days">Last 7 Days</button>
      <button class="filter-btn" data-range="week">This Week</button>
      <button class="filter-btn" data-range="month">This Month</button>
      <button class="filter-btn" data-range="custom">Custom</button>

      <div class="custom-range" id="custom-range">
        <input type="date" id="range-start">
        <span>to</span>
        <input type="date" id="range-end">
        <button onclick="applyCustomRange()">Apply</button>
      </div>

      <div class="separator"></div>

      <button class="export-btn" onclick="exportCsv()">Export CSV</button>

      <div class="auto-refresh">
        <span class="refresh-dot" id="refresh-dot"></span>
        <label><input type="checkbox" id="auto-refresh-toggle"> Auto</label>
        <select id="auto-refresh-interval">
          <option value="30000">30s</option>
          <option value="60000" selected>60s</option>
          <option value="300000">5m</option>
        </select>
        <span class="last-refreshed" id="last-refreshed"></span>
      </div>
    </div>

    <!-- Section 1: Executive Summary -->
    <div class="grid-5" id="exec-grid"></div>

    <!-- Section 1b: Token Security (server-rendered from token-audit.log) -->
    <?php
    // Read the audit log and compute summary + anomalies for the last 24h.
    // This is server-rendered (no JS) because the audit log is small and
    // we want it visible immediately on page load.
    require_once __DIR__ . '/../utils.php';

    $token_summary = [
        'reads'    => 0,
        'writes'   => 0,
        'deletes'  => 0,
        'errors'   => 0,
        'by_prov'  => ['pb' => 0, 'hubspot' => 0, 'close' => 0, 'apollo' => 0],
        'anomalies'=> [],
        'log_missing' => false,
    ];

    // Per-provider whitelist of script names that are EXPECTED to read tokens.
    // Anything outside this list gets flagged as an anomaly so we can spot a
    // suspicious endpoint reading tokens we didn't intend to give it access.
    $token_read_whitelist = [
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

    $audit_path = audit_token_log_path();
    if (!is_file($audit_path)) {
        $token_summary['log_missing'] = true;
    } else {
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

                $evt = $r['evt'] ?? '';
                $prov = $r['prov'] ?? '';
                $ep = $r['ep'] ?? '';
                $res = $r['res'] ?? '';
                $cid = $r['cid'] ?? '';
                $ip = $r['ip'] ?? '';

                if ($evt === 'read')   $token_summary['reads']++;
                if ($evt === 'write')  $token_summary['writes']++;
                if ($evt === 'delete') $token_summary['deletes']++;
                if ($res === 'error')  $token_summary['errors']++;

                if (isset($token_summary['by_prov'][$prov])) {
                    $token_summary['by_prov'][$prov]++;
                }

                // Anomaly check 1: endpoint not in whitelist for this provider
                if ($evt === 'read' && isset($token_read_whitelist[$prov])
                    && !in_array($ep, $token_read_whitelist[$prov], true)) {
                    $token_summary['anomalies'][] = $r + ['why' => 'Endpoint "' . htmlspecialchars($ep) . '" is not in the whitelist for ' . htmlspecialchars($prov) . ' token reads'];
                }

                // Anomaly check 2: burst — many reads from same client in 5 min.
                // Skip endpoints that are expected to be polled (e.g. state.php).
                if ($evt === 'read' && $cid !== ''
                    && !in_array($ep, $BURST_EXCLUDE_ENDPOINTS, true)) {
                    $bucket = $cid . '|' . floor($t / 300);
                    if (!isset($burst_buckets[$bucket])) $burst_buckets[$bucket] = ['count' => 0, 'sample' => $r];
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
                            'count' => 0,
                            'sample' => $r,
                            'cids' => [],
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
                $token_summary['anomalies'][] = $info['sample'] + [
                    'why' => 'Read burst: ' . $info['count'] . ' reads from same client in a 5-minute window (excluding state.php polling)',
                ];
            }
        }

        // Promote IPs probing MANY DISTINCT client_ids — true enumeration shape.
        // One client missing many times is normal (user without that CRM connected);
        // one IP missing many DIFFERENT client_ids is the actual scan signature.
        //
        // Compensating filter: if this IP ALSO produced any successful reads
        // in the window, it's a corporate NAT (or shared egress) with a mix
        // of connected and unconnected users behind it — not enumeration.
        // Real spray-attackers hit universally missing. See the tunables
        // block above for the trade-off rationale.
        foreach ($miss_by_ip as $ip => $info) {
            if ($info['has_success']) continue;
            $distinct = count($info['cids']);
            if ($distinct >= $ENUM_DISTINCT_CIDS_PER_IP) {
                $token_summary['anomalies'][] = $info['sample'] + [
                    'why' => 'Possible enumeration: ' . $info['count'] . ' missing-token reads from IP ' . htmlspecialchars($ip) . ' across ' . $distinct . ' distinct client_ids in 24h (no successful reads from this IP in the window)',
                ];
            }
        }

        // Promote delete spikes — many tokens deleted within a 1-hour bucket
        foreach ($delete_buckets as $bucket => $info) {
            if ($info['count'] > $DELETE_SPIKE_THRESHOLD) {
                $token_summary['anomalies'][] = $info['sample'] + [
                    'why' => 'Delete spike: ' . $info['count'] . ' token deletions within a 1-hour window — possible mass-disconnect or compromise-and-cleanup',
                ];
            }
        }
    }
    ?>
    <h2 class="section-title">Token Security (last 24h)</h2>
    <?php if ($token_summary['log_missing']): ?>
      <div class="alert alert-warning">
        Token audit log not found at <code><?= htmlspecialchars(audit_token_log_path()) ?></code>.
        This is expected if no token operations have happened since the audit log feature was deployed.
        The log will be created automatically on the next token read/write/delete.
      </div>
    <?php else: ?>
      <div class="grid-5">
        <div class="stat">
          <div class="label">Reads</div>
          <div class="value"><?= number_format($token_summary['reads']) ?></div>
        </div>
        <div class="stat">
          <div class="label">Writes</div>
          <div class="value"><?= number_format($token_summary['writes']) ?></div>
        </div>
        <div class="stat">
          <div class="label">Deletes</div>
          <div class="value"><?= number_format($token_summary['deletes']) ?></div>
        </div>
        <div class="stat">
          <div class="label">Read errors</div>
          <div class="value" style="color: <?= $token_summary['errors'] > 0 ? '#92400e' : 'inherit' ?>;">
            <?= number_format($token_summary['errors']) ?>
          </div>
        </div>
        <div class="stat">
          <div class="label">Anomalies</div>
          <div class="value" style="color: <?= count($token_summary['anomalies']) > 0 ? '#991b1b' : '#16a34a' ?>;">
            <?= count($token_summary['anomalies']) ?>
          </div>
        </div>
      </div>
      <div class="grid-4" style="margin-top: 12px;">
        <?php foreach (['pb', 'hubspot', 'close', 'apollo'] as $prov): ?>
          <div class="stat">
            <div class="label"><?= strtoupper($prov) ?> events</div>
            <div class="value"><?= number_format($token_summary['by_prov'][$prov]) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($token_summary['anomalies'])): ?>
        <details open style="margin-top: 16px;">
          <summary class="section-title" style="margin-top: 0; cursor: pointer;">
            🚨 Anomalies (<?= count($token_summary['anomalies']) ?>)
          </summary>
          <table style="width:100%; margin-top: 10px; border-collapse: collapse; font-size: 13px;">
            <thead>
              <tr style="background: #fef2f2;">
                <th style="text-align:left; padding: 8px; border-bottom: 1px solid #fecaca;">When</th>
                <th style="text-align:left; padding: 8px; border-bottom: 1px solid #fecaca;">Event</th>
                <th style="text-align:left; padding: 8px; border-bottom: 1px solid #fecaca;">Provider</th>
                <th style="text-align:left; padding: 8px; border-bottom: 1px solid #fecaca;">Endpoint</th>
                <th style="text-align:left; padding: 8px; border-bottom: 1px solid #fecaca;">IP</th>
                <th style="text-align:left; padding: 8px; border-bottom: 1px solid #fecaca;">client_id (hash)</th>
                <th style="text-align:left; padding: 8px; border-bottom: 1px solid #fecaca;">Why flagged</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($token_summary['anomalies'], 0, 50) as $a): ?>
                <tr>
                  <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;"><?= htmlspecialchars($a['t'] ?? '') ?></td>
                  <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;"><?= htmlspecialchars($a['evt'] ?? '') ?></td>
                  <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;"><?= htmlspecialchars($a['prov'] ?? '') ?></td>
                  <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;"><code><?= htmlspecialchars($a['ep'] ?? '') ?></code></td>
                  <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;"><?= htmlspecialchars($a['ip'] ?? '') ?></td>
                  <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;"><code><?= htmlspecialchars($a['cid'] ?? '') ?></code></td>
                  <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6;"><?= htmlspecialchars($a['why'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($token_summary['anomalies']) > 50): ?>
            <p style="font-size:12px; color: var(--text-muted); margin-top: 8px;">
              Showing 50 of <?= count($token_summary['anomalies']) ?> anomalies. Check the audit log directly for the full list:
              <code><?= htmlspecialchars(audit_token_log_path()) ?></code>
            </p>
          <?php endif; ?>
        </details>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <nav class="tab-nav" role="tablist" aria-label="Dashboard sections">
      <button type="button" class="tab-btn active" data-tab="trends"       role="tab" aria-selected="true"  aria-controls="tab-trends">📈 Trends</button>
      <button type="button" class="tab-btn"        data-tab="users"        role="tab" aria-selected="false" aria-controls="tab-users">👥 Users</button>
      <button type="button" class="tab-btn"        data-tab="productivity" role="tab" aria-selected="false" aria-controls="tab-productivity">📞 Productivity</button>
      <button type="button" class="tab-btn"        data-tab="sessions"     role="tab" aria-selected="false" aria-controls="tab-sessions">⚡ Sessions</button>
      <button type="button" class="tab-btn"        data-tab="ctc"          role="tab" aria-selected="false" aria-controls="tab-ctc">🔥 Click-to-Call</button>
      <button type="button" class="tab-btn"        data-tab="detail"       role="tab" aria-selected="false" aria-controls="tab-detail">🔎 Detail</button>
    </nav>

    <!-- Tab: Trends (Usage Trends + CRM Distribution) -->
    <div class="tab-pane active" data-tab="trends" id="tab-trends" role="tabpanel" aria-labelledby="tab-trends-btn">
      <!-- Section 2: Usage Trends -->
      <h2 class="section-title">Usage Trends</h2>
      <div id="trends-section">
        <div class="chart-row">
          <div class="chart-box">
            <h3>Sessions & Users per Day</h3>
            <div class="chart-container"><canvas id="chart-sessions"></canvas></div>
          </div>
          <div class="chart-box">
            <h3>Calls & Connections per Day</h3>
            <div class="chart-container"><canvas id="chart-calls"></canvas></div>
          </div>
        </div>
      </div>

      <!-- Section 3: CRM Distribution -->
      <h2 class="section-title">CRM Distribution</h2>
      <div class="chart-row">
        <div class="chart-box">
          <h3>Events by CRM</h3>
          <div class="chart-container"><canvas id="chart-crm-dist"></canvas></div>
        </div>
        <div>
          <table id="crm-combined-table">
            <thead><tr><th>CRM</th><th>Sessions</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab: Users (Activity by User) -->
    <div class="tab-pane" data-tab="users" id="tab-users" role="tabpanel" hidden>
      <!-- Section 3.5: Activity by User -->
      <h2 class="section-title">Activity by User</h2>
      <div class="alert alert-info" style="margin: 0 0 12px;">
        <strong>Privacy note:</strong> Only the PhoneBurner <code>member_user_id</code> is shown here.
        Names and email addresses are intentionally omitted &mdash; if you need to identify a specific
        user, look the ID up in the PhoneBurner admin. We may surface richer profile data in the
        future once we&rsquo;ve formalized access controls for it.
      </div>
      <div style="display:flex; gap:8px; align-items:center; margin: 8px 0 12px;">
        <input type="text" id="user-search"
               placeholder="Filter by member_user_id&hellip;"
               style="flex:1; max-width:300px; padding:6px 10px; border:1px solid var(--border); border-radius:6px; font-size:13px;">
        <span class="muted" id="user-search-count"></span>
      </div>
      <table id="by-user-table">
        <thead>
          <tr>
            <th>Member User ID</th>
            <th style="text-align:right;">Launches</th>
            <th>CRMs Used</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <!-- Tab: Productivity (Dial Session Productivity + Records per Session) -->
    <div class="tab-pane" data-tab="productivity" id="tab-productivity" role="tabpanel" hidden>
      <!-- Section 4: Dial Session Productivity -->
      <h2 class="section-title">Dial Session Productivity</h2>
      <div class="grid-3" id="productivity-grid"></div>
      <table id="call-outcomes-table">
        <thead><tr><th>Call Outcome</th><th>Count</th><th>%</th></tr></thead>
        <tbody></tbody>
      </table>

      <!-- Section 5: Records per Session -->
      <h2 class="section-title">Records per Session</h2>
      <div class="grid" id="records-grid"></div>
      <div class="chart-box" style="margin-top: 12px;">
        <h3>Records per Launch Distribution</h3>
        <div class="chart-container" style="height: 200px;"><canvas id="chart-histogram"></canvas></div>
      </div>
    </div>

    <!-- Tab: Sessions (SSE Session Details + Launch Sources & Object Types) -->
    <div class="tab-pane" data-tab="sessions" id="tab-sessions" role="tabpanel" hidden>
      <!-- Section 6: SSE Session Details -->
      <h2 class="section-title">SSE Session Details</h2>
      <div class="grid" id="sse-grid"></div>

      <!-- Section 7: Launch Source & Object Type -->
      <h2 class="section-title">Launch Sources & Object Types</h2>
      <div class="grid" id="launch-grid"></div>
      <div class="chart-row" style="margin-top: 12px;">
        <div>
          <table id="by-launch-source-table">
            <thead><tr><th>Launch Source</th><th>Events</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div>
          <table id="by-object-type-table">
            <thead><tr><th>Object Type</th><th>Events</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab: Click-to-Call (single-call pill usage, distinct from dial sessions) -->
    <div class="tab-pane" data-tab="ctc" id="tab-ctc" role="tabpanel" hidden>
      <h2 class="section-title">Click-to-Call Activity</h2>
      <div class="alert alert-info" style="margin: 0 0 12px;">
        Click-to-Call is the single-call pill embedded on CRM record pages and list rows.
        The daily chart overlays two series:
        <strong>Calls initiated</strong> (extension-side <code>event_type=click_to_call</code>, fired when the pill is clicked)
        vs <strong>Calls dispositioned</strong> (server-side <code>event_type=click_to_call_done</code>, fired by the
        PhoneBurner <code>softphone_call_done</code> webhook after the user dispositions the call).
        Any gap between the two lines is calls where the softphone popup was abandoned mid-flow —
        watching this over time surfaces how often users skip disposition.
      </div>

      <div class="grid-4" id="ctc-summary-grid"></div>

      <div class="chart-row" style="margin-top: 12px;">
        <div class="chart-box">
          <h3>Calls per Day</h3>
          <div class="chart-container"><canvas id="chart-ctc-daily"></canvas></div>
        </div>
        <div class="chart-box">
          <h3>By CRM</h3>
          <div class="chart-container"><canvas id="chart-ctc-by-crm"></canvas></div>
        </div>
      </div>

      <div class="chart-row" style="margin-top: 12px;">
        <div>
          <h3 style="font-size:14px; color:#666; margin-bottom:8px;">By Object Type</h3>
          <table id="ctc-by-object-table">
            <thead><tr><th>Object Type</th><th>Calls</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div>
          <h3 style="font-size:14px; color:#666; margin-bottom:8px;">By Page Type</h3>
          <table id="ctc-by-page-table">
            <thead><tr><th>Page</th><th>Calls</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <h2 class="section-title">Activity by User</h2>
      <div class="alert alert-info" style="margin: 0 0 12px;">
        Same privacy model as the Users tab — <code>member_user_id</code> only,
        no names or emails. Look up specific agents in PhoneBurner admin.
      </div>
      <table id="ctc-by-user-table">
        <thead>
          <tr>
            <th>Member User ID</th>
            <th style="text-align:right;">Initiated</th>
            <th style="text-align:right;">Dispositioned</th>
            <th style="text-align:right;">Rate</th>
            <th>CRMs used</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
      <p style="font-size:12px; color:#666; margin-top:8px;">
        Dispositioned counts key on <code>payload.custom_data.pb_user_id</code> from the
        <code>softphone_call_done</code> webhook (PhoneBurner echoes our custom_data back
        on every call webhook). Same <code>member_user_id</code> the extension uses for
        initiated entries — so this table shows one row per agent with both sides side by
        side. If dispositioned stays at 0 while initiated is non-zero, either the agent
        didn't disposition their calls (real signal) or PB stopped populating custom_data
        (regression — check the softphone_call_done log line's <code>pb_user_id</code>
        field).
      </p>
    </div>

    <!-- Tab: Detail (Detailed Breakdown by Hostname & Level) -->
    <div class="tab-pane" data-tab="detail" id="tab-detail" role="tabpanel" hidden>
      <!-- Section 8: Detail Breakdown -->
      <h2 class="section-title">Detailed Breakdown</h2>
      <div class="chart-row">
        <div>
          <h3 style="font-size:14px; color:#666; margin-bottom:8px;">By Hostname</h3>
          <table id="by-host-table">
            <thead><tr><th>Host</th><th>Events</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div>
          <h3 style="font-size:14px; color:#666; margin-bottom:8px;">By Level</h3>
          <table id="by-level-table">
            <thead><tr><th>Level</th><th>Events</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>

    <p class="muted" style="margin-top:24px;">
      Data: <code>crm_usage_stats.php</code> &middot; <code>sse_usage_stats.php</code> &middot; <code>daily_agent_stats.php</code>
    </p>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const msgEl        = document.getElementById("message");
  const contentEl    = document.getElementById("content");

  const crmEndpoint   = "../api/core/crm_usage_stats.php";
  const sseEndpoint   = "../api/core/sse_usage_stats.php";
  const agentEndpoint = "../api/core/daily_agent_stats.php";

  const hasChartJs = typeof Chart !== "undefined";

  // Chart instances (destroy before re-render)
  const charts = {};

  let currentRange = "today";
  let customStart  = "";
  let customEnd    = "";
  let autoRefreshTimer = null;

  // Last loaded data (for CSV export)
  let lastSse = null, lastCrm = null, lastAgent = null, lastCtc = null, lastCtcDone = null;

  // Cached "by user" rows for live filter without re-fetching
  let lastByUserRows = [];

  // --- CRM color map ---
  const CRM_COLORS = {
    hubspot:    "#ff7a59",
    close:      "#1a3e72",
    apollo:     "#7c3aed",
    pipedrive:  "#24292e",
    salesforce: "#00a1e0",
    zoho:       "#c8202f",
    unknown:    "#999",
  };
  function crmColor(crmId) {
    return CRM_COLORS[crmId] || CRM_COLORS.unknown;
  }

  // ========================================================================
  // Date range helpers
  // ========================================================================
  function getDateRange(range) {
    const today = new Date();
    const todayStr = fmtDate(today);

    if (range === "today") return { start: todayStr, end: todayStr, label: "Today" };

    if (range === "7days") {
      const d = new Date(today);
      d.setDate(today.getDate() - 6);
      return { start: fmtDate(d), end: todayStr, label: "Last 7 Days" };
    }

    if (range === "week") {
      const d = new Date(today);
      d.setDate(today.getDate() - today.getDay());
      return { start: fmtDate(d), end: todayStr, label: "This Week" };
    }

    if (range === "month") {
      const d = new Date(today.getFullYear(), today.getMonth(), 1);
      return { start: fmtDate(d), end: todayStr, label: "This Month" };
    }

    if (range === "custom" && customStart && customEnd) {
      return { start: customStart, end: customEnd, label: "Custom" };
    }

    return { start: todayStr, end: todayStr, label: "Today" };
  }

  function fmtDate(d) {
    return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
  }

  // Count days in a range
  function dayCount(start, end) {
    const s = new Date(start + "T00:00:00");
    const e = new Date(end + "T00:00:00");
    return Math.max(1, Math.round((e - s) / 86400000) + 1);
  }

  // ========================================================================
  // Load dashboard
  // ========================================================================
  function loadDashboard() {
    const dr  = getDateRange(currentRange);
    const t   = Date.now();
    const sseUrl       = sseEndpoint   + "?start=" + dr.start + "&end=" + dr.end + "&t=" + t;
    const crmUrl       = crmEndpoint   + "?start=" + dr.start + "&end=" + dr.end + "&t=" + t;
    const ctcUrl       = crmEndpoint   + "?start=" + dr.start + "&end=" + dr.end + "&event_type=click_to_call&t=" + t;
    const ctcDoneUrl   = crmEndpoint   + "?start=" + dr.start + "&end=" + dr.end + "&event_type=click_to_call_done&t=" + t;
    const agentUrl     = agentEndpoint + "?start=" + dr.start + "&end=" + dr.end + "&t=" + t;

    Promise.all([
      fetch(sseUrl).then(r => { if (!r.ok) throw new Error("SSE HTTP " + r.status); return r.json(); }),
      fetch(crmUrl).then(r => { if (!r.ok) throw new Error("CRM HTTP " + r.status); return r.json(); }),
      fetch(ctcUrl).then(r => r.ok ? r.json() : null).catch(() => null),
      fetch(ctcDoneUrl).then(r => r.ok ? r.json() : null).catch(() => null),
      fetch(agentUrl).then(r => r.ok ? r.json() : null).catch(() => null),
    ]).then(([sseResp, crmResp, ctcResp, ctcDoneResp, agentResp]) => {
      const sse     = normalize(sseResp);
      const crm     = normalize(crmResp);
      const ctc     = ctcResp     ? normalize(ctcResp)     : null;
      const ctcDone = ctcDoneResp ? normalize(ctcDoneResp) : null;
      const agent   = agentResp   ? normalize(agentResp)   : null;

      if (!sseResp?.ok || !sse) { showError("SSE stats API error."); return; }
      if (!crmResp?.ok || !crm) { showError("CRM stats API error."); return; }

      lastSse = sse; lastCrm = crm; lastAgent = agent; lastCtc = ctc; lastCtcDone = ctcDone;

      msgEl.innerHTML = "";
      contentEl.style.display = "block";

      const days = dayCount(dr.start, dr.end);
      const isMultiDay = days > 1;

      // --- Compute SSE totals from per_day ---
      const ssePerDay = sse.per_day || [];
      let totalConnects = 0, totalDisconnects = 0, totalMaxConcurrent = 0;
      let totalDurSum = 0, totalDurCount = 0;

      ssePerDay.forEach(d => {
        totalConnects    += d.connects || 0;
        totalDisconnects += d.disconnects || 0;
        if ((d.max_concurrent_est || 0) > totalMaxConcurrent) totalMaxConcurrent = d.max_concurrent_est;
        if (d.avg_duration_sec > 0 && d.disconnects > 0) {
          totalDurSum   += d.avg_duration_sec * d.disconnects;
          totalDurCount += d.disconnects;
        }
      });

      const totalAvgDur = totalDurCount > 0 ? Math.round(totalDurSum / totalDurCount) : 0;
      const p95s = ssePerDay.filter(d => d.p95_duration_sec > 0).map(d => d.p95_duration_sec);
      const totalP95 = sse.overall_p95 || (p95s.length > 0 ? Math.max(...p95s) : 0);
      const activeNow = sse.active_now?.active_now || 0;
      const uniqueUsers = sse.totals?.unique_users || 0;
      const abandoned = Math.max(0, totalConnects - totalDisconnects);

      // --- Agent stats ---
      const totalCalls    = agent?.total_calls || 0;
      const totalConn     = agent?.total_connected || 0;
      const totalAppt     = agent?.total_appointments || 0;
      const connectRate   = agent?.connect_rate || 0;
      const uniqueAgents  = agent?.unique_agents || 0;

      // ====================================================================
      // Section 1: Executive Summary
      // ====================================================================
      const execGrid = document.getElementById("exec-grid");
      execGrid.innerHTML = "";
      execGrid.appendChild(statCardLg("Dial Sessions", String(totalConnects), ""));
      execGrid.appendChild(statCardLg("Unique Users", String(uniqueUsers), "green"));
      execGrid.appendChild(statCardLg("Total Calls", String(totalCalls), "orange"));
      execGrid.appendChild(statCardLg("Connect Rate", totalCalls > 0 ? connectRate.toFixed(1) + "%" : "N/A", "purple"));
      const avgRecs = crm.selected_count?.avg || 0;
      execGrid.appendChild(statCardLg("Avg Records/Session", avgRecs > 0 ? avgRecs.toFixed(1) : "N/A", "red"));

      // ====================================================================
      // Section 2: Usage Trends (charts)
      // ====================================================================
      const trendsSection = document.getElementById("trends-section");
      if (isMultiDay && hasChartJs) {
        trendsSection.style.display = "block";

        // Chart A: Sessions + Users per day
        const sessLabels = ssePerDay.map(d => shortDate(d.date));
        const sessData   = ssePerDay.map(d => d.connects || 0);
        const usersData  = ssePerDay.map(d => d.unique_users || 0);
        renderLineChart("chart-sessions", sessLabels,
          [{ label: "Sessions", data: sessData, color: "#0066cc" },
           { label: "Unique Users", data: usersData, color: "#ea580c" }]);

        // Chart B: Calls + Connections per day
        const agentPerDay = agent?.per_day || [];
        if (agentPerDay.length > 0) {
          const callLabels = agentPerDay.map(d => shortDate(d.date));
          const callData   = agentPerDay.map(d => d.total_calls || 0);
          const connData   = agentPerDay.map(d => d.connected || 0);
          renderLineChart("chart-calls", callLabels,
            [{ label: "Calls", data: callData, color: "#16a34a" },
             { label: "Connected", data: connData, color: "#7c3aed" }]);
        } else {
          destroyChart("chart-calls");
          const ctx = document.getElementById("chart-calls");
          if (ctx) ctx.parentElement.innerHTML = '<p class="empty-msg">No call data for this period</p>';
        }
      } else {
        trendsSection.style.display = isMultiDay ? "block" : "none";
        if (!isMultiDay) {
          destroyChart("chart-sessions");
          destroyChart("chart-calls");
        }
      }

      // ====================================================================
      // Section 3: CRM Distribution
      // ====================================================================
      const byCrmId = crm.by_crm_id || {};
      if (hasChartJs && Object.keys(byCrmId).length > 0) {
        const crmLabels = Object.keys(byCrmId).sort((a, b) => byCrmId[b] - byCrmId[a]);
        const crmValues = crmLabels.map(k => byCrmId[k]);
        const crmColors = crmLabels.map(k => crmColor(k));
        renderDoughnutChart("chart-crm-dist", crmLabels, crmValues, crmColors);
      } else {
        destroyChart("chart-crm-dist");
      }

      // CRM table (dial sessions per CRM from usage tracking)
      const crmTableBody = document.querySelector("#crm-combined-table tbody");
      crmTableBody.innerHTML = "";
      if (Object.keys(byCrmId).length > 0) {
        Object.entries(byCrmId).sort((a, b) => b[1] - a[1]).forEach(([crm, count]) => {
          const tr = document.createElement("tr");
          tr.innerHTML = '<td>' + esc(crm) + '</td><td class="num">' + count + '</td>';
          crmTableBody.appendChild(tr);
        });
      } else {
        crmTableBody.innerHTML = '<tr><td colspan="2" class="empty-msg">No data</td></tr>';
      }

      // ====================================================================
      // Section 3.5: Activity by User (member_user_id only — see privacy note)
      // ====================================================================
      const byUser = crm.by_user || {};
      lastByUserRows = Object.entries(byUser)
        .map(([uid, info]) => ({
          uid,
          total: info.total || 0,
          byCrm: info.by_crm || {},
        }))
        .sort((a, b) => b.total - a.total);
      renderByUser(document.getElementById("user-search").value || "");

      // ====================================================================
      // Click-to-Call tab (populated from a filtered fetch of crm_usage_stats)
      // ====================================================================
      renderCtc(ctc, ctcDone);

      // ====================================================================
      // Section 4: Productivity
      // ====================================================================
      const prodGrid = document.getElementById("productivity-grid");
      prodGrid.innerHTML = "";
      const callsPerSession = totalConnects > 0 && totalCalls > 0
        ? (totalCalls / totalConnects).toFixed(1) : "N/A";
      prodGrid.appendChild(statCard("Total Calls", String(totalCalls)));
      prodGrid.appendChild(statCard("Connected", String(totalConn)));
      prodGrid.appendChild(statCard("Connect Rate", totalCalls > 0 ? connectRate.toFixed(1) + "%" : "N/A"));
      prodGrid.appendChild(statCard("Appointments", String(totalAppt)));
      prodGrid.appendChild(statCard("Active Agents", String(uniqueAgents)));
      prodGrid.appendChild(statCard("Calls / Session", callsPerSession));

      // Call outcomes table
      const byStatus = agent?.by_status || {};
      const outcomeBody = document.querySelector("#call-outcomes-table tbody");
      outcomeBody.innerHTML = "";
      const statusTotal = Object.values(byStatus).reduce((a, b) => a + b, 0);
      if (statusTotal > 0) {
        Object.entries(byStatus).sort((a, b) => b[1] - a[1]).forEach(([status, count]) => {
          const pct = ((count / statusTotal) * 100).toFixed(1);
          const tr = document.createElement("tr");
          tr.innerHTML = '<td>' + esc(status) + '</td><td class="num">' + count + '</td><td class="pct">' + pct + '%</td>';
          outcomeBody.appendChild(tr);
        });
      } else {
        outcomeBody.innerHTML = '<tr><td colspan="3" class="empty-msg">No call outcome data for this period</td></tr>';
      }

      // ====================================================================
      // Section 5: Records per Session
      // ====================================================================
      const recGrid = document.getElementById("records-grid");
      recGrid.innerHTML = "";
      const sc = crm.selected_count || {};
      recGrid.appendChild(statCard("Avg Records / Launch", sc.avg > 0 ? sc.avg.toFixed(1) : "N/A"));
      recGrid.appendChild(statCard("Total Records Loaded", String(sc.total || 0)));

      // Histogram
      const buckets = sc.buckets || {};
      const orderedBucketKeys = ["1-5","6-10","11-25","26-50","51-100","101-250","251-500","500+"];
      if (hasChartJs && Object.keys(buckets).length > 0) {
        const hLabels = orderedBucketKeys.filter(k => buckets[k]);
        const hValues = hLabels.map(k => buckets[k] || 0);
        renderBarChart("chart-histogram", hLabels, hValues);
      } else {
        destroyChart("chart-histogram");
      }

      // ====================================================================
      // Section 6: SSE Session Details
      // ====================================================================
      const sseGrid = document.getElementById("sse-grid");
      sseGrid.innerHTML = "";
      sseGrid.appendChild(statCard("Active Now (SSE)", String(activeNow)));
      sseGrid.appendChild(statCard("Max Concurrent", String(totalMaxConcurrent)));
      sseGrid.appendChild(statCard("Sessions Started", String(totalConnects)));
      const endedCard = statCard("Sessions Ended", String(totalDisconnects));
      endedCard.title = "Stop button or 60-min inactivity timeout";
      sseGrid.appendChild(endedCard);
      const abCard = statCard("Abandoned", String(abandoned));
      abCard.title = "User closed browser without stopping session";
      sseGrid.appendChild(abCard);
      sseGrid.appendChild(statCard("Avg Duration", secondsToFriendly(totalAvgDur)));
      const p95Card = statCard("P95 Duration", secondsToFriendly(totalP95));
      p95Card.title = "95% of sessions lasted this long or less";
      sseGrid.appendChild(p95Card);
      sseGrid.appendChild(statCard("Unique Users", String(uniqueUsers)));

      // ====================================================================
      // Section 7: Launch Source & Object Type
      // ====================================================================
      const launchGrid = document.getElementById("launch-grid");
      launchGrid.innerHTML = "";
      const src = crm.by_launch_source || {};
      const totalLaunches = Object.values(src).reduce((a, b) => a + b, 0);
      launchGrid.appendChild(statCard("Total Launches", String(totalLaunches)));
      let dominant = "None";
      if (totalLaunches > 0) {
        dominant = Object.entries(src).sort((a, b) => b[1] - a[1])[0][0];
        dominant = friendlyLaunchSource(dominant);
      }
      launchGrid.appendChild(statCard("Most Common Method", dominant));

      fillTableFriendly("by-launch-source-table", crm.by_launch_source, friendlyLaunchSource);
      fillTableFriendly("by-object-type-table", crm.by_object_type, friendlyObjectType);

      // ====================================================================
      // Section 8: Hostname & Level (collapsed)
      // ====================================================================
      fillTable("by-host-table", crm.by_host);
      fillTable("by-level-table", crm.by_level);

      // Update last-refreshed timestamp
      document.getElementById("last-refreshed").textContent = "Updated " + new Date().toLocaleTimeString();

    }).catch(err => {
      console.error("Dashboard load error:", err);
      showError(err.message || String(err));
    });
  }

  // ========================================================================
  // UI Helpers
  // ========================================================================
  function showError(text) {
    msgEl.innerHTML = '<div class="alert alert-danger">Could not load dashboard data.<br>' + esc(text) + '</div>';
    contentEl.style.display = "none";
  }

  function normalize(resp) {
    return (resp && resp.data) ? resp.data : resp;
  }

  function esc(s) {
    const d = document.createElement("div");
    d.textContent = s;
    return d.innerHTML;
  }

  function statCard(label, value) {
    const div = document.createElement("div");
    div.className = "stat";
    div.innerHTML = '<div class="label">' + esc(label) + '</div><div class="value">' + esc(value) + '</div>';
    return div;
  }

  function statCardLg(label, value, colorClass) {
    const div = document.createElement("div");
    div.className = "stat-lg" + (colorClass ? " " + colorClass : "");
    div.innerHTML = '<div class="label">' + esc(label) + '</div><div class="value">' + esc(value) + '</div>';
    return div;
  }

  function secondsToFriendly(sec) {
    sec = Number(sec || 0);
    if (!sec) return "0s";
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = Math.floor(sec % 60);
    if (h > 0) return h + "h " + m + "m";
    if (m > 0) return m + "m " + s + "s";
    return s + "s";
  }

  function shortDate(dateStr) {
    // "2026-04-19" -> "Apr 19"
    const d = new Date(dateStr + "T12:00:00");
    return d.toLocaleDateString("en-US", { month: "short", day: "numeric" });
  }

  function fillTable(tableId, obj) {
    const tbody = document.querySelector("#" + tableId + " tbody");
    tbody.innerHTML = "";
    if (!obj || Object.keys(obj).length === 0) {
      tbody.innerHTML = '<tr><td colspan="2" class="empty-msg">No data</td></tr>';
      return;
    }
    Object.entries(obj).sort((a, b) => b[1] - a[1]).forEach(([key, count]) => {
      const tr = document.createElement("tr");
      tr.innerHTML = '<td>' + esc(key) + '</td><td class="num">' + count + '</td>';
      tbody.appendChild(tr);
    });
  }

  function renderByUser(filterText) {
    const tbody = document.querySelector("#by-user-table tbody");
    const countEl = document.getElementById("user-search-count");
    tbody.innerHTML = "";

    const q = (filterText || "").trim().toLowerCase();
    const filtered = q
      ? lastByUserRows.filter(r => r.uid.toLowerCase().indexOf(q) !== -1)
      : lastByUserRows;

    if (lastByUserRows.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" class="empty-msg">No identified users for this period (entries without a saved PAT are excluded)</td></tr>';
      countEl.textContent = "";
      return;
    }
    if (filtered.length === 0) {
      tbody.innerHTML = '<tr><td colspan="3" class="empty-msg">No users match &ldquo;' + esc(filterText) + '&rdquo;</td></tr>';
      countEl.textContent = "0 of " + lastByUserRows.length;
      return;
    }

    filtered.forEach(row => {
      const crmList = Object.entries(row.byCrm)
        .sort((a, b) => b[1] - a[1])
        .map(([crm, n]) => esc(crm) + " (" + n + ")")
        .join(", ");
      const tr = document.createElement("tr");
      tr.innerHTML =
        '<td><code>' + esc(row.uid) + '</code></td>' +
        '<td class="num">' + row.total + '</td>' +
        '<td>' + crmList + '</td>';
      tbody.appendChild(tr);
    });

    countEl.textContent = q
      ? filtered.length + " of " + lastByUserRows.length
      : lastByUserRows.length + " user" + (lastByUserRows.length === 1 ? "" : "s");
  }

  // Render the Click-to-Call tab from a filtered crm_usage_stats response
  // (event_type=click_to_call). ctc may be null if the API errored — the
  // tab renders zeroed-out stats in that case so it always looks intentional.
  function renderCtc(ctc, ctcDone) {
    const summaryGrid = document.getElementById("ctc-summary-grid");
    summaryGrid.innerHTML = "";

    const total = ctc?.total_events || 0;
    const uniqueUsers = ctc?.unique_users || 0;
    const perDay = ctc?.per_day || [];
    const daysActive = perDay.filter(d => (d.total_events || 0) > 0).length;

    // Disposition side (softphone_call_done webhook events).
    const totalDone = ctcDone?.total_events || 0;
    const perDayDone = ctcDone?.per_day || [];

    // Disposition rate: what % of clicked calls ended with a disposition
    // fired back through the softphone_call_done webhook. Anything under
    // 100% is calls where the user clicked the pill but never dispositioned
    // (closed the popup, walked away, etc.). Over 100% happens if a call
    // dispositions after the click-to-call log line has already rotated to
    // the next day — real but rare.
    const dispositionRate = (total > 0)
      ? Math.min(100, Math.round((totalDone / total) * 100))
      : 0;
    const rateLabel = (total > 0) ? (dispositionRate + "%") : "N/A";

    summaryGrid.appendChild(statCard("Calls initiated", String(total)));
    summaryGrid.appendChild(statCard("Calls dispositioned", String(totalDone)));
    summaryGrid.appendChild(statCard("Disposition rate", rateLabel));
    summaryGrid.appendChild(statCard("Unique users", String(uniqueUsers)));

    // Time-series chart — overlay initiated vs dispositioned so any gap is
    // immediately visible. If the two lines track together, drift is low.
    // If "Dispositioned" runs materially below "Initiated" day over day,
    // that's calls where the softphone popup was abandoned mid-flow.
    if (hasChartJs) {
      // Build a merged date axis that includes both series' dates.
      const dateSet = new Set();
      perDay.forEach(d => dateSet.add(d.date));
      perDayDone.forEach(d => dateSet.add(d.date));
      const allDates = Array.from(dateSet).sort();

      const initByDate = new Map(perDay.map(d => [d.date, d.total_events || 0]));
      const doneByDate = new Map(perDayDone.map(d => [d.date, d.total_events || 0]));

      const dailyLabels = allDates.map(shortDate);
      const dailyInitiated = allDates.map(d => initByDate.get(d) || 0);
      const dailyDispositioned = allDates.map(d => doneByDate.get(d) || 0);

      renderLineChart("chart-ctc-daily", dailyLabels, [
        { label: "Calls initiated (pill clicks)", data: dailyInitiated, color: "#ff7a59" },
        { label: "Calls dispositioned (softphone webhook)", data: dailyDispositioned, color: "#3e6ff0" },
      ]);

      // Per-CRM doughnut (only meaningful once multiple CRMs are wired up,
      // but works fine with one slice too).
      const byCrm = ctc?.by_crm_id || {};
      const crmLabels = Object.keys(byCrm);
      const crmValues = crmLabels.map(k => byCrm[k]);
      const crmColors = crmLabels.map(k => crmColor(k));
      if (crmLabels.length === 0) {
        // Destroy any prior chart so the box shows empty, not stale data
        destroyChart("chart-ctc-by-crm");
      } else {
        renderDoughnutChart("chart-ctc-by-crm", crmLabels, crmValues, crmColors);
      }
    }

    // Object-type table (contacts / companies / deals)
    fillTableFriendly("ctc-by-object-table", ctc?.by_object_type || {}, friendlyObjectType);

    // Page-type table (record vs list). launch_source is the discriminator:
    // "record" = pill on a single-record page, "list" = pill in a list-view row.
    const byPage = {};
    const bySource = ctc?.by_launch_source || {};
    if (bySource.record) byPage["Record page"] = bySource.record;
    if (bySource.list) byPage["List view"] = bySource.list;
    fillTableFriendly("ctc-by-page-table", byPage, k => k);

    // Per-user activity table. Merges initiated (extension-side clicks) with
    // dispositioned (softphone webhook events) keyed on member_user_id.
    // The dispositioned side only populates if PhoneBurner includes
    // payload.agent.user_id in the softphone_call_done webhook — see the
    // caveat below the table.
    const ctcUserTbody = document.querySelector("#ctc-by-user-table tbody");
    ctcUserTbody.innerHTML = "";

    const ctcByUser     = ctc?.by_user     || {};
    const ctcDoneByUser = ctcDone?.by_user || {};

    // Union of user IDs across both sides.
    const userIds = new Set([
      ...Object.keys(ctcByUser),
      ...Object.keys(ctcDoneByUser),
    ]);

    const ctcRows = Array.from(userIds).map(uid => {
      const init = ctcByUser[uid]     || { total: 0, by_crm: {} };
      const done = ctcDoneByUser[uid] || { total: 0 };
      const initiated     = init.total || 0;
      const dispositioned = done.total || 0;
      const rate = (initiated > 0)
        ? Math.min(100, Math.round((dispositioned / initiated) * 100)) + "%"
        : "N/A";
      return {
        uid,
        initiated,
        dispositioned,
        rate,
        byCrm: init.by_crm || {},
      };
    }).sort((a, b) => b.initiated - a.initiated);

    if (ctcRows.length === 0) {
      ctcUserTbody.innerHTML =
        '<tr><td colspan="5" class="empty-msg">No click-to-call activity yet in this date range.</td></tr>';
      return;
    }

    ctcRows.forEach(row => {
      const crmList = Object.entries(row.byCrm)
        .sort((a, b) => b[1] - a[1])
        .map(([crm, n]) => esc(crm) + " (" + n + ")")
        .join(", ");
      const tr = document.createElement("tr");
      tr.innerHTML =
        '<td><code>' + esc(row.uid) + '</code></td>' +
        '<td class="num">' + row.initiated + '</td>' +
        '<td class="num">' + row.dispositioned + '</td>' +
        '<td class="num">' + row.rate + '</td>' +
        '<td>' + crmList + '</td>';
      ctcUserTbody.appendChild(tr);
    });
  }

  function fillTableFriendly(tableId, obj, labelFn) {
    const tbody = document.querySelector("#" + tableId + " tbody");
    tbody.innerHTML = "";
    if (!obj || Object.keys(obj).length === 0) {
      tbody.innerHTML = '<tr><td colspan="2" class="empty-msg">No data</td></tr>';
      return;
    }
    Object.entries(obj).sort((a, b) => b[1] - a[1]).forEach(([key, count]) => {
      const tr = document.createElement("tr");
      tr.innerHTML = '<td>' + esc(labelFn ? labelFn(key) : key) + '</td><td class="num">' + count + '</td>';
      tbody.appendChild(tr);
    });
  }

  function friendlyLaunchSource(key) {
    const map = {
      "selection": "Selection (record list)",
      "list": "List (saved list)",
      "scan": "Scan (page scrape)",
      "record": "Record (single record)",
      "sequence-tasks": "Sequence Tasks",
    };
    return map[key] || key;
  }

  function friendlyObjectType(key) {
    const map = { "contacts": "Contacts", "companies": "Companies", "deals": "Deals" };
    return map[key] || key;
  }

  // ========================================================================
  // Chart rendering (guarded by hasChartJs)
  // ========================================================================
  function destroyChart(id) {
    if (charts[id]) { charts[id].destroy(); delete charts[id]; }
  }

  function renderLineChart(canvasId, labels, datasets) {
    if (!hasChartJs) return;
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const ds = datasets.map(d => ({
      label: d.label,
      data: d.data,
      borderColor: d.color,
      backgroundColor: d.color + "20",
      fill: true,
      tension: 0.3,
      pointRadius: labels.length <= 14 ? 4 : 2,
      pointHoverRadius: 6,
    }));

    charts[canvasId] = new Chart(canvas, {
      type: "line",
      data: { labels, datasets: ds },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        plugins: { legend: { position: "bottom", labels: { boxWidth: 12, padding: 16 } } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { grid: { display: false } },
        },
      },
    });
  }

  function renderDoughnutChart(canvasId, labels, values, colors) {
    if (!hasChartJs) return;
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    charts[canvasId] = new Chart(canvas, {
      type: "doughnut",
      data: {
        labels,
        datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: "#fff" }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "right", labels: { boxWidth: 12, padding: 10, font: { size: 12 } } },
        },
      },
    });
  }

  function renderBarChart(canvasId, labels, values) {
    if (!hasChartJs) return;
    destroyChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    charts[canvasId] = new Chart(canvas, {
      type: "bar",
      data: {
        labels,
        datasets: [{
          data: values,
          backgroundColor: "#0066cc40",
          borderColor: "#0066cc",
          borderWidth: 1,
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { grid: { display: false } },
        },
      },
    });
  }

  // ========================================================================
  // Custom date range
  // ========================================================================
  window.applyCustomRange = function() {
    const s = document.getElementById("range-start").value;
    const e = document.getElementById("range-end").value;
    if (!s || !e) return;
    if (s > e) { alert("Start date must be before end date"); return; }
    // Max 31 days
    const diff = dayCount(s, e);
    if (diff > 31) { alert("Maximum 31-day range"); return; }
    customStart = s;
    customEnd = e;
    currentRange = "custom";
    loadDashboard();
  };

  // ========================================================================
  // Auto-refresh
  // ========================================================================
  function startAutoRefresh() {
    stopAutoRefresh();
    const interval = parseInt(document.getElementById("auto-refresh-interval").value, 10);
    autoRefreshTimer = setInterval(loadDashboard, interval);
    document.getElementById("refresh-dot").classList.add("active");
    localStorage.setItem("dashboard_auto_refresh", JSON.stringify({ enabled: true, interval }));
  }

  function stopAutoRefresh() {
    if (autoRefreshTimer) { clearInterval(autoRefreshTimer); autoRefreshTimer = null; }
    document.getElementById("refresh-dot").classList.remove("active");
    localStorage.setItem("dashboard_auto_refresh", JSON.stringify({ enabled: false }));
  }

  // Restore auto-refresh preference
  try {
    const pref = JSON.parse(localStorage.getItem("dashboard_auto_refresh") || "{}");
    if (pref.enabled) {
      document.getElementById("auto-refresh-toggle").checked = true;
      if (pref.interval) document.getElementById("auto-refresh-interval").value = String(pref.interval);
    }
  } catch (e) {}

  document.getElementById("auto-refresh-toggle").addEventListener("change", function() {
    this.checked ? startAutoRefresh() : stopAutoRefresh();
  });
  document.getElementById("auto-refresh-interval").addEventListener("change", function() {
    if (document.getElementById("auto-refresh-toggle").checked) startAutoRefresh();
  });

  // ========================================================================
  // CSV Export
  // ========================================================================
  window.exportCsv = function() {
    if (!lastSse || !lastCrm) { alert("No data loaded yet"); return; }
    const dr = getDateRange(currentRange);
    let csv = "Metric,Value\n";
    csv += "Date Range," + dr.start + " to " + dr.end + "\n";
    csv += "Dial Sessions," + (lastSse.totals?.connects || 0) + "\n";
    csv += "Unique Users," + (lastSse.totals?.unique_users || 0) + "\n";

    if (lastAgent) {
      csv += "Total Calls," + (lastAgent.total_calls || 0) + "\n";
      csv += "Connected," + (lastAgent.total_connected || 0) + "\n";
      csv += "Appointments," + (lastAgent.total_appointments || 0) + "\n";
      csv += "Connect Rate," + (lastAgent.connect_rate || 0) + "%\n";
    }

    csv += "\nCRM,Sessions\n";
    Object.entries(lastCrm.by_crm_id || {}).sort((a, b) => b[1] - a[1]).forEach(([k, v]) => {
      csv += k + "," + v + "\n";
    });

    if (lastAgent?.by_status) {
      csv += "\nCall Outcome,Count\n";
      Object.entries(lastAgent.by_status).sort((a, b) => b[1] - a[1]).forEach(([k, v]) => {
        csv += '"' + k.replace(/"/g, '""') + '",' + v + "\n";
      });
    }

    // Per-day SSE
    const ssePerDay = lastSse.per_day || [];
    if (ssePerDay.length > 1) {
      csv += "\nDate,Sessions,Users,Avg Duration (s)\n";
      ssePerDay.forEach(d => {
        csv += d.date + "," + (d.connects || 0) + "," + (d.unique_users || 0) + "," + (d.avg_duration_sec || 0) + "\n";
      });
    }

    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "crm-dashboard-" + dr.start + ".csv";
    a.click();
    URL.revokeObjectURL(url);
  };

  // ========================================================================
  // Filter button listeners
  // ========================================================================
  document.querySelectorAll(".filter-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".filter-btn").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
      currentRange = btn.getAttribute("data-range");

      const customEl = document.getElementById("custom-range");
      if (currentRange === "custom") {
        customEl.classList.add("visible");
        // Don't load yet -- wait for Apply
        return;
      } else {
        customEl.classList.remove("visible");
      }
      loadDashboard();
    });
  });

  // Start auto-refresh if preference was saved
  if (document.getElementById("auto-refresh-toggle").checked) {
    startAutoRefresh();
  }

  // Live filter for the "Activity by User" table (no re-fetch)
  document.getElementById("user-search").addEventListener("input", function() {
    renderByUser(this.value);
  });

  // ========================================================================
  // Tab navigation
  // ------------------------------------------------------------------------
  // - URL hash (#users, #productivity, etc.) drives the active tab so links
  //   are shareable and refresh-friendly.
  // - Chart.js charts inside an initially-hidden tab compute width=0 on
  //   first render; we fire a resize event after activation so they pick up
  //   the correct dimensions.
  // ========================================================================
  const VALID_TABS = ["trends", "users", "productivity", "sessions", "ctc", "detail"];

  function activateTab(name, opts) {
    if (!VALID_TABS.includes(name)) name = "trends";
    opts = opts || {};

    document.querySelectorAll(".tab-btn").forEach(btn => {
      const isActive = btn.dataset.tab === name;
      btn.classList.toggle("active", isActive);
      btn.setAttribute("aria-selected", isActive ? "true" : "false");
    });
    document.querySelectorAll(".tab-pane").forEach(pane => {
      const isActive = pane.dataset.tab === name;
      pane.classList.toggle("active", isActive);
      if (isActive) {
        pane.removeAttribute("hidden");
      } else {
        pane.setAttribute("hidden", "");
      }
    });

    // Charts laid out while hidden need a nudge to recompute their canvas size.
    window.dispatchEvent(new Event("resize"));

    if (opts.updateHash !== false) {
      const newHash = "#" + name;
      if (window.location.hash !== newHash) {
        history.replaceState(null, "", newHash);
      }
    }
  }

  document.querySelectorAll(".tab-btn").forEach(btn => {
    btn.addEventListener("click", () => activateTab(btn.dataset.tab));
  });

  window.addEventListener("hashchange", () => {
    const fromHash = (window.location.hash || "").replace(/^#/, "");
    activateTab(fromHash, { updateHash: false });
  });

  // Honor an initial hash like /crm_usage_dashboard.php#users
  const initialTab = (window.location.hash || "").replace(/^#/, "") || "trends";
  activateTab(initialTab, { updateHash: false });

  // Initial load
  loadDashboard();
});
</script>
</body>
</html>
