<?php
// scripts/cron/transcript_retry.php
//
// Runs every 5 minutes from the repo-managed cron file (pb-extension.cron.tmpl),
// as www-data. PhoneBurner sends each transcript webhook once and never
// retries, so when a CRM write fails at delivery time the receivers queue the
// payload (transcript_retry_store) and this job re-applies it with backoff
// (5 min, 15 min, 1 h), then gives up with a transcript_retry.gave_up log line.
//
// Usage:
//   php transcript_retry.php           normal run (what cron does)
//
// Logic lives in utils.php (transcript_retry_run).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$publicDir = dirname(__DIR__, 2) . '/server/public';

define('PB_BOOTSTRAP_NO_JSON', true);
require_once $publicDir . '/api/core/bootstrap.php';
require_once $publicDir . '/utils.php';

// One runner at a time: a slow CRM could stretch a run past the next tick.
$lock = @fopen(sys_get_temp_dir() . '/pb-transcript-retry-' . md5($publicDir) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$stats = transcript_retry_run();
if ($stats['ok'] + $stats['failed'] + $stats['gave_up'] > 0) {
    api_log('transcript_retry.run', $stats);
}
