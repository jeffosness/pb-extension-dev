<?php
// scripts/diagnostics/replay_hs_dialsession.php
//
// Replay a HubSpot dial-session build for a specific customer, given their
// PhoneBurner member_user_id and a list of HubSpot contact IDs. Output what
// our extension WOULD HAVE SENT to PhoneBurner — no actual dial session is
// created, no API call is made to PhoneBurner. HubSpot IS called (read-only)
// using the customer's stored OAuth token.
//
// Usage on the prod VPS:
//   sudo -u www-data php /opt/pb-extension/scripts/diagnostics/replay_hs_dialsession.php \
//     --member_user_id=1282259974 \
//     --ids=213341452817,213341452819,213044636997,...
//
// Or read IDs from a file (one per line OR comma-separated):
//   sudo -u www-data php /opt/pb-extension/scripts/diagnostics/replay_hs_dialsession.php \
//     --member_user_id=1282259974 \
//     --ids_file=/tmp/contact_ids.txt
//
// Output:
//   - which client_id is mapped from the member_user_id
//   - the customer's preferred_phone_property setting
//   - the phone properties discovered on their HubSpot account
//   - per-contact: which phone fields had values, what we'd set as primary,
//     and what we'd put in additional_phone
//   - a summary that simulates PB's "no duplicates in same session" dedup
//
// Safe to run repeatedly. Read-only.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(2);
}

// Find the project root (script is at <root>/scripts/diagnostics/<this>)
$root = dirname(__DIR__, 2);
require_once $root . '/server/public/config.php';
require_once $root . '/server/public/utils.php';
require_once $root . '/server/public/api/crm/hubspot/hs_helpers.php';

// --- Parse args -------------------------------------------------------------
$opts = getopt('', ['member_user_id:', 'ids::', 'ids_file::', 'verbose']);
$memberUserId = trim((string)($opts['member_user_id'] ?? ''));
if ($memberUserId === '') {
    fwrite(STDERR, "Missing --member_user_id\n");
    exit(2);
}

$idsRaw = '';
if (!empty($opts['ids'])) {
    $idsRaw = (string)$opts['ids'];
} elseif (!empty($opts['ids_file'])) {
    $idsRaw = file_get_contents((string)$opts['ids_file']);
    if ($idsRaw === false) {
        fwrite(STDERR, "Could not read ids_file\n");
        exit(2);
    }
}
$ids = preg_split('/[\s,]+/', $idsRaw, -1, PREG_SPLIT_NO_EMPTY);
$ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
if (empty($ids)) {
    fwrite(STDERR, "No contact IDs supplied via --ids or --ids_file\n");
    exit(2);
}
$verbose = isset($opts['verbose']);

echo "Member user ID: $memberUserId\n";
echo "Contact IDs supplied: " . count($ids) . "\n";

// --- Resolve client_id by grepping PB token files for member_user_id --------
$pbTokensDir = cfg('TOKENS_DIR') . '/pb';
if (!is_dir($pbTokensDir)) {
    fwrite(STDERR, "PB tokens directory not found: $pbTokensDir\n");
    exit(2);
}

$clientId = null;
foreach (glob($pbTokensDir . '/*.json') as $tokenFile) {
    $raw = @file_get_contents($tokenFile);
    if ($raw === false) continue;
    $data = json_decode($raw, true);
    if (!is_array($data)) continue;
    if ((string)($data['member_user_id'] ?? '') === $memberUserId) {
        $clientId = basename($tokenFile, '.json');
        break;
    }
}
if ($clientId === null) {
    fwrite(STDERR, "No PB token file matches member_user_id $memberUserId\n");
    fwrite(STDERR, "Searched: $pbTokensDir\n");
    exit(2);
}
echo "Resolved client_id: " . substr(hash('sha256', $clientId), 0, 12) . " (hashed)\n";

// --- Load HubSpot tokens, refresh if needed ---------------------------------
$hs = load_hs_tokens($clientId);
if (!is_array($hs)) {
    fwrite(STDERR, "No HubSpot tokens for this client_id\n");
    exit(2);
}
if (hs_token_is_expired($hs)) {
    echo "HubSpot token expired — refreshing...\n";
    $hs = hs_refresh_access_token_or_fail($clientId, $hs);
}
$hsAccess = (string)($hs['access_token'] ?? '');
$hubId    = (string)($hs['hub_id'] ?? '');
if ($hsAccess === '') {
    fwrite(STDERR, "No access_token in HubSpot tokens\n");
    exit(2);
}
echo "HubSpot hub_id: $hubId\n";

// --- Load user's preferred phone property -----------------------------------
$preferredPhoneProp = null;
$userSettings = load_user_settings($memberUserId);
if (is_array($userSettings)) {
    $preferredPhoneProp = $userSettings['crm_preferences']['hubspot']['preferred_phone_property_contacts'] ?? null;
    if ($preferredPhoneProp !== null) {
        $preferredPhoneProp = trim((string)$preferredPhoneProp);
        if ($preferredPhoneProp === '') $preferredPhoneProp = null;
    }
}
echo "Preferred phone property: " . ($preferredPhoneProp ?? '(none — uses first non-empty)') . "\n";

// --- Discover phone properties ---------------------------------------------
$phoneProperties = hs_discover_phone_properties($hsAccess, 'contacts', $hubId);
echo "\nDiscovered " . count($phoneProperties) . " phone properties on this account:\n";
foreach ($phoneProperties as $p) {
    echo "  - " . ($p['name'] ?? '?') . "  (label: " . ($p['label'] ?? '?') . ")\n";
}

// --- Fetch contacts and simulate the dial-session payload -------------------
echo "\nFetching " . count($ids) . " contacts from HubSpot...\n";
$diag = [];
$fetched = hs_fetch_contacts_by_ids($hsAccess, $ids, $phoneProperties, $diag, $preferredPhoneProp);
echo "Fetched OK: " . ($diag['contacts_fetch']['ok'] ?? 0) . " | Failed: " . ($diag['contacts_fetch']['fail'] ?? 0) . "\n";

// --- Per-contact analysis ---------------------------------------------------
function norm_for_dedup(string $v): string {
    $d = preg_replace('/\D/', '', $v);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return $d;
}

$skipped_no_phone = 0;
$pb_payload = [];     // each entry: [hs_id, name, primary (normalized), all_phones_normalized]
foreach ($fetched as $c) {
    $primary = trim((string)($c['phone'] ?? ''));
    if ($primary === '') { $skipped_no_phone++; continue; }
    $primaryN = norm_for_dedup($primary);
    $allN = [$primaryN];
    foreach (($c['additional_phones'] ?? []) as $ap) {
        $n = norm_for_dedup((string)($ap['number'] ?? ''));
        if ($n !== '' && !in_array($n, $allN, true)) $allN[] = $n;
    }
    $pb_payload[] = [
        'hs_id'    => $c['hs_id'],
        'name'     => trim($c['first_name'] . ' ' . $c['last_name']),
        'primary'  => $primaryN,
        'phones'   => $allN,
        'phone_count' => count($allN),
    ];
}

echo "\n=== What we'd send to PhoneBurner ===\n";
echo "Total contacts in payload: " . count($pb_payload) . "\n";
echo "Skipped (no primary phone after normalization): $skipped_no_phone\n";

$phoneCountHistogram = [];
foreach ($pb_payload as $p) {
    $k = $p['phone_count'];
    $phoneCountHistogram[$k] = ($phoneCountHistogram[$k] ?? 0) + 1;
}
ksort($phoneCountHistogram);
echo "Phone-count distribution (how many phones per contact):\n";
foreach ($phoneCountHistogram as $k => $v) echo "  $k phones: $v contacts\n";

// --- Simulate PB dedup on PRIMARY phone only --------------------------------
$seenPrimary = [];
$keptPrimary = 0;
$droppedPrimary = [];
foreach ($pb_payload as $p) {
    if (isset($seenPrimary[$p['primary']])) {
        $droppedPrimary[] = $p['name'] . ' (primary=' . $p['primary'] . ' collided with ' . $seenPrimary[$p['primary']] . ')';
    } else {
        $seenPrimary[$p['primary']] = $p['name'];
        $keptPrimary++;
    }
}

// --- Simulate PB dedup on UNION of all phones per contact ------------------
$seenAny = [];
$keptUnion = 0;
$droppedUnion = [];
foreach ($pb_payload as $p) {
    $hit = null;
    foreach ($p['phones'] as $ph) {
        if (isset($seenAny[$ph])) { $hit = $ph; break; }
    }
    if ($hit !== null) {
        $droppedUnion[] = $p['name'] . ' (collided on ' . $hit . ' with ' . $seenAny[$hit] . ')';
    } else {
        foreach ($p['phones'] as $ph) $seenAny[$ph] = $p['name'];
        $keptUnion++;
    }
}

echo "\n=== PB dedup scenarios ===\n";
echo "If PB dedupes by PRIMARY phone only:\n";
echo "  → Kept: $keptPrimary | Dropped: " . count($droppedPrimary) . "\n";
echo "If PB dedupes by ANY phone (union across primary + additional):\n";
echo "  → Kept: $keptUnion | Dropped: " . count($droppedUnion) . "\n";

if ($verbose) {
    echo "\n=== Per-contact detail ===\n";
    foreach ($pb_payload as $p) {
        echo "  hs_id={$p['hs_id']} name={$p['name']} primary={$p['primary']} all=" . implode(',', $p['phones']) . "\n";
    }
    echo "\n=== Dropped under PRIMARY-only dedup ===\n";
    foreach ($droppedPrimary as $d) echo "  $d\n";
    echo "\n=== Dropped under UNION dedup ===\n";
    foreach ($droppedUnion as $d) echo "  $d\n";
}
