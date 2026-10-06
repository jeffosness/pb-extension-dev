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

/** Post one message to the configured Slack incoming webhook. Returns true on success. */
function sh_post_slack(array $cfg, string $text): bool {
    $url = (string)($cfg['SLACK_ALERT_WEBHOOK_URL'] ?? '');
    if ($url === '') {
        api_log('server_health.slack.not_configured', []);
        return false;
    }
    // Only ever send to Slack — a typo'd or tampered config value must not
    // turn this into a request to an arbitrary host.
    if (strpos($url, SH_SLACK_URL_PREFIX) !== 0) {
        api_log('server_health.slack.bad_url', []); // never log the URL itself: it is a secret
        return false;
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
        return false;
    }
    return true;
}

if ($testMsg) {
    $ok = sh_post_slack($cfg, ':test_tube: *[' . strtoupper($env) . '] Test alert* — server health alerts are wired up.'
        . "\n<{$dashboardUrl}|Open the dashboard>");
    fwrite(STDOUT, $ok ? "Sent.\n" : "Failed — see api.log (server_health.slack.*).\n");
    exit($ok ? 0 : 1);
}

$sample = sh_collect_sample($cfg, $env);

if ($dryRun) {
    fwrite(STDOUT, json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

$dir = sh_health_dir($cfg);
if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    api_log('server_health.mkdir_failed', ['dir' => $dir]);
    exit(1);
}

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

$sendAlerts = ($env === 'prod');
foreach ($notes as $n) {
    api_log('server_health.alert', [
        'rule' => $n['key'], 'kind' => $n['kind'], 'level' => $n['level'], 'value' => $n['value'], 'sent' => $sendAlerts,
    ]);
    if ($sendAlerts && !sh_post_slack($cfg, sh_format_notification($n, $env, $dashboardUrl))) {
        // Delivery failed: clear last_notified so the next run retries instead
        // of waiting 6h. (Resolved notices have no entry to retry; they're logged above.)
        if (isset($alerts[$n['key']])) $alerts[$n['key']]['last_notified'] = 0;
    }
}

// 3) Persist state (latest sample for the dashboard tiles + ring + alerts)
try {
    atomic_write_json($dir . '/state.json', [
        'updated_at' => $sample['ts'],
        'latest'     => $sample,
        'ring'       => $ring,
        'alerts'     => $alerts,
    ]);
} catch (\Throwable $e) {
    api_log('server_health.state_write_failed', ['exception' => get_class($e)]);
}

// 4) Retention — once an hour is plenty
if ((int)gmdate('i', $sample['ts']) === 7) {
    sh_prune_old_files($dir, $sample['ts']);
}
