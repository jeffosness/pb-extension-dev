<?php
// generic_crm/config.php

return [
    // Base URL for THIS project. When you move this later,
    // just update this value.
    'BASE_URL' => 'https://extension-dev.phoneburner.biz',

    // Where to store PhoneBurner PATs (per client_id).
    'TOKENS_DIR' => __DIR__ . '/tokens',

    // Where to store per-dial-session JSON state files.
    'SESSIONS_DIR' => __DIR__ . '/sessions',

    // NEW: Where to store per-PhoneBurner-user settings (by member_user_id).
    'USER_SETTINGS_DIR' => __DIR__ . '/user_settings',

    // PhoneBurner API base
    'PB_API_BASE' => 'https://www.phoneburner.com/rest/1',

    // Webhook secret (set this to match your PB webhook configuration)
    'PB_WEBHOOK_SECRET' => 'CHANGE_ME_WEBHOOK_SECRET',

    // Simple logging toggle
    'LOG_FILE' => '/opt/pb-extension-dev/var/log/app.log',

        // HubSpot OAuth credentials (fill these with your real app values)
  'HS_CLIENT_ID' => 'Client_ID',
  'HS_CLIENT_SECRET' => 'Client_Secret',
  'HS_SCOPES' => 'crm.objects.contacts.read crm.lists.read crm.objects.deals.read crm.objects.companies.read',

];

