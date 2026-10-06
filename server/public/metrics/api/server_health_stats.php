<?php
// server/public/metrics/api/server_health_stats.php
//
// Feeds the dashboard's "Server Health" section from the samples written by
// scripts/cron/collect_server_health.php (see metrics/server_health_lib.php).
//
//   ?view=latest              newest sample + active alerts + thresholds (cheap: reads state.json)
//   ?view=history&range=24h   samples for the trend chart (range: 24h | 7d)
//
// Dashboard-only: Apache Basic Auth on /metrics/ plus metrics_require_auth().

require_once __DIR__ . '/../../api/core/bootstrap.php';
require_once __DIR__ . '/../metrics_auth.php';
metrics_require_auth();
require_once __DIR__ . '/../../utils.php';
require_once __DIR__ . '/../server_health_lib.php';

rate_limit_or_fail('dashboard:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 60);

$dir  = sh_health_dir(cfg());
$view = ($_GET['view'] ?? 'latest') === 'history' ? 'history' : 'latest';

if ($view === 'latest') {
    $state  = sh_read_state($dir);
    $latest = is_array($state['latest'] ?? null) ? $state['latest'] : null;
    $age    = $latest ? time() - (int)($latest['ts'] ?? 0) : null;

    api_ok([
        'latest'     => $latest,
        'age_sec'    => $age,
        'stale'      => $latest === null || $age > SH_STALE_SEC,
        'stale_after_sec' => SH_STALE_SEC,
        'alerts'     => is_array($state['alerts'] ?? null) ? $state['alerts'] : [],
        'rules'      => sh_alert_rules(),
    ]);
}

// History: 24h = every sample (~1440 points); 7d = every 10th (~1000 points).
$range  = ($_GET['range'] ?? '24h') === '7d' ? '7d' : '24h';
$since  = time() - ($range === '7d' ? 7 * 86400 : 86400);
$stride = $range === '7d' ? 10 : 1;

$points = [];
foreach (sh_read_samples($dir, $since, $stride) as $s) {
    $f = sh_flatten_for_rules($s);
    $points[] = [
        't'       => $f['ts'],
        'cpu'     => $f['load5_pct'],
        'mem'     => $f['mem_used_pct'],
        'workers' => $f['workers_busy_pct'],
        'disk'    => $f['disk_used_pct'],
        'sse'     => $f['sse_live'],
    ];
}

api_ok(['range' => $range, 'points' => $points]);
