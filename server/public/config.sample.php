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

    // ── Click-to-Call (generic softphone) ────────────────────────
    // HMAC secret from your PhoneBurner softphone registration
    // (Settings → Developer → Softphones). PhoneBurner signs the
    // softphone_call_done webhook body with this secret; webhooks/
    // softphone_call_done.php verifies X-PB-Signature against it.
    // One secret per registration; for multiple registrations, switch
    // this to a slug-keyed map and look up by the incoming slug.
    'SOFTPHONE_HMAC_SECRET' => 'CHANGE_ME_SOFTPHONE_SECRET',

    // DEV/TEST ONLY — forces the softphone iframe to authenticate with this
    // fixed bearer token (softphone.php ?token=). Needed locally because the
    // per-user PAT is a PRODUCTION token that won't auth against a local-dev PB.
    // Leave EMPTY/unset in production so each user's own PAT is used.
    'SOFTPHONE_TEST_TOKEN' => '',

    // ── Logging ──────────────────────────────────────────────────
    // Dev:  /opt/pb-extension-dev/var/log/app.log
    // Prod: /opt/pb-extension/var/log/app.log
    'LOG_FILE' => '/opt/pb-extension-dev/var/log/app.log',

    // Set to true to enable debug endpoints (scan_debug.php, _debug_get.php)
    // NEVER enable in production
    'DEBUG_MODE' => false,

    // ── HubSpot OAuth ────────────────────────────────────────────
    // The new PB-portal HubSpot app supports multiple redirect URIs, so
    // dev and prod share the same client_id/secret. The redirect URI used
    // is whichever matches the BASE_URL of the request.
    // Redirect URI: {BASE_URL}/api/crm/hubspot/oauth_hs_finish.php
    'HS_CLIENT_ID' => 'Client_ID',
    'HS_CLIENT_SECRET' => 'Client_Secret',
    'HS_SCOPES' => 'crm.lists.read crm.objects.companies.read crm.objects.contacts.read crm.objects.contacts.write crm.objects.deals.read crm.objects.owners.read crm.schemas.companies.read crm.schemas.contacts.read',

    // ── HubSpot legacy OAuth fallback (optional) ─────────────────
    // Set these to the OLD HubSpot app's credentials during the migration
    // window from a previous OAuth app. hs_refresh_access_token_or_fail()
    // will fall back to these if a refresh fails with the primary credentials,
    // letting existing customer tokens (issued by the old app) keep refreshing
    // until the customer reconnects via the new app.
    //
    // Remove these once telemetry shows ~zero `hubspot_refresh.legacy_creds.ok`
    // log events — that means all customers have reconnected via the new app
    // and the old app's tokens are no longer in use.
    //
    // Leave commented/unset if no OAuth app migration is in progress.
    // 'HS_LEGACY_CLIENT_ID' => 'Old_Client_ID',
    // 'HS_LEGACY_CLIENT_SECRET' => 'Old_Client_Secret',

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

