<?php
require_once __DIR__ . '/utils.php';

$session_token =
  $_GET['s']
  ?? $_GET['session']
  ?? $_GET['sessionToken']
  ?? '';

// CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

@set_time_limit(0);
@ignore_user_abort(true);

// Optional debug logs
log_msg("SSE REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? ''));
log_msg("SSE QUERY_STRING=" . ($_SERVER['QUERY_STRING'] ?? ''));
log_msg("SSE _GET=" . json_encode($_GET));

if (!$session_token) {
    echo "event: error\n";
    echo 'data: ' . json_encode(['error' => 'Missing session token']) . "\n\n";
    @ob_flush(); @flush();
    exit;
}

$path       = session_file_path($session_token);
$last_mtime = 0;
$lastKeepalive = time();

while (true) {
    if (connection_aborted()) break;

    clearstatcache(true, $path);

    if (file_exists($path)) {
        $mtime = filemtime($path);
        if ($mtime !== $last_mtime) {
            $last_mtime = $mtime;
            $data = file_get_contents($path);
            if ($data === false || $data === '') {
                $data = json_encode(['error' => 'empty session state']);
            }

            echo "event: update\n";
            echo "id: $mtime\n";
            echo "data: " . $data . "\n\n";
            @ob_flush(); @flush();
        }
    }

    if (time() - $lastKeepalive >= 20) {
        echo ": keepalive\n\n";
        @ob_flush(); @flush();
        $lastKeepalive = time();
    }

    sleep(1);
}
