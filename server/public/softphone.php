<?php
// server/public/softphone.php
//
// Hosted softphone page. The extension opens this in a window with a single-use
// ?code= (and the dial details). We exchange the code → client_id → PhoneBurner
// bearer token (PAT) SERVER-SIDE and embed the softphone iframe with ?token=
// appended. PhoneBurner's softphone authenticates from that token alone (no
// pb.com login / session cookie / CSRF) and forwards it on its own API calls.
//
// The token only ever appears in the iframe's src attribute (this page's DOM) —
// never in the top-window URL, history, or anything the extension passes around.
//
// softphone_host.js drives the dial over the postMessage contract (unchanged).

$code    = $_GET['code'] ?? '';
$runtime = $_GET['runtime'] ?? '';
$number  = (string)($_GET['number'] ?? '');
$crmId   = (string)($_GET['crm_id'] ?? '');
$crmName = (string)($_GET['crm_name'] ?? '');

// Every api.log line records REQUEST_URI as `path`, and ours carries the dialed
// ?number= (bootstrap's path scrub only masks code/token-style params). Params
// are read above, so drop the query BEFORE bootstrap wires any logging.
if (isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = explode('?', (string)$_SERVER['REQUEST_URI'], 2)[0];
}

// HTML page: bootstrap in NO_JSON mode (same as the OAuth finish pages) for
// api_log() + PHP error handlers. The CTC name lookup reaches call-logger
// helpers that call api_log() directly.
define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/api/core/bootstrap.php';
require_once __DIR__ . '/utils.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
// bootstrap sets Referrer-Policy: no-referrer. Restore the browser default so
// the PhoneBurner softphone iframe request carries the same Referer as before.
header('Referrer-Policy: strict-origin-when-cross-origin');

// Resolve the bearer token (server-side only).
//
// DEV/TEST override: SOFTPHONE_TEST_TOKEN in config.php forces a fixed token —
// needed locally because the per-user PAT is a PRODUCTION PhoneBurner token and
// won't authenticate against a local-dev PB. Set this ONLY in dev config.php;
// leave it unset in production so real per-user PATs are used.
$token = '';
$testToken = cfg()['SOFTPHONE_TEST_TOKEN'] ?? '';
$client_id = $code !== '' ? temp_code_retrieve_and_delete($code) : null; // consume the code regardless
if ($testToken !== '') {
    $token = $testToken;
} elseif ($client_id) {
    $pat = load_pb_token($client_id);
    if (!empty($pat)) {
        $token = $pat;
    }
}

// Resolve the contact's name so PhoneBurner can label the record it creates
// (pb-softphone:dial accepts first_name/last_name). CTC reads the NUMBER off the
// CRM page; the name is the only thing we fetch. Fail-open: the provider helpers
// never api_error(), so any failure just dials unnamed. Short timeout because
// the softphone page waits on this before rendering.
$firstName = '';
$lastName  = '';
if ($client_id && $crmId !== '' && $crmName !== '') {
    $lookupT0 = microtime(true);
    $name = null;
    $lookupError = false;
    // Catch-all: a token refresh inside the lookup persists via
    // atomic_write_json(), which throws on write failure. An optional name must
    // never stop the softphone from rendering (the code is already consumed).
    try {
        switch ($crmName) {
            case 'hubspot':
            case 'hubspotcompany':
                require_once __DIR__ . '/api/crm/hubspot/hs_helpers.php';
                $name = hs_ctc_lookup_name((string)$client_id, $crmName, $crmId);
                break;
            case 'forth':
                require_once __DIR__ . '/api/crm/forth/forth_helpers.php';
                $name = forth_ctc_lookup_name((string)$client_id, $crmId);
                break;
        }
    } catch (Throwable $e) {
        $name = null;
        $lookupError = get_class($e);
    }
    if (is_array($name)) {
        // Strip control chars + cap length; this lands in a postMessage payload
        // and PB's contact record, not just our page.
        $clean = function ($s) {
            $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s) ?? '');
            // Cap at 100 characters (not bytes) without depending on mbstring —
            // a byte cut could split a multibyte char and corrupt the name.
            return preg_match('/^.{0,100}/us', $s, $m) ? $m[0] : '';
        };
        $firstName = $clean($name['first_name'] ?? '');
        $lastName  = $clean($name['last_name'] ?? '');
    }
    // No names in the log (PII) — just whether we found one.
    _pb_write_api_log('softphone.name_lookup', [
        'client_id_hash' => substr(hash('sha256', (string)$client_id), 0, 12),
        'crm_name'       => $crmName,
        'found'          => ($firstName !== '' || $lastName !== ''),
        'exception'      => $lookupError, // class name only — message could carry paths/data
        'ms'             => (int) round((microtime(true) - $lookupT0) * 1000),
    ]);
}

// Only accept an http(s) runtime URL.
$runtimeOk = ($runtime !== '' && preg_match('#^https?://#i', $runtime) === 1);

// Build the iframe src with the token appended.
$iframeSrc = '';
$runtimeOrigin = '';
if ($runtimeOk) {
    $sep = (strpos($runtime, '?') !== false) ? '&' : '?';
    $iframeSrc = $runtime . ($token !== '' ? $sep . 'token=' . rawurlencode($token) : '');
    $p = parse_url($runtime);
    if (!empty($p['scheme']) && !empty($p['host'])) {
        $runtimeOrigin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }
}

// Config for the JS (no token here — only the origin + dial details).
$cfg = [
    'runtimeOrigin' => $runtimeOrigin,
    'number'        => $number,
    'crmId'         => $crmId,
    'crmName'       => $crmName,
    'firstName'     => $firstName,
    'lastName'      => $lastName,
    'authed'        => $token !== '',
];
?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>PhoneBurner Click-to-Call</title>
    <style>
      :root { --bg:#0b1220; --border:rgba(255,255,255,.12); --text:rgba(255,255,255,.92); --muted:rgba(255,255,255,.55); }
      * { box-sizing:border-box; }
      html,body { height:100%; }
      body { margin:0; display:flex; flex-direction:column; background:var(--bg); color:var(--text);
             font:13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
      .header { padding:8px 12px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex:0 0 auto; }
      .title { font-weight:800; }
      #sp-status { font-size:11px; color:var(--muted); border:1px solid var(--border); border-radius:999px; padding:2px 8px; white-space:nowrap; }
      .frame-wrap { flex:1 1 auto; min-height:220px; background:#000; }
      #sp-frame { width:100%; height:100%; border:0; display:block; }
      .controls { flex:0 0 auto; border-top:1px solid var(--border); padding:10px 12px; display:flex; flex-direction:column; gap:8px; }
      button { background:transparent; border:1px solid var(--border); color:var(--muted); border-radius:10px; padding:8px 12px; font:inherit; font-weight:600; cursor:pointer; }
      details summary { cursor:pointer; color:var(--muted); font-size:12px; }
      #sp-log { margin:6px 0 0; padding:8px; max-height:140px; overflow-y:auto; background:#0a0f1c; border:1px solid var(--border); border-radius:8px;
                font:11px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; color:var(--muted); white-space:pre-wrap; word-break:break-word; }
    </style>
  </head>
  <body>
    <div class="header">
      <span class="title">PhoneBurner Click-to-Call</span>
      <span id="sp-status">connecting…</span>
    </div>
    <div class="frame-wrap">
      <iframe id="sp-frame" allow="microphone; autoplay" title="PhoneBurner Softphone"
              src="<?= htmlspecialchars($iframeSrc, ENT_QUOTES) ?>"></iframe>
    </div>
    <div class="controls">
      <button id="sp-mic">🎤 Enable microphone for calls</button>
      <details>
        <summary>Event log</summary>
        <pre id="sp-log"></pre>
      </details>
    </div>
    <?php
      // JSON_HEX_* escapes < > & ' " so CRM-sourced names (or a crafted ?number=)
      // containing "</script>" can't break out of this inline script block.
      // INVALID_UTF8_SUBSTITUTE keeps a malformed byte from making json_encode
      // return false, which would emit "PB_SOFTPHONE = ;" and kill the dial.
      $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    ?>
    <script>window.PB_SOFTPHONE = <?= $cfgJson ?>;</script>
    <?php
      // Cache-bust softphone_host.js on every deploy so browsers immediately
      // pick up server-side JS changes without waiting for Chrome's heuristic
      // freshness to expire the cached copy.
      $spHostFile = __DIR__ . '/softphone_host.js';
      $spHostVer = is_file($spHostFile) ? filemtime($spHostFile) : time();
    ?>
    <script src="softphone_host.js?v=<?= $spHostVer ?>"></script>
  </body>
</html>
