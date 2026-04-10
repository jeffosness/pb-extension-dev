<?php
// config.php — Environment-specific settings
//
// Each environment (dev, prod) has its own copy of this file.
// This file is NOT tracked in git. See DEPLOYMENT.md for setup.
//
// On the server, this file is locked with: sudo chattr +i config.php
// To edit:  sudo chattr -i config.php   (unlock)
// After:    sudo chattr +i config.php   (re-lock)
// To check: lsattr config.php           (look for 'i' flag)
//
// ┌─────────────────────────────────────────────────────────────────┐
// │ Environment        │ BASE_URL                                   │
// │ Dev/Staging        │ https://extension-dev.phoneburner.biz      │
// │ Production         │ https://extension.phoneburner.biz          │
// └─────────────────────────────────────────────────────────────────┘

return [
    // ── Environment ──────────────────────────────────────────────
    // Dev:  https://extension-dev.phoneburner.biz
    // Prod: https://extension.phoneburner.biz
    'BASE_URL' => 'https://extension-dev.phoneburner.biz',

    // ── Token storage (MUST be outside webroot) ──────────────────
    // Dev:  /var/lib/pb-extension-dev/tokens
    // Prod: /var/lib/pb-extension/tokens
    'TOKENS_DIR' => '/var/lib/pb-extension-dev/tokens',

    // ── Session + user data (inside webroot, protected by .htaccess)
    'SESSIONS_DIR' => __DIR__ . '/sessions',
    'USER_SETTINGS_DIR' => __DIR__ . '/user_settings',

    // ── PhoneBurner API ──────────────────────────────────────────
    'PB_API_BASE' => 'https://www.phoneburner.com/rest/1',
    'PB_WEBHOOK_SECRET' => 'CHANGE_ME_WEBHOOK_SECRET',

    // ── Logging ──────────────────────────────────────────────────
    // Dev:  /opt/pb-extension-dev/var/log/app.log
    // Prod: /opt/pb-extension/var/log/app.log
    'LOG_FILE' => '/opt/pb-extension-dev/var/log/app.log',

    // Set to true to enable debug endpoints (scan_debug.php, _debug_get.php)
    // NEVER enable in production
    'DEBUG_MODE' => false,

    // ── HubSpot OAuth ────────────────────────────────────────────
    // Create separate OAuth apps for dev and prod environments.
    // Redirect URI: {BASE_URL}/api/crm/hubspot/oauth_hs_finish.php
    'HS_CLIENT_ID' => 'Client_ID',
    'HS_CLIENT_SECRET' => 'Client_Secret',
    'HS_SCOPES' => 'crm.objects.contacts.read crm.lists.read crm.objects.deals.read crm.objects.companies.read crm.schemas.contacts.read crm.schemas.companies.read',

    // ── Close OAuth ──────────────────────────────────────────────
    // Create separate OAuth apps for dev and prod environments.
    // Redirect URI: {BASE_URL}/api/crm/close/oauth_close_finish.php
    'CLOSE_CLIENT_ID' => 'Client_ID',
    'CLOSE_CLIENT_SECRET' => 'Client_Secret',

    // ── Apollo OAuth ─────────────────────────────────────────────
    // Register at developer.apollo.io > OAuth Registration
    // Redirect URI: {BASE_URL}/api/crm/apollo/oauth_apollo_finish.php
    'APOLLO_CLIENT_ID' => 'Client_ID',
    'APOLLO_CLIENT_SECRET' => 'Client_Secret',
];

