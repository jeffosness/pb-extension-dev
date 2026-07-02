<?php
// tests/bootstrap.php — loaded by PHPUnit before every test suite.
//
// Responsibilities:
//   1. Ensure a config.php exists so cfg() in utils.php doesn't fatal.
//      Falls back to config.sample.php if config.php is absent (CI, or a
//      fresh clone without a real dev config yet).
//   2. Require utils.php so the functions under test are available.
//
// We DO NOT include api/core/bootstrap.php here — that file sends CORS
// headers, sets timezone, and generally assumes an HTTP request context.
// The functions in utils.php (safe_file_path, atomic_write_json, temp code
// helpers) are the ones we care most about testing; they're self-contained
// and don't need bootstrap.php. redact_pii_recursive lives in
// api/core/bootstrap.php and is a follow-up test target.

declare(strict_types=1);

$serverPublic = __DIR__ . '/../server/public';

if (!file_exists($serverPublic . '/config.php')) {
    if (!copy($serverPublic . '/config.sample.php', $serverPublic . '/config.php')) {
        fwrite(STDERR, "tests/bootstrap.php: failed to seed config.php from config.sample.php\n");
        exit(1);
    }
}

require_once $serverPublic . '/utils.php';
