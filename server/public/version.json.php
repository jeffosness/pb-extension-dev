<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$versionFile = __DIR__ . '/version.php';
$versionInfo = file_exists($versionFile) ? (require $versionFile) : [];

echo json_encode([
  'ok'      => true,
  'version' => $versionInfo['version'] ?? null,
  'env'     => $versionInfo['env'] ?? null,
], JSON_PRETTY_PRINT);
