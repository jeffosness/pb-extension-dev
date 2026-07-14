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

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$code    = $_GET['code'] ?? '';
$runtime = $_GET['runtime'] ?? '';
$number  = (string)($_GET['number'] ?? '');
$crmId   = (string)($_GET['crm_id'] ?? '');
$crmName = (string)($_GET['crm_name'] ?? '');

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
    <script>window.PB_SOFTPHONE = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES) ?>;</script>
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
