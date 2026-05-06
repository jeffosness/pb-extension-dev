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
 * Assign a selected_count value to a histogram bucket label.
 */
function selected_count_bucket(int $count): string {
    if ($count <= 5)   return '1-5';
    if ($count <= 10)  return '6-10';
    if ($count <= 25)  return '11-25';
    if ($count <= 50)  return '26-50';
    if ($count <= 100) return '51-100';
    if ($count <= 250) return '101-250';
    if ($count <= 500) return '251-500';
    return '500+';
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
    array &$uniqueUsers,
    int &$selectedCountTotal,
    int &$selectedCountEntries,
    array &$selectedCountBuckets,
    array &$byUser,
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

        // Unique users by client_id_hash
        $clientHash = $entry['client_id_hash'] ?? '';
        if ($clientHash !== '') {
            $uniqueUsers[$clientHash] = true;
        }

        // Selected count aggregation
        $selCount = isset($entry['selected_count']) ? (int)$entry['selected_count'] : 0;
        if ($selCount > 0) {
            $selectedCountTotal += $selCount;
            $selectedCountEntries++;
            $bucket = selected_count_bucket($selCount);
            $selectedCountBuckets[$bucket] = ($selectedCountBuckets[$bucket] ?? 0) + 1;
        }

        // Per-user aggregation (only for entries with a known PhoneBurner identity).
        // We deliberately do NOT include name/email here — see crm_usage_dashboard.php
        // for the rationale. Use the ID to look up the agent on the PB side when needed.
        $memberUserId = isset($entry['member_user_id']) && $entry['member_user_id'] !== ''
            ? (string)$entry['member_user_id']
            : '';
        if ($memberUserId !== '') {
            if (!isset($byUser[$memberUserId])) {
                $byUser[$memberUserId] = [
                    'total'  => 0,
                    'by_crm' => [],
                ];
            }
            $byUser[$memberUserId]['total']++;

            // For generic / unknown CRM launches the crm_id alone ("generic") is
            // useless for spotting emerging integrations — substitute the hostname
            // so each unrecognized CRM shows as its own bucket. Known CRMs keep
            // their crm_id (hubspot, close, apollo, etc).
            $perUserCrmKey = $crm;
            if ($crm === 'generic' || $crm === 'unknown') {
                $hostKey = preg_replace('/^www\./i', '', (string)$host);
                if ($hostKey !== '' && $hostKey !== 'unknown') {
                    $perUserCrmKey = $hostKey;
                }
            }

            $byUser[$memberUserId]['by_crm'][$perUserCrmKey] =
                ($byUser[$memberUserId]['by_crm'][$perUserCrmKey] ?? 0) + 1;
        }
    }

    fclose($fh);
}

// -------------------------
// Rate limit (dashboard GET uses IP-based key)
// -------------------------
$data      = json_input();
$client_id = $data ? ($data['client_id'] ?? null) : null;
if (!$client_id) {
    $client_id = 'dashboard:' . $_SERVER['REMOTE_ADDR'];
}
rate_limit_or_fail($client_id, 60);

// -------------------------
// Params
// -------------------------
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

// Grand totals (across all days)
$byCrm              = [];
$byHost             = [];
$byLevel            = [];
$byObjectType       = [];
$byLaunchSource     = [];
$total              = 0;
$allUniqueUsers     = [];
$allSelCountTotal   = 0;
$allSelCountEntries = 0;
$allSelCountBuckets = [];
$byUser             = [];

// Per-day breakdown
$perDay = [];

// Track which dates had a daily file (to know if we need legacy fallback)
$datesWithDailyFile = [];

// Read daily files first (preferred, no date filtering needed)
foreach ($dates as $d) {
    $dailyFile = safe_file_path($metricsDir, 'crm_usage-' . $d . '.log');

    // Per-day accumulators
    $dayCrm = [];
    $dayHost = [];
    $dayLevel = [];
    $dayObjType = [];
    $dayLaunchSrc = [];
    $dayTotal = 0;
    $dayUsers = [];
    $daySelTotal = 0;
    $daySelEntries = 0;
    $daySelBuckets = [];
    $dayByUser = [];

    if ($dailyFile && is_file($dailyFile)) {
        $datesWithDailyFile[$d] = true;
        aggregate_log_file(
            $dailyFile,
            $dayCrm, $dayHost, $dayLevel, $dayObjType, $dayLaunchSrc, $dayTotal,
            $dayUsers, $daySelTotal, $daySelEntries, $daySelBuckets, $dayByUser
        );
    }

    // Merge per-day into grand totals
    $total += $dayTotal;
    foreach ($dayCrm as $k => $v) $byCrm[$k] = ($byCrm[$k] ?? 0) + $v;
    foreach ($dayHost as $k => $v) $byHost[$k] = ($byHost[$k] ?? 0) + $v;
    foreach ($dayLevel as $k => $v) $byLevel[$k] = ($byLevel[$k] ?? 0) + $v;
    foreach ($dayObjType as $k => $v) $byObjectType[$k] = ($byObjectType[$k] ?? 0) + $v;
    foreach ($dayLaunchSrc as $k => $v) $byLaunchSource[$k] = ($byLaunchSource[$k] ?? 0) + $v;
    foreach ($dayUsers as $k => $v) $allUniqueUsers[$k] = true;
    $allSelCountTotal += $daySelTotal;
    $allSelCountEntries += $daySelEntries;
    foreach ($daySelBuckets as $k => $v) $allSelCountBuckets[$k] = ($allSelCountBuckets[$k] ?? 0) + $v;

    foreach ($dayByUser as $uid => $info) {
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = ['total' => 0, 'by_crm' => []];
        }
        $byUser[$uid]['total'] += $info['total'];
        foreach ($info['by_crm'] as $crmKey => $crmCount) {
            $byUser[$uid]['by_crm'][$crmKey] =
                ($byUser[$uid]['by_crm'][$crmKey] ?? 0) + $crmCount;
        }
    }

    $perDay[] = [
        'date'               => $d,
        'total_events'       => $dayTotal,
        'by_crm_id'          => $dayCrm ?: (object)[],
        'by_launch_source'   => $dayLaunchSrc ?: (object)[],
        'unique_users'       => count($dayUsers),
        'avg_selected_count' => $daySelEntries > 0
            ? round($daySelTotal / $daySelEntries, 1) : 0,
    ];
}

// Fall back to legacy monolithic file for dates without daily files
$datesNeedingLegacy = array_filter($dates, function ($d) use ($datesWithDailyFile) {
    return !isset($datesWithDailyFile[$d]);
});

if (count($datesNeedingLegacy) > 0 && file_exists($legacyFile)) {
    // Legacy fallback aggregates into grand totals only (no per-day split possible)
    $legacyStart = strtotime(min($datesNeedingLegacy) . ' 00:00:00');
    $legacyEnd   = strtotime(max($datesNeedingLegacy) . ' 23:59:59');

    aggregate_log_file(
        $legacyFile,
        $byCrm, $byHost, $byLevel, $byObjectType, $byLaunchSource, $total,
        $allUniqueUsers, $allSelCountTotal, $allSelCountEntries, $allSelCountBuckets,
        $byUser,
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
        'unique_users'     => 0,
        'selected_count'   => ['total' => 0, 'entries' => 0, 'avg' => 0, 'buckets' => (object)[]],
        'per_day'          => $perDay,
        'by_user'          => (object)[],
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
    'unique_users'     => count($allUniqueUsers),
    'selected_count'   => [
        'total'   => $allSelCountTotal,
        'entries' => $allSelCountEntries,
        'avg'     => $allSelCountEntries > 0
            ? round($allSelCountTotal / $allSelCountEntries, 1) : 0,
        'buckets' => $allSelCountBuckets ?: (object)[],
    ],
    'per_day'          => $perDay,
    'by_user'          => $byUser ?: (object)[],
]);
