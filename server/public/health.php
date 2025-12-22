<?php
// /public/health.php
header('Content-Type: application/json');

$baseDir = __DIR__;

// -------------------------
// Load version.php (optional)
// -------------------------
$versionInfo = null;
$versionFile = $baseDir . '/version.php';
if (file_exists($versionFile)) {
    $tmp = require $versionFile;
    if (is_array($tmp)) {
        $versionInfo = $tmp;
    }
}

$results = [
  'ok' => true,
  'time' => date('c'),
  'php_version' => PHP_VERSION,
  'curl_loaded' => extension_loaded('curl'),

  // NEW: version stamp
  'version' => $versionInfo['version'] ?? null,
  'env'     => $versionInfo['env'] ?? null,

  'checks' => [],
];

function add_check(&$results, $name, $ok, $details = null) {
  $results['checks'][] = [
    'name' => $name,
    'ok' => (bool)$ok,
    'details' => $details,
  ];
  if (!$ok) $results['ok'] = false;
}

$configPath = $baseDir . '/config.php';
if (!file_exists($configPath)) {
  add_check($results, 'config.php exists', false, $configPath);
  echo json_encode($results, JSON_PRETTY_PRINT);
  exit;
}

$cfg = require $configPath;
add_check($results, 'config.php loads', is_array($cfg), 'loaded');

$requiredDirs = [
  'TOKENS_DIR'        => $cfg['TOKENS_DIR']        ?? ($baseDir . '/tokens'),
  'USER_SETTINGS_DIR' => $cfg['USER_SETTINGS_DIR'] ?? ($baseDir . '/user_settings'),
  'SESSIONS_DIR'      => $baseDir . '/sessions',
  'DAILY_STATS_DIR'   => $baseDir . '/daily_stats',
  'METRICS_DIR'       => $baseDir . '/metrics',
];

foreach ($requiredDirs as $label => $dir) {
  // Create if missing
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  add_check($results, "$label exists", is_dir($dir), $dir);
  add_check($results, "$label writable", is_writable($dir), $dir);
}

// Log file check
$logFile = $cfg['LOG_FILE'] ?? ($baseDir . '/metrics/app.log');
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
  @mkdir($logDir, 0775, true);
}
$logWriteOk = @file_put_contents($logFile, '[' . date('c') . "] healthcheck\n", FILE_APPEND);
add_check($results, 'LOG_FILE writable', $logWriteOk !== false, $logFile);

echo json_encode($results, JSON_PRETTY_PRINT);
