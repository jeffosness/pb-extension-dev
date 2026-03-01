<?php
require_once __DIR__ . '/utils.php';

$cfg = cfg();
if (empty($cfg['DEBUG_MODE'])) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json');
echo json_encode([
  'query_string' => $_SERVER['QUERY_STRING'] ?? null,
  'get' => $_GET,
  'uri' => $_SERVER['REQUEST_URI'] ?? null,
], JSON_PRETTY_PRINT);
