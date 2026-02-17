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
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      margin: 16px 0 24px;
      font-size: 14px;
      background: #fff;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      overflow: hidden;
    }
    th {
      background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);
      font-weight: 600;
      padding: 12px 16px;
      text-align: left;
      border-bottom: 2px solid #dee2e6;
      color: #495057;
    }
    td {
      padding: 10px 16px;
      border-bottom: 1px solid #f0f0f0;
    }
    tr:last-child td {
      border-bottom: none;
    }
    tbody tr:nth-child(even) td {
      background: #fafbfc;
    }
    tr:hover td {
      background: #f0f3f5;
    }
    tbody tr:nth-child(even):hover td {
      background: #e8ecef;
    }
    .muted { color: #777; font-size: 13px; }
    .section-title {
      margin-top: 32px;
      margin-bottom: 12px;
      font-size: 20px;
      font-weight: 700;
      color: #1a1a1a;
      border-bottom: 2px solid #e0e0e0;
      padding-bottom: 8px;
    }
    code { background: #f1f1f1; padding: 2px 4px; border-radius: 3px; font-size: 90%; }
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .stat {
      background: linear-gradient(135deg, #fafafa 0%, #ffffff 100%);
      border: 1px solid #e0e0e0;
      border-radius: 8px;
      padding: 16px;
      font-size: 14px;
      transition: all 0.2s ease;
      cursor: help;
    }
    .stat:hover {
      border-color: #0066cc;
      box-shadow: 0 4px 12px rgba(0, 102, 204, 0.1);
      transform: translateY(-2px);
    }
    .stat .label {
      color: #666;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .stat .value {
      font-size: 24px;
      font-weight: 700;
      margin-top: 8px;
      color: #1a1a1a;
    }
    .filter-buttons {
      display: flex;
      gap: 8px;
      margin: 16px 0;
    }
    .filter-btn {
      padding: 8px 16px;
      border-radius: 6px;
      border: 2px solid #e0e0e0;
      background: #fff;
      cursor: pointer;
      font-weight: 600;
      font-size: 13px;
      transition: all 0.2s ease;
    }
    .filter-btn:hover {
      background: #f5f5f5;
      border-color: #c0c0c0;
    }
    .filter-btn.active {
      background: #0066cc;
      color: #fff;
      border-color: #0052a3;
      box-shadow: 0 2px 6px rgba(0, 102, 204, 0.2);
    }
    .filter-btn.active:hover {
      background: #0052a3;
      box-shadow: 0 3px 8px rgba(0, 102, 204, 0.3);
    }
    /* Responsive */
    @media (max-width: 768px) {
      .grid {
        grid-template-columns: 1fr;
      }
      .stat .value {
        font-size: 20px;
      }
    }
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

    <div class="filter-buttons">
      <button class="filter-btn active" data-range="today">Today</button>
      <button class="filter-btn" data-range="week">This Week</button>
      <button class="filter-btn" data-range="month">This Month</button>
    </div>

    <h2 class="section-title">SSE Dial Session Load</h2>
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

    <h2 class="section-title">Dial Session Sources</h2>
    <div class="grid" id="launch-grid"></div>

    <table id="by-launch-source-table">
      <thead><tr><th>Launch Source</th><th>Events</th></tr></thead>
      <tbody></tbody>
    </table>

    <h2 class="section-title">By Object Type</h2>
    <table id="by-object-type-table">
      <thead><tr><th>Object Type</th><th>Events</th></tr></thead>
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
  const filterBtns = document.querySelectorAll(".filter-btn");

  const crmEndpoint = "../api/core/crm_usage_stats.php";
  const sseEndpoint = "../api/core/sse_usage_stats.php";

  let currentRange = "today";

  function getDateRange(range) {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, "0");
    const day = String(today.getDate()).padStart(2, "0");
    const todayStr = `${year}-${month}-${day}`;

    if (range === "today") {
      return { start: todayStr, end: todayStr, label: "Today" };
    }

    if (range === "week") {
      const startOfWeek = new Date(today);
      startOfWeek.setDate(today.getDate() - today.getDay());
      const startYear = startOfWeek.getFullYear();
      const startMonth = String(startOfWeek.getMonth() + 1).padStart(2, "0");
      const startDay = String(startOfWeek.getDate()).padStart(2, "0");
      const startStr = `${startYear}-${startMonth}-${startDay}`;
      return { start: startStr, end: todayStr, label: "This Week" };
    }

    if (range === "month") {
      const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
      const startYear = startOfMonth.getFullYear();
      const startMonth = String(startOfMonth.getMonth() + 1).padStart(2, "0");
      const startDay = String(startOfMonth.getDate()).padStart(2, "0");
      const startStr = `${startYear}-${startMonth}-${startDay}`;
      return { start: startStr, end: todayStr, label: "This Month" };
    }

    return { start: todayStr, end: todayStr, label: "Today" };
  }

  function loadDashboard() {
    const dateRange = getDateRange(currentRange);
    const sseUrl = sseEndpoint + `?start=${dateRange.start}&end=${dateRange.end}&t=${Date.now()}`;
    const crmUrl = crmEndpoint + `?start=${dateRange.start}&end=${dateRange.end}&t=${Date.now()}`;

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

      msgEl.innerHTML = "";
      contentEl.style.display = "block";

      // Calculate totals from per_day data
      let totalConnects = 0, totalDisconnects = 0, totalMaxConcurrent = 0;
      let totalDurationSum = 0, totalSessionCount = 0;
      const perDays = sse.per_day || [];

      if (perDays.length > 0) {
        totalConnects = perDays.reduce((sum, d) => sum + (d.connects || 0), 0);
        totalDisconnects = perDays.reduce((sum, d) => sum + (d.disconnects || 0), 0);

        // Calculate weighted average: (sum of day_avg × day_sessions) / total_sessions
        perDays.forEach(d => {
          if (d.avg_duration_sec > 0 && d.disconnects > 0) {
            totalDurationSum += d.avg_duration_sec * d.disconnects;
            totalSessionCount += d.disconnects;
          }
        });

        totalMaxConcurrent = Math.max(...perDays.map(d => d.max_concurrent_est || 0), 0);
      }

      const totalAvgDur = totalSessionCount > 0
        ? Math.round(totalDurationSum / totalSessionCount)
        : 0;

      // P95: Use overall_p95 from backend if available, otherwise use max of daily P95s
      const p95s = perDays.filter(d => d.p95_duration_sec > 0).map(d => d.p95_duration_sec);
      const totalP95Dur = sse.overall_p95 || (p95s.length > 0 ? Math.max(...p95s) : 0);

      const activeNow = (sse.active_now && typeof sse.active_now.active_now === "number")
        ? sse.active_now.active_now
        : 0;

      const uniqueUsers = (sse.totals && typeof sse.totals.unique_users === "number")
          ? sse.totals.unique_users : 0;

      sseGridEl.innerHTML = "";
      sseGridEl.appendChild(statCard("Active dial sessions now (SSE)", String(activeNow)));
      sseGridEl.appendChild(statCard("Max concurrent estimate", String(totalMaxConcurrent)));
      sseGridEl.appendChild(statCard("Sessions started (" + dateRange.label + ")", String(totalConnects)));
      sseGridEl.appendChild(statCard("Sessions ended (" + dateRange.label + ")", String(totalDisconnects)));
      sseGridEl.appendChild(statCard("Avg session duration", secondsToFriendly(totalAvgDur)));
      const p95Card = statCard("P95 duration (95th percentile)", secondsToFriendly(totalP95Dur));
      p95Card.setAttribute('title', '95% of sessions lasted this long or less');
      sseGridEl.appendChild(p95Card);
      sseGridEl.appendChild(statCard("Unique users (" + dateRange.label + ")", String(uniqueUsers)));

      // CRM summary + tables
      if (!crm.total_events || crm.total_events === 0) {
        summaryEl.textContent =
          "CRM usage (" + dateRange.label + "): No usage data found yet. SSE load is shown above.";
        return;
      }

      summaryEl.textContent =
        "CRM events logged (" + dateRange.label + "): " + crm.total_events +
        " · Distinct CRMs: " + Object.keys(crm.by_crm_id || {}).length +
        " · Distinct hosts: " + Object.keys(crm.by_host || {}).length;

      fillTable("by-crm-table", crm.by_crm_id);
      fillTable("by-host-table", crm.by_host);
      fillTable("by-level-table", crm.by_level);

      // Launch source breakdown
      const launchGridEl = document.getElementById("launch-grid");
      const src = crm.by_launch_source || {};
      const objType = crm.by_object_type || {};
      const hasLaunchData = Object.keys(src).length > 0 || Object.keys(objType).length > 0;

      if (launchGridEl) {
        launchGridEl.innerHTML = "";
        if (hasLaunchData) {
          const totalLaunches = Object.values(src).reduce((a, b) => a + b, 0);
          launchGridEl.appendChild(statCard("Total launches (" + dateRange.label + ")", String(totalLaunches)));

          let dominant = "None";
          if (totalLaunches > 0) {
            dominant = Object.entries(src).sort((a, b) => b[1] - a[1])[0][0];
            dominant = friendlyLaunchSource(dominant);
          }
          launchGridEl.appendChild(statCard("Most common launch method", dominant));
        }
      }

      fillTableFriendly("by-launch-source-table", crm.by_launch_source, friendlyLaunchSource);
      fillTableFriendly("by-object-type-table", crm.by_object_type, friendlyObjectType);

    }).catch((err) => {
      console.error("Dashboard load error:", err);
      showError(err.message || String(err));
    });
  }

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

  function fillTableFriendly(tableId, obj, labelFn) {
    const tbody = document.querySelector("#" + tableId + " tbody");
    tbody.innerHTML = "";
    if (!obj) return;

    Object.entries(obj)
      .sort((a, b) => b[1] - a[1])
      .forEach(([key, count]) => {
        const tr = document.createElement("tr");
        const tdKey = document.createElement("td");
        const tdCount = document.createElement("td");
        tdKey.textContent = labelFn ? labelFn(key) : key;
        tdCount.textContent = count;
        tr.appendChild(tdKey);
        tr.appendChild(tdCount);
        tbody.appendChild(tr);
      });
  }

  function friendlyLaunchSource(key) {
    const map = {
      "selection": "Selection (record list)",
      "list": "List (HubSpot list)",
      "scan": "Scan (page scrape)",
    };
    return map[key] || key;
  }

  function friendlyObjectType(key) {
    const map = {
      "contacts": "Contacts",
      "companies": "Companies",
      "deals": "Deals",
    };
    return map[key] || key;
  }

  // Filter button listeners
  filterBtns.forEach(btn => {
    btn.addEventListener("click", () => {
      filterBtns.forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
      currentRange = btn.getAttribute("data-range");
      loadDashboard();
    });
  });

  // Initial load
  loadDashboard();
});
</script>
</body>
</html>
