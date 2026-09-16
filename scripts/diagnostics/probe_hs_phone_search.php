<?php
// scripts/diagnostics/probe_hs_phone_search.php
//
// Settle the "can we reliably find a HubSpot contact by phone number?" question
// for the dial-pad feature. Runs EVERY candidate search strategy against a real
// HubSpot portal and prints a hit/miss matrix — so we can see which strategy
// finds known contacts regardless of how the number is stored/formatted.
//
// Two ways to authenticate:
//
//   (A) LOCAL — HubSpot Private App token (easiest for quick tests):
//       Fully self-contained. Needs NOTHING from the repo or server — inline
//       HTTP + inline phone-property discovery. Pass the token via env var
//       (keeps it out of shell history) or --access_token=.
//
//         # PowerShell (Windows):
//         $env:HS_ACCESS_TOKEN = "pat-na1-xxxxxxxx"
//         php scripts\diagnostics\probe_hs_phone_search.php --sample=8
//         php scripts\diagnostics\probe_hs_phone_search.php --numbers="6152650077, (615) 265-0077, +16152650077"
//
//   (B) SERVER — reuse a customer's stored OAuth token on the VPS (read-only):
//         sudo -u www-data php /tmp/probe_hs_phone_search.php \
//           --root=/opt/pb-extension --member_user_id=838001177 --sample=8
//
// Read-only either way. Makes NO writes to HubSpot or PhoneBurner.
//
// Recommended: run --sample=N first (no --numbers) to see the REAL stored format
// and pick a known contact's number; then test that number in several formats
// plus one number you KNOW isn't in the portal (to confirm misses are clean).
//
// Options:
//   --access_token=TOKEN   HubSpot Private App token (or set HS_ACCESS_TOKEN). Enables local mode.
//   --member_user_id=ID    Server mode: resolve the customer's OAuth token by PB member id.
//   --root=/path           Server mode: the install root (defaults to repo root of this file).
//   --sample=N             List N recent contacts + their raw stored phone values.
//   --numbers="a, b, c"    Comma/newline separated numbers to test (spaces allowed within one).
//   --numbers_file=PATH    Read numbers from a file instead.
//   --props=phone,mobile   Override phone-property discovery with an explicit list.
//   --verbose              Print every match's id + stored phone values.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(2);
}

$opts = getopt('', [
    'root::', 'member_user_id::', 'numbers::', 'numbers_file::',
    'sample::', 'props::', 'access_token::', 'verbose',
]);

$accessTokenDirect = trim((string)($opts['access_token'] ?? ''));
if ($accessTokenDirect === '') {
    $envTok = getenv('HS_ACCESS_TOKEN');
    if ($envTok !== false) $accessTokenDirect = trim($envTok);
}
$tokenMode = ($accessTokenDirect !== '');

$sample  = isset($opts['sample']) ? (int)$opts['sample'] : 0;
$verbose = isset($opts['verbose']);

$numbersRaw = '';
if (!empty($opts['numbers'])) {
    $numbersRaw = (string)$opts['numbers'];
} elseif (!empty($opts['numbers_file'])) {
    $numbersRaw = (string)@file_get_contents((string)$opts['numbers_file']);
}
// Split on commas / newlines only (NOT spaces — "(615) 265-0077" contains one).
$numbers = preg_split('/[,\r\n]+/', $numbersRaw, -1, PREG_SPLIT_NO_EMPTY);
$numbers = array_values(array_filter(array_map('trim', $numbers)));

if ($sample <= 0 && empty($numbers)) {
    fwrite(STDERR, "Nothing to do: pass --sample=N and/or --numbers=\"...\"\n");
    exit(2);
}

// --- Inline HTTP (so local/token mode needs nothing from the repo) -----------

function probe_http($token, string $method, string $url, ?array $body = null): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    $optArr = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25];
    if ($method === 'POST') {
        $optArr[CURLOPT_POST] = true;
        $optArr[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
        $headers[] = 'Content-Type: application/json';
    }
    $optArr[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $optArr);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
    return [$code, $json, $raw];
}

// --- Inline search logic (mirror of the eventual hs_helpers.php helper) ------

/** Canonicalize to US 10-digit form: strip non-digits, drop a leading US "1". */
function probe_norm_digits(string $raw): string {
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return $d;
}

/** Discover phone-type properties on the contacts object (fieldType=phonenumber). */
function probe_discover_phone_props($token): array {
    [$code, $json] = probe_http($token, 'GET', 'https://api.hubapi.com/crm/v3/properties/contacts');
    $out = [];
    if ($code >= 200 && $code < 300 && isset($json['results']) && is_array($json['results'])) {
        foreach ($json['results'] as $p) {
            if (($p['fieldType'] ?? '') === 'phonenumber') {
                $out[] = ['name' => (string)($p['name'] ?? ''), 'label' => (string)($p['label'] ?? '')];
            }
        }
    }
    if (empty($out)) {
        $out = [['name' => 'phone', 'label' => 'Phone Number'], ['name' => 'mobilephone', 'label' => 'Mobile Phone Number']];
    }
    return $out;
}

/** Property names to search across (cap 5 = HubSpot filterGroup limit). */
function probe_phone_prop_names(array $phoneProperties): array {
    $names = [];
    foreach ($phoneProperties as $p) {
        $n = trim((string)($p['name'] ?? ''));
        if ($n !== '') $names[] = $n;
    }
    if (empty($names)) $names = ['phone', 'mobilephone'];
    return array_slice(array_values(array_unique($names)), 0, 5);
}

/** Run one strategy. Returns ['ok','http','query','count','matches','error']. */
function probe_search($token, string $rawNumber, array $phoneProperties, string $strategy): array {
    $digits = probe_norm_digits($rawNumber);
    switch ($strategy) {
        case 'contains_last10': $op = 'CONTAINS_TOKEN'; $val = '*' . $digits . '*'; break;
        case 'contains_last7':  $op = 'CONTAINS_TOKEN'; $val = '*' . substr($digits, -7) . '*'; break;
        case 'contains_last4':  $op = 'CONTAINS_TOKEN'; $val = '*' . substr($digits, -4) . '*'; break;
        case 'eq_e164':         $op = 'EQ'; $val = '+1' . $digits; break;
        case 'eq_last10':       $op = 'EQ'; $val = $digits; break;
        case 'eq_raw':          $op = 'EQ'; $val = trim($rawNumber); break;
        default: return ['ok' => false, 'http' => 0, 'query' => '', 'count' => 0, 'matches' => [], 'error' => "unknown strategy $strategy"];
    }
    if ($digits === '' && $strategy !== 'eq_raw') {
        return ['ok' => false, 'http' => 0, 'query' => $op . ':' . $val, 'count' => 0, 'matches' => [], 'error' => 'no digits'];
    }

    $propNames    = probe_phone_prop_names($phoneProperties);
    $filterGroups = [];
    foreach ($propNames as $pn) {
        $filterGroups[] = ['filters' => [['propertyName' => $pn, 'operator' => $op, 'value' => $val]]];
    }
    $returnProps = array_values(array_unique(array_merge(['firstname', 'lastname', 'company'], $propNames)));
    [$code, $json, $raw] = probe_http($token, 'POST', 'https://api.hubapi.com/crm/v3/objects/contacts/search', [
        'filterGroups' => $filterGroups,
        'properties'   => $returnProps,
        'limit'        => 20,
        'sorts'        => [['propertyName' => 'lastmodifieddate', 'direction' => 'DESCENDING']],
    ]);
    if ($code < 200 || $code >= 300) {
        $err = is_array($json) && isset($json['message']) ? (string)$json['message'] : substr((string)$raw, 0, 200);
        return ['ok' => false, 'http' => $code, 'query' => $op . ':' . $val, 'count' => 0, 'matches' => [], 'error' => $err];
    }
    $matches = [];
    foreach (($json['results'] ?? []) as $r) {
        $props  = $r['properties'] ?? [];
        $phones = [];
        foreach ($propNames as $pn) {
            $pv = trim((string)($props[$pn] ?? ''));
            if ($pv !== '') $phones[$pn] = $pv;
        }
        $matches[] = [
            'id'        => (string)($r['id'] ?? ''),
            'firstname' => trim((string)($props['firstname'] ?? '')),
            'lastname'  => trim((string)($props['lastname'] ?? '')),
            'company'   => trim((string)($props['company'] ?? '')),
            'phones'    => $phones,
        ];
    }
    return ['ok' => true, 'http' => $code, 'query' => $op . ':' . $val, 'count' => count($matches), 'matches' => $matches];
}

// --- Resolve the access token -----------------------------------------------
$hsAccess = '';
if ($tokenMode) {
    $hsAccess = $accessTokenDirect;
    echo "Auth: HubSpot Private App token (local mode)\n";
} else {
    // Server mode: resolve the customer's stored OAuth token from the install.
    $root = trim((string)($opts['root'] ?? ''));
    if ($root === '') $root = dirname(__DIR__, 2);
    $root = rtrim($root, '/');
    foreach (['/server/public/config.php', '/server/public/utils.php', '/server/public/api/crm/hubspot/hs_helpers.php'] as $rel) {
        if (!is_file($root . $rel)) {
            fwrite(STDERR, "No token given and cannot find $rel under --root=$root.\n");
            fwrite(STDERR, "Either set HS_ACCESS_TOKEN (local mode) or pass a valid --root (e.g. /opt/pb-extension).\n");
            exit(2);
        }
    }
    require_once $root . '/server/public/config.php';
    require_once $root . '/server/public/utils.php';
    require_once $root . '/server/public/api/crm/hubspot/hs_helpers.php';

    $memberUserId = trim((string)($opts['member_user_id'] ?? ''));
    if ($memberUserId === '') {
        fwrite(STDERR, "Server mode requires --member_user_id.\n");
        exit(2);
    }
    $pbTokensDir = cfg('TOKENS_DIR') . '/pb';
    if (!is_dir($pbTokensDir)) {
        fwrite(STDERR, "PB tokens directory not found: $pbTokensDir\n");
        exit(2);
    }
    $clientId = null;
    foreach (glob($pbTokensDir . '/*.json') as $tokenFile) {
        $data = json_decode((string)@file_get_contents($tokenFile), true);
        if (is_array($data) && (string)($data['member_user_id'] ?? '') === $memberUserId) {
            $clientId = basename($tokenFile, '.json');
            break;
        }
    }
    if ($clientId === null) {
        fwrite(STDERR, "No PB token file matches member_user_id $memberUserId in $pbTokensDir\n");
        exit(2);
    }
    echo "Root: $root\n";
    echo "Resolved client_id: " . substr(hash('sha256', $clientId), 0, 12) . " (hashed)\n";
    $hs = load_hs_tokens($clientId);
    if (!is_array($hs)) {
        fwrite(STDERR, "No HubSpot tokens for this client_id (is this the right env?)\n");
        exit(2);
    }
    if (hs_token_is_expired($hs)) {
        echo "HubSpot token expired — refreshing...\n";
        $hs = hs_refresh_access_token_or_fail($clientId, $hs);
    }
    $hsAccess = (string)($hs['access_token'] ?? '');
    if ($hsAccess === '') {
        fwrite(STDERR, "No access_token in HubSpot tokens\n");
        exit(2);
    }
    echo "HubSpot hub_id: " . (string)($hs['hub_id'] ?? '') . "\n";
}

// --- Determine phone properties ---------------------------------------------
if (!empty($opts['props'])) {
    $phoneProperties = [];
    foreach (preg_split('/[,\s]+/', (string)$opts['props'], -1, PREG_SPLIT_NO_EMPTY) as $pn) {
        $phoneProperties[] = ['name' => $pn, 'label' => $pn];
    }
} else {
    $phoneProperties = probe_discover_phone_props($hsAccess);
}
echo "\nPhone properties searched (" . count($phoneProperties) . "):\n";
foreach ($phoneProperties as $p) {
    echo "  - " . ($p['name'] ?? '?') . "  (" . ($p['label'] ?? '?') . ")\n";
}

// --- Sample: show how numbers are actually STORED ---------------------------
if ($sample > 0) {
    echo "\n=== Sample of $sample recent contacts (raw stored phone values) ===\n";
    $propNames   = probe_phone_prop_names($phoneProperties);
    $returnProps = array_values(array_unique(array_merge(['firstname', 'lastname'], $propNames)));
    [$code, $json] = probe_http($hsAccess, 'POST', 'https://api.hubapi.com/crm/v3/objects/contacts/search', [
        'filterGroups' => [],
        'properties'   => $returnProps,
        'limit'        => min(max($sample, 1), 100),
        'sorts'        => [['propertyName' => 'lastmodifieddate', 'direction' => 'DESCENDING']],
    ]);
    if ($code < 200 || $code >= 300) {
        echo "  (sample fetch failed — HTTP $code)\n";
    } else {
        foreach (($json['results'] ?? []) as $r) {
            $props = $r['properties'] ?? [];
            $name  = trim((string)($props['firstname'] ?? '') . ' ' . (string)($props['lastname'] ?? ''));
            $vals  = [];
            foreach ($propNames as $pn) {
                $v = trim((string)($props[$pn] ?? ''));
                if ($v !== '') $vals[] = "$pn=\"$v\"";
            }
            echo "  id=" . (string)($r['id'] ?? '?') . "  " . ($name ?: '(no name)') . "  " . (empty($vals) ? '(no phone)' : implode('  ', $vals)) . "\n";
        }
    }
}

// --- The strategy matrix ----------------------------------------------------
if (!empty($numbers)) {
    $strategies = ['contains_last10', 'contains_last7', 'contains_last4', 'eq_e164', 'eq_last10', 'eq_raw'];
    echo "\n=== Search strategy matrix ===\n";
    echo "Testing " . count($numbers) . " number(s) x " . count($strategies) . " strategies.\n";

    foreach ($numbers as $num) {
        echo "\n--- INPUT: \"$num\"  (normalized digits: " . probe_norm_digits($num) . ") ---\n";
        foreach ($strategies as $strat) {
            $res = probe_search($hsAccess, $num, $phoneProperties, $strat);
            if (!$res['ok']) {
                echo sprintf("  %-16s ERROR http=%d %s\n", $strat, $res['http'], (string)($res['error'] ?? ''));
                continue;
            }
            $summary = [];
            foreach ($res['matches'] as $m) {
                $nm = trim($m['firstname'] . ' ' . $m['lastname']);
                $summary[] = ($nm !== '' ? $nm : '(no name)') . '#' . $m['id'];
            }
            $shown = array_slice($summary, 0, 5);
            $more  = count($summary) > 5 ? ' +' . (count($summary) - 5) . ' more' : '';
            echo sprintf("  %-16s [%-20s] count=%-3d %s%s\n",
                $strat, $res['query'], $res['count'],
                empty($shown) ? '(no matches)' : implode(', ', $shown), $more);
            if ($verbose) {
                foreach ($res['matches'] as $m) {
                    echo "        #{$m['id']}  " . trim($m['firstname'] . ' ' . $m['lastname']) . "  phones=" . json_encode($m['phones']) . "\n";
                }
            }
        }
    }
}

echo "\nDone.\n";
