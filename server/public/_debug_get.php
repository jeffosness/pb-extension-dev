<?php
header('Content-Type: application/json');
echo json_encode([
  'query_string' => $_SERVER['QUERY_STRING'] ?? null,
  'get' => $_GET,
  'uri' => $_SERVER['REQUEST_URI'] ?? null,
], JSON_PRETTY_PRINT);
