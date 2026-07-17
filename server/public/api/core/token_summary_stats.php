<?php
// server/public/api/core/token_summary_stats.php
//
// JSON endpoint that returns the same computation the dashboard's server-
// rendered "Token Security (last 24h)" block produces. Used by the
// dashboard's JS auto-refresh so the section updates without a full page
// reload.
//
// Same access model as sse_usage_stats.php / crm_usage_stats.php — the
// dashboard itself is admin-authed via metrics/.htaccess, and this
// endpoint is called only from that dashboard. No client_id required.
//
// See token_summary_lib.php for the actual computation.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/token_summary_lib.php';

$summary = compute_token_summary();

// Truncate anomalies to 50 to match the server-rendered view's cap. The
// UI can note "showing 50 of N" separately via anomalies_total.
$total = count($summary['anomalies']);
if ($total > 50) {
    $summary['anomalies'] = array_slice($summary['anomalies'], 0, 50);
}
$summary['anomalies_total'] = $total;

api_ok($summary);
