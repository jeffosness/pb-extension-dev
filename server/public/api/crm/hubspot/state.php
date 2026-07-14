<?php
// server/public/api/crm/hubspot/state.php
//
// Returns connection readiness for this browser client_id:
// - PhoneBurner PAT present?
// - HubSpot OAuth tokens present?
//
// IMPORTANT: This endpoint is consumed by the extension, so it must return
// FLAT keys (pb_ready, hs_ready, etc). We use api_ok_flat() to keep the
// legacy/extension-friendly response shape.

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';

$data      = json_input();
$client_id = get_client_id_or_fail($data);

$pbPat    = load_pb_token($client_id);
$hsTokens = load_hs_tokens($client_id);

// Check whether the customer's HubSpot tokens have the contacts.write scope.
// Used by the extension's Task Queue UI to decide between "launch" and
// "reconnect to enable" prompts. Customers on legacy demo-org tokens won't
// have this scope until they reconnect via the new PB-portal app.
//
// HubSpot's token response stores granted scopes as an ARRAY under the
// 'scopes' (plural) key. We also defensively check 'scope' (singular,
// space-separated string) in case any old token files use that shape.
$hasTaskScope = false;
if (is_array($hsTokens)) {
    $scopesArr = $hsTokens['scopes'] ?? null;
    if (is_array($scopesArr)) {
        $hasTaskScope = in_array('crm.objects.contacts.write', $scopesArr, true);
    } else {
        $scopeStr = (string)($hsTokens['scope'] ?? '');
        if ($scopeStr !== '') {
            $hasTaskScope = in_array(
                'crm.objects.contacts.write',
                preg_split('/\s+/', $scopeStr),
                true
            );
        }
    }
}

api_log('hubspot_state.ok', [
    'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
    'pb_ready'       => (bool)$pbPat,
    'hs_ready'       => (bool)$hsTokens,
    'has_task_scope' => $hasTaskScope,
]);

api_ok_flat([
    'client_id' => $client_id,

    // legacy/top-level flags used by the extension UI
    'pb_ready'  => (bool)$pbPat,
    'hs_ready'  => (bool)$hsTokens,

    // nested structure kept for compatibility
    'phoneburner' => [
        'connected' => (bool)$pbPat,
    ],
    'hubspot' => [
        'connected'      => (bool)$hsTokens,
        'expires_at'     => is_array($hsTokens) ? ($hsTokens['expires_at'] ?? null) : null,
        'portal_id'      => is_array($hsTokens) ? ($hsTokens['hub_id'] ?? null)      : null,
        // Task Queue feature requires crm.objects.contacts.write; customers on
        // legacy demo-org tokens won't have it until they reconnect.
        'has_task_scope' => $hasTaskScope,
    ],
]);
