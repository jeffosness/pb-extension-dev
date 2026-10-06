<?php
// scripts/cron/collect_server_health.php
//
// Runs every minute from the repo-managed cron file (scripts/cron/pb-extension.cron.tmpl,
// installed into /etc/cron.d/ by scripts/deploy-on-server.sh). Runs as www-data.
//
//   1. Collect one server health sample (CPU, memory, disk, Apache workers, live sessions).
//   2. Append it to {log dir}/health/health-YYYY-MM-DD.jsonl (30-day retention).
//   3. Evaluate alert rules and post changes to Slack (prod only — both envs share
//      one VPS, so dev alerting would just duplicate every message).
//
// Usage:
//   php collect_server_health.php               normal run (what cron does)
//   php collect_server_health.php --dry-run     print the sample, write nothing, send nothing
//   php collect_server_health.php --test-alert  send one test message to Slack (any env)
//
// Logic lives in server/public/metrics/server_health_lib.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repoDir   = dirname(__DIR__, 2);
$publicDir = $repoDir . '/server/public';

// bootstrap gives us api_log() (CLI-safe) and the PHP error handlers.
define('PB_BOOTSTRAP_NO_JSON', true);
require_once $publicDir . '/api/core/bootstrap.php';
require_once $publicDir . '/utils.php';
require_once $publicDir . '/metrics/server_health_lib.php';

$args    = array_slice($argv, 1);
$dryRun  = in_array('--dry-run', $args, true);
$testMsg = in_array('--test-alert', $args, true);

$cfg = cfg();
$versionInfo = @include $publicDir . '/version.php';
$env = is_array($versionInfo) ? (string)($versionInfo['env'] ?? 'unknown') : 'unknown';

$baseUrl      = rtrim((string)($cfg['BASE_URL'] ?? ''), '/');
$dashboardUrl = $baseUrl . '/metrics/crm_usage_dashboard.php#server-health';

/**
 * Post one message to the configured Slack incoming webhook.
 * Returns 'sent', 'failed' (worth retrying), or 'unconfigured' (don't retry:
 * an empty or non-Slack URL won't fix itself, and retrying would just fill api.log).
 */
function sh_post_slack(array $cfg, string $text): string {
    $url = (string)($cfg['SLACK_ALERT_WEBHOOK_URL'] ?? '');
    if ($url === '') {
        return 'unconfigured';
    }
    // Only ever send to Slack — a typo'd or tampered config value must not
    // turn this into a request to an arbitrary host.
    if (strpos($url, SH_SLACK_URL_PREFIX) !== 0) {
        api_log('server_health.slack.bad_url', []); // never log the URL itself: it is a secret
        return 'unconfigured';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['text' => $text], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $status = (int)($info['http_code'] ?? 0);
    if ($body === false || $status < 200 || $status >= 300) {
        // Slack returns plain-text errors ("invalid_payload", "no_service").
        $info['raw_body'] = is_string($body) ? $body : '';
        api_log('server_health.slack.failed', describe_api_failure($info, null) + ['curl_error' => $err]);
        return 'failed';
    }
    return 'sent';
}

if ($testMsg) {
    $ok = 'sent' === sh_post_slack($cfg, ':test_tube: *[' . strtoupper($env) . '] Test alert* — server health alerts are wired up.'
        . "\n<{$dashboardUrl}|Open the dashboard>");
    fwrite(STDOUT, $ok ? "Sent.\n" : "Failed — see api.log (server_health.slack.*).\n");
    exit($ok ? 0 : 1);
}

if ($dryRun) {
    fwrite(STDOUT, json_encode(sh_collect_sample($cfg, $env), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "
");
    exit(0);
}

$dir = sh_health_dir($cfg);
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    api_log('server_health.mkdir_failed', ['dir' => $dir]);
    exit(1);
}

// One collector at a time. A slow run (Slack timeouts, a hung df) must not
// overlap the next minute's run: both would read the same state.json and
// send duplicate alerts. Held until exit. (Codex review, 2026-10-06.)
$lock = @fopen($dir . '/collector.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    api_log('server_health.skipped_locked', []);
    exit(0);
}

$sample = sh_collect_sample($cfg, $env);

// 1) Append sample
$line = json_encode($sample, JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents(sh_day_file($dir, $sample['ts']), $line, FILE_APPEND | LOCK_EX) === false) {
    api_log('server_health.append_failed', ['dir' => $dir]);
}

// 2) Alerts
$state = sh_read_state($dir);
$ring  = is_array($state['ring'] ?? null) ? $state['ring'] : [];
$ring[] = sh_flatten_for_rules($sample);
$ring  = array_slice($ring, -SH_RING_SIZE);

[$alerts, $notes] = sh_evaluate_alerts($ring, is_array($state['alerts'] ?? null) ? $state['alerts'] : [], $sample['ts']);

// Delivery. Failed posts go to an outbox with their exact text (so a failed
// "WARNING" is retried as that WARNING, and a failed "RESOLVED" isn't lost),
// retried every 5 min, oldest first, dropped after SH_OUTBOX_MAX_TRIES.
$sendAlerts = ($env === 'prod');
$outbox = is_array($state['outbox'] ?? null) ? $state['outbox'] : [];
foreach ($notes as $n) {
    api_log('server_health.alert', [
        'rule' => $n['key'], 'kind' => $n['kind'], 'level' => $n['level'], 'value' => $n['value'], 'sent' => $sendAlerts,
    ]);
    if ($sendAlerts) {
        $outbox[] = ['text' => sh_format_notification($n, $env, $dashboardUrl), 'tries' => 0, 'next_at' => 0];
    }
}
$remaining = [];
$slackDown = false;
foreach ($outbox as $msg) {
    if ($slackDown || ($msg['next_at'] ?? 0) > $sample['ts']) { $remaining[] = $msg; continue; }
    $res = sh_post_slack($cfg, (string)$msg['text']);
    if ($res === 'failed') {
        $slackDown = true; // don't burn 15s per message this run
        $msg['tries'] = (int)($msg['tries'] ?? 0) + 1;
        $msg['next_at'] = $sample['ts'] + 300;
        if ($msg['tries'] < SH_OUTBOX_MAX_TRIES) $remaining[] = $msg;
        else api_log('server_health.slack.dropped', ['tries' => $msg['tries']]);
    }
    // 'sent' and 'unconfigured' leave the outbox.
}
$outbox = array_slice($remaining, -20);

// 3) Persist state (latest sample for the dashboard tiles + ring + alerts)
try {
    atomic_write_json($dir . '/state.json', [
        'updated_at' => $sample['ts'],
        'latest'     => $sample,
        'ring'       => $ring,
        'alerts'     => $alerts,
        'outbox'     => $outbox,
    ]);
} catch (\Throwable $e) {
    api_log('server_health.state_write_failed', ['exception' => get_class($e)]);
}

// 4) Retention — once an hour is plenty
if ((int)gmdate('i', $sample['ts']) === 7) {
    sh_prune_old_files($dir, $sample['ts']);
}
