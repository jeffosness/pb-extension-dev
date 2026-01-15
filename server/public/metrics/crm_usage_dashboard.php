<?php
// server/public/metrics/crm_usage_dashboard.php
//
// Dashboard that visualizes:
// - ../api/core/crm_usage_stats.php
// - ../api/core/sse_usage_stats.php
//
// Uses core/bootstrap.php for shared hardening/CORS/OPTIONS behavior.
// IMPORTANT: This page is HTML, not JSON, so we opt out of JSON headers.

define('PB_BOOTSTRAP_NO_JSON', true);
require_once __DIR__ . '/../api/core/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

api_log('crm_usage_dashboard.view', [
  'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 120) : null,
]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>CRM Usage Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #f5f5f7;
      margin: 0;
      padding: 0;
    }
    .container {
      max-width: 960px;
      margin: 40px auto;
      background: #fff;
      padding: 24px 28px 32px;
      border-radius: 8px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    h1 { margin-top: 0; margin-bottom: 8px; font-size: 28px; }
    p.lead { margin-top: 0; color: #555; }
    .alert {
      padding: 12px 16px;
      border-radius: 4px;
      margin: 16px 0;
      font-size: 14px;
    }
    .alert-danger { background: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
    .alert-warning { background: #fff3cd; color: #664d03; border: 1px solid #ffecb5; }
    .alert-info { background: #cff4fc; color: #055160; border: 1px solid #b6effb; }
    table { width: 100%; border-collapse: collapse; margin: 16px 0 24px; font-size: 14px; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: left; }
    th { background: #fafafa; font-weight: 600; }
    .muted { color: #777; font-size: 13px; }
    .section-title { margin-top: 24px; margin-bottom: 4px; font-size: 18px; }
    code { background: #f1f1f1; padding: 2px 4px; border-radius: 3px; font-size: 90%; }
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .stat {
      background: #fafafa;
      border: 1px solid #eee;
      border-radius: 6px;
      padding: 10px 12px;
      font-size: 14px;
    }
    .stat .label { color: #666; font-size: 12px; }
    .stat .value { font-size: 18px; font-weight: 700; margin-top: 4px; }
  </style>
</head>
<body>
<div class="container">
  <h1>CRM Usage Dashboard</h1>
  <p class="lead">
    CRM detection events logged by <code>track_crm_usage.php</code>, plus SSE “dial session load”
    stats from <code>sse.php</code>.
  </p>

  <div id="message"></div>

  <div id="content" style="display:none;">

    <h2 class="section-title">SSE Dial Session Load (Today)</h2>
    <div class="grid" id="sse-grid"></div>
    <div class="muted">
      Data source: <code>../api/core/sse_usage_stats.php</code>
    </div>

    <div class="alert alert-info" id="summary" style="margin-top:20px;"></div>

    <h2 class="section-title">By CRM ID</h2>
    <table id="by-crm-table">
      <thead><tr><th>CRM ID</th><th>Events</th></tr></thead>
      <tbody></tbody>
    </table>

    <h2 class="section-title">By Hostname</h2>
    <table id="by-host-table">
      <thead><tr><th>Host</th><th>Events</th></tr></thead>
      <tbody></tbody>
    </table>

    <h2 class="section-title">By Level</h2>
    <table id="by-level-table">
      <thead><tr><th>Level</th><th>Events</th></tr></thead>
      <tbody></tbody>
    </table>

    <p class="muted">
      Data source: <code>../api/core/crm_usage_stats.php</code>
    </p>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const msgEl = document.getElementById("message");
  const contentEl = document.getElementById("content");
  const summaryEl = document.getElementById("summary");
  const sseGridEl = document.getElementById("sse-grid");

  const crmEndpoint = "../api/core/crm_usage_stats.php";
  const sseEndpoint = "../api/core/sse_usage_stats.php";

  function showError(text) {
    msgEl.innerHTML =
      '<div class="alert alert-danger">Could not load dashboard data.<br>Details: ' +
      String(text) + "</div>";
    contentEl.style.display = "none";
  }

  function showWarning(text) {
    msgEl.innerHTML = '<div class="alert alert-warning">' + String(text) + "</div>";
    contentEl.style.display = "none";
  }

  function normalize(resp) {
    // Support BOTH formats:
    // - New bootstrap format: { ok:true, data:{...} }
    // - Legacy format: { ok:true, ...payload }
    return (resp && resp.data) ? resp.data : resp;
  }

  function statCard(label, value) {
    const div = document.createElement("div");
    div.className = "stat";
    div.innerHTML =
      '<div class="label">' + label + '</div>' +
      '<div class="value">' + value + '</div>';
    return div;
  }

  function secondsToFriendly(sec) {
    sec = Number(sec || 0);
    if (!sec) return "0s";
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = Math.floor(sec % 60);
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
  }

  function fillTable(tableId, obj) {
    const tbody = document.querySelector("#" + tableId + " tbody");
    tbody.innerHTML = "";
    if (!obj) return;

    Object.entries(obj)
      .sort((a, b) => b[1] - a[1])
      .forEach(([key, count]) => {
        const tr = document.createElement("tr");
        const tdKey = document.createElement("td");
        const tdCount = document.createElement("td");
        tdKey.textContent = key;
        tdCount.textContent = count;
        tr.appendChild(tdKey);
        tr.appendChild(tdCount);
        tbody.appendChild(tr);
      });
  }

  // Default to "today" for SSE stats (endpoint defaults to today anyway)
  const sseUrl = sseEndpoint + "?t=" + Date.now();
  const crmUrl = crmEndpoint + "?t=" + Date.now();

  Promise.all([
    fetch(sseUrl).then(r => { if (!r.ok) throw new Error("SSE stats HTTP " + r.status); return r.json(); }),
    fetch(crmUrl).then(r => { if (!r.ok) throw new Error("CRM stats HTTP " + r.status); return r.json(); })
  ]).then(([sseResp, crmResp]) => {
    const sse = normalize(sseResp);
    const crm = normalize(crmResp);

    if (!sseResp || !sseResp.ok || !sse) {
      showError("SSE stats API returned error or malformed response.");
      return;
    }
    if (!crmResp || !crmResp.ok || !crm) {
      showError("CRM stats API returned error or malformed response.");
      return;
    }

    // If CRM has no data yet, still show SSE section (it may have data)
    msgEl.innerHTML = "";
    contentEl.style.display = "block";

    // SSE Today summary
    const perDay = (sse.per_day && sse.per_day.length) ? sse.per_day[0] : null;
    const activeNow = (sse.active_now && typeof sse.active_now.active_now === "number")
      ? sse.active_now.active_now
      : 0;

    sseGridEl.innerHTML = "";
    sseGridEl.appendChild(statCard("Active dial sessions now (SSE)", String(activeNow)));
    sseGridEl.appendChild(statCard("Sessions started today", String(perDay ? perDay.connects : 0)));
    sseGridEl.appendChild(statCard("Sessions ended today", String(perDay ? perDay.disconnects : 0)));
    sseGridEl.appendChild(statCard("Avg session duration (ended)", secondsToFriendly(perDay ? perDay.avg_duration_sec : 0)));
    sseGridEl.appendChild(statCard("P95 duration (ended)", secondsToFriendly(perDay ? perDay.p95_duration_sec : 0)));
    sseGridEl.appendChild(statCard("Max concurrent estimate (today)", String(perDay ? perDay.max_concurrent_est : 0)));

    // CRM summary + tables
    if (!crm.total_events || crm.total_events === 0) {
      // Show SSE but warn CRM has no data
      summaryEl.textContent =
        "CRM usage: No usage data found yet. " +
        "SSE load is shown above.";
      return;
    }

    summaryEl.textContent =
      "CRM events logged: " + crm.total_events +
      " · Distinct CRMs: " + Object.keys(crm.by_crm_id || {}).length +
      " · Distinct hosts: " + Object.keys(crm.by_host || {}).length;

    fillTable("by-crm-table", crm.by_crm_id);
    fillTable("by-host-table", crm.by_host);
    fillTable("by-level-table", crm.by_level);

  }).catch((err) => {
    console.error("Dashboard load error:", err);
    showError(err.message || String(err));
  });
});
</script>
</body>
</html>
