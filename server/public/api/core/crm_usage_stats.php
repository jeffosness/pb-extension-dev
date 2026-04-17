<?php
// server/public/api/core/crm_usage_stats.php
//
// Reads CRM usage logs and returns aggregated counts (wrapped via api_ok()).
// Prefers daily files (crm_usage-YYYY-MM-DD.log) written by track_crm_usage.php.
// Falls back to legacy monolithic crm_usage.log for historical data.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

// Date filtering (same pattern as sse_usage_stats.php)
function safe_date_ymd(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
    return $s;
}

function date_range_ymd(string $start, string $end): array {
    $out = [];
    $startTs = strtotime($start . ' 00:00:00');
    $endTs   = strtotime($end   . ' 00:00:00');
    if ($startTs === false || $endTs === false) return $out;
    if ($startTs > $endTs) return $out;

    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $out[] = date('Y-m-d', $ts);
    }
    return $out;
}

/**
 * Read all entries from a JSONL log file and aggregate into accumulators.
 * If $filterByDate is true, entries are filtered by timestamp against $startTs/$endTs.
 */
function aggregate_log_file(
    string $logFile,
    array &$byCrm,
    array &$byHost,
    array &$byLevel,
    array &$byObjectType,
    array &$byLaunchSource,
    int &$total,
    bool $filterByDate = false,
    int $startTs = 0,
    int $endTs = 0
): void {
    $fh = @fopen($logFile, 'r');
    if ($fh === false) return;

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;

        $entry = json_decode($line, true);
        if (!is_array($entry)) continue;

        // Filter by date range when reading legacy monolithic file
        if ($filterByDate) {
            $logTs = isset($entry['ts']) ? strtotime($entry['ts']) : 0;
            if ($logTs < $startTs || $logTs > $endTs) {
                continue;
            }
        }

        $total++;

        $crm   = $entry['crm_id'] ?? 'unknown';
        $host  = $entry['host']   ?? 'unknown';
        $level = (string)($entry['level'] ?? 'unknown');

        $byCrm[$crm]     = ($byCrm[$crm]     ?? 0) + 1;
        $byHost[$host]   = ($byHost[$host]   ?? 0) + 1;
        $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;

        $objectType   = $entry['object_type']   ?? '';
        $launchSource = $entry['launch_source'] ?? '';

        if ($objectType !== '') {
            $byObjectType[$objectType] = ($byObjectType[$objectType] ?? 0) + 1;
        }
        if ($launchSource !== '') {
            $byLaunchSource[$launchSource] = ($byLaunchSource[$launchSource] ?? 0) + 1;
        }
    }

    fclose($fh);
}

$start = safe_date_ymd($_GET['start'] ?? null);
$end = safe_date_ymd($_GET['end'] ?? null);

$today = date('Y-m-d');
if (!$start) $start = $today;
if (!$end) $end = $today;

$dates = date_range_ymd($start, $end);
if (count($dates) === 0) {
    api_error('Invalid date range', 'bad_request', 400);
}
if (count($dates) > 31) {
    api_error('Date range too large (max 31 days)', 'bad_request', 400);
}

$publicDir  = dirname(__DIR__, 2); // core -> api -> public
$metricsDir = $publicDir . '/metrics';
$legacyFile = $metricsDir . '/crm_usage.log';

$byCrm         = [];
$byHost        = [];
$byLevel       = [];
$byObjectType  = [];
$byLaunchSource = [];
$total         = 0;

// Track which dates had a daily file (to know if we need legacy fallback)
$datesWithDailyFile = [];

// Read daily files first (preferred, no date filtering needed)
foreach ($dates as $d) {
    $dailyFile = safe_file_path($metricsDir, 'crm_usage-' . $d . '.log');
    if ($dailyFile && is_file($dailyFile)) {
        $datesWithDailyFile[$d] = true;
        aggregate_log_file($dailyFile, $byCrm, $byHost, $byLevel, $byObjectType, $byLaunchSource, $total);
    }
}

// Fall back to legacy monolithic file for dates without daily files
$datesNeedingLegacy = array_filter($dates, function ($d) use ($datesWithDailyFile) {
    return !isset($datesWithDailyFile[$d]);
});

if (count($datesNeedingLegacy) > 0 && file_exists($legacyFile)) {
    // Compute timestamp range for only the missing dates
    $legacyStart = strtotime(min($datesNeedingLegacy) . ' 00:00:00');
    $legacyEnd   = strtotime(max($datesNeedingLegacy) . ' 23:59:59');

    aggregate_log_file(
        $legacyFile, $byCrm, $byHost, $byLevel, $byObjectType, $byLaunchSource, $total,
        true, $legacyStart, $legacyEnd
    );
}

if ($total === 0 && count($datesWithDailyFile) === 0 && !file_exists($legacyFile)) {
    api_ok([
        'total_events'     => 0,
        'by_crm_id'        => (object)[],
        'by_host'          => (object)[],
        'by_level'         => (object)[],
        'by_object_type'   => (object)[],
        'by_launch_source' => (object)[],
        'note'             => 'No CRM usage log files found',
    ]);
}

api_log('crm_usage_stats.ok', [
    'total_events' => $total,
    'daily_files'  => count($datesWithDailyFile),
    'legacy_dates' => count($datesNeedingLegacy),
]);

api_ok([
    'total_events'     => $total,
    'by_crm_id'        => $byCrm,
    'by_host'          => $byHost,
    'by_level'         => $byLevel,
    'by_object_type'   => $byObjectType,
    'by_launch_source' => $byLaunchSource,
]);
