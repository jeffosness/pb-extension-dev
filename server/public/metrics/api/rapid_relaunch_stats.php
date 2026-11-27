<?php
// server/public/metrics/api/rapid_relaunch_stats.php
//
// "Rapid re-launches" for the metrics dashboard (GH #261): pairs of dial
// sessions from the same extension install created seconds apart, and whether
// the follow-me widget ended up on the session PhoneBurner wasn't using.
// See ../rapid_relaunch_lib.php for the data sources and the mismatch rule.
//
// Params (GET):
//   window_sec  max gap between launches to count as a pair (10-600, default 60)
//   days        how far back to look (1-7, default 7; session files only live 7 days)
//
// JSON endpoint behind /metrics/ Basic Auth. Returns hashes only, no PII.

require_once __DIR__ . '/../../api/core/bootstrap.php';
require_once __DIR__ . '/../metrics_auth.php';
metrics_require_auth();
require_once __DIR__ . '/../../utils.php';
require_once __DIR__ . '/../rapid_relaunch_lib.php';

rate_limit_or_fail('dashboard:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 60);

$windowSec = (int)($_GET['window_sec'] ?? RR_DEFAULT_WINDOW_SEC);
$windowSec = max(10, min(600, $windowSec));
$days = (int)($_GET['days'] ?? 7);
$days = max(1, min(7, $days));

$now     = time();
$sinceTs = $now - $days * 86400;

// Same directory the launch endpoints write to (session_file_path() in utils.php).
$sessionsDir = dirname(session_file_path('probe'));
$metricsDir  = dirname(__DIR__);

$sessions = rr_read_sessions($sessionsDir, $sinceTs);

// One extra day of SSE logs: a session created late on day N may first connect after midnight.
$dates = [];
for ($ts = $sinceTs; $ts <= $now + 86400; $ts += 86400) {
    $dates[] = date('Y-m-d', $ts);
}
$widgetSeen = rr_read_widget_sessions($metricsDir, array_values(array_unique($dates)));

$pairs = rr_find_pairs($sessions['rows'], $widgetSeen, $windowSec);

api_ok([
    'window_sec' => $windowSec,
    'days'       => $days,
    'summary'    => rr_summarize($pairs, count($sessions['rows']), rr_sessions_by_crm($sessions['rows'])),
    'scanned'    => $sessions['scanned'],
    'truncated'  => $sessions['truncated'],
    'pairs'      => array_slice($pairs, 0, 100),
]);
