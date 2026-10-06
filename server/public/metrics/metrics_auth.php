<?php
// server/public/metrics/metrics_auth.php
//
// Defense-in-depth auth check for everything under /metrics/.
//
// The primary gate is Apache Basic Auth on <Location "/metrics/"> in the
// vhost (SERVER_SETUP.md §5). That rule lives in server config, not in the
// repo, so a vhost rebuild or a certbot-regenerated -le-ssl.conf that drops
// the block would silently make the dashboard and its data feeds public.
// This check makes each metrics page refuse to run unless Apache actually
// authenticated the request.
//
// History: until 2026-10 the dashboard's JSON feeds lived in /api/core/,
// outside the Basic Auth <Location>, and were readable by anyone. They now
// live in /metrics/api/ and call this guard. See LESSONS.md 2026-10-06.
//
// Include AFTER bootstrap.php (uses api_error / api_log).

function metrics_require_auth(): void {
    // PHP's built-in dev server (`php -S`) has no Basic Auth. It is never
    // used in production (Apache + mod_php), so allow it for local work.
    if (PHP_SAPI === 'cli-server') {
        return;
    }

    // Only trust identity that Apache set AFTER verifying the password.
    // Do NOT fall back to PHP_AUTH_USER: mod_php fills it straight from the
    // request's Authorization header with no validation, so with the vhost
    // rule missing, `curl -u anyone:anything` would pass. (Codex review,
    // 2026-10-06.)
    $user = (string)($_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '');
    if ($user !== '') {
        return;
    }

    api_log('metrics_auth.denied', [
        'path' => isset($_SERVER['SCRIPT_NAME']) ? substr((string)$_SERVER['SCRIPT_NAME'], 0, 120) : null,
    ]);
    // 404, not 401: don't advertise that something lives here.
    api_error('Not found', 'not_found', 404);
}
