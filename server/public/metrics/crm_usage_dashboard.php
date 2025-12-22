<?php
// unified_crm/metrics/crm_usage_dashboard.php
//
// Simple dashboard that visualizes data from
// ../api/core/crm_usage_stats.php

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>CRM Usage Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Tiny bit of Bootstrap-ish styling without requiring the lib -->
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
    h1 {
      margin-top: 0;
      margin-bottom: 8px;
      font-size: 28px;
    }
    p.lead {
      margin-top: 0;
      color: #555;
    }
    .alert {
      padding: 12px 16px;
      border-radius: 4px;
      margin: 16px 0;
      font-size: 14px;
    }
    .alert-danger {
      background: #f8d7da;
      color: #842029;
      border: 1px solid #f5c2c7;
    }
    .alert-warning {
      background: #fff3cd;
      color: #664d03;
      border: 1px solid #ffecb5;
    }
    .alert-info {
      background: #cff4fc;
      color: #055160;
      border: 1px solid #b6effb;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin: 16px 0 24px;
      font-size: 14px;
    }
    th, td {
      padding: 6px 8px;
      border-bottom: 1px solid #eee;
      text-align: left;
    }
    th {
      background: #fafafa;
      font-weight: 600;
    }
    .muted {
      color: #777;
      font-size: 13px;
    }
    .section-title {
      margin-top: 24px;
      margin-bottom: 4px;
      font-size: 18px;
    }
    code {
      background: #f1f1f1;
      padding: 2px 4px;
      border-radius: 3px;
      font-size: 90%;
    }
  </style>
</head>
<body>
<div class="container">
  <h1>CRM Usage Dashboard</h1>
  <p class="lead">
    Shows how often the unified extension has detected each CRM, based on events
    logged by <code>track_crm_usage.php</code>.
  </p>

  <div id="message"></div>

  <div id="content" style="display:none;">
    <div class="alert alert-info" id="summary"></div>

    <h2 class="section-title">By CRM ID</h2>
    <table id="by-crm-table">
      <thead>
      <tr><th>CRM ID</th><th>Events</th></tr>
      </thead>
      <tbody></tbody>
    </table>

    <h2 class="section-title">By Hostname</h2>
    <table id="by-host-table">
      <thead>
      <tr><th>Host</th><th>Events</th></tr>
      </thead>
      <tbody></tbody>
    </table>

    <h2 class="section-title">By Level</h2>
    <table id="by-level-table">
      <thead>
      <tr><th>Level</th><th>Events</th></tr>
      </thead>
      <tbody></tbody>
    </table>

    <p class="muted">
      Data source: <code>../api/core/crm_usage_stats.php</code>
    </p>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const endpoint = "../api/core/crm_usage_stats.php";
  const msgEl = document.getElementById("message");
  const contentEl = document.getElementById("content");
  const summaryEl = document.getElementById("summary");

  function showError(text) {
    msgEl.innerHTML =
      '<div class="alert alert-danger">Could not load stats from ' +
      endpoint +
      '<br>Details: ' + text + "</div>";
    contentEl.style.display = "none";
  }

  function showWarning(text) {
    msgEl.innerHTML =
      '<div class="alert alert-warning">' + text + "</div>";
    contentEl.style.display = "none";
  }

  fetch(endpoint)
    .then((resp) => {
      if (!resp.ok) {
        throw new Error("HTTP " + resp.status);
      }
      return resp.json();
    })
    .then((data) => {
      console.log("crm_usage_stats:", data);

      if (!data || !data.ok) {
        showError("API returned error or malformed response.");
        return;
      }

      if (!data.total_events || data.total_events === 0) {
        showWarning("No usage data found yet.");
        return;
      }

      msgEl.innerHTML = "";
      contentEl.style.display = "block";

      // Summary
      summaryEl.textContent =
        "Total events logged: " + data.total_events +
        " · Distinct CRMs: " + Object.keys(data.by_crm_id || {}).length +
        " · Distinct hosts: " + Object.keys(data.by_host || {}).length;

      // Helper to fill a table from an object map
      function fillTable(tableId, obj, keyLabel) {
        const tbody = document.querySelector("#" + tableId + " tbody");
        tbody.innerHTML = "";

        if (!obj) return;

        const rows = Object.entries(obj)
          .sort((a, b) => b[1] - a[1]); // sort descending by count

        rows.forEach(([key, count]) => {
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

      fillTable("by-crm-table", data.by_crm_id, "CRM");
      fillTable("by-host-table", data.by_host, "Host");
      fillTable("by-level-table", data.by_level, "Level");
    })
    .catch((err) => {
      console.error("Error loading crm_usage_stats:", err);
      showError(err.message || String(err));
    });
});
</script>
</body>
</html>
