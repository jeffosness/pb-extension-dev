// popup.js — DROP-IN (Unified Extension)

const BASE_URL = "https://extension-dev.phoneburner.biz";

function pbToText(x) {
  if (x == null) return "";
  if (typeof x === "string") return x;
  if (x instanceof Error) return x.message || String(x);
  try {
    return JSON.stringify(x, null, 2);
  } catch (e) {
    return String(x);
  }
}

function $(id) {
  return document.getElementById(id);
}

function sendToBackground(msg) {
  return new Promise((resolve) => {
    try {
      chrome.runtime.sendMessage(msg, (resp) => resolve(resp));
    } catch (e) {
      resolve({ ok: false, error: e?.message || String(e) });
    }
  });
}

function getClientIdFromBackground() {
  return new Promise((resolve) => {
    try {
      chrome.runtime.sendMessage({ type: "GET_CLIENT_ID" }, (resp) => {
        if (resp && resp.ok && resp.client_id) resolve(resp.client_id);
        else resolve(null);
      });
    } catch (e) {
      resolve(null);
    }
  });
}

// ---------------------------
// CRM header + panel toggles
// ---------------------------

let ACTIVE_CTX = null;

function setCrmHeader(context) {
  ACTIVE_CTX = context || null;

  const nameEl = $("crm-name");
  const levelEl = $("crm-level");
  const hsPanel = $("hubspot-panel");
  const scanBtn = $("scan-launch");

  if (nameEl) {
    const crmName = context?.crmName || context?.host || "Unknown";
    nameEl.textContent = crmName;
  }

  if (levelEl) {
    let levelLabel = "";
    switch (context?.level) {
      case 3:
        levelLabel = "(Level 3 – Full integration)";
        break;
      case 2:
        levelLabel = "(Level 2 – Optimized scraping)";
        break;
      case 1:
        levelLabel = "(Level 1 – Generic mode)";
        break;
      default:
        levelLabel = "(Unknown level)";
    }
    levelEl.textContent = levelLabel;
  }

  const isHubSpotL3 = context && context.crmId === "hubspot" && context.level === 3;

  // Show HubSpot panel only for HubSpot Level 3
  if (hsPanel) {
    hsPanel.style.display = isHubSpotL3 ? "block" : "none";
  }

  // Optional: prevent user from using generic scan button on HubSpot L3
  if (scanBtn) {
    scanBtn.disabled = !!isHubSpotL3;
    scanBtn.title = isHubSpotL3
      ? "Use 'Launch HubSpot Dial Session' for HubSpot."
      : "";
  }
}

// ---------------------------
// HubSpot helpers (server-facing)
// ---------------------------

async function hsPost(path, body = {}) {
  const clientId = await getClientIdFromBackground();

  const headers = { "Content-Type": "application/json" };
  if (clientId) headers["X-Client-Id"] = clientId;

  const res = await fetch(`${BASE_URL}/api/${path}`, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
    credentials: "include",
  });

  const json = await res.json().catch(() => ({}));
  return json;
}

async function refreshHubSpotState() {
  const panel = $("hubspot-panel");
  const statusEl = $("hs-status");
  const btn = $("btn-hs-connect");

  if (!panel || panel.style.display === "none") return;
  if (!statusEl || !btn) return;

  statusEl.textContent = "Checking HubSpot connection…";
  btn.disabled = true;

  let resp;
  try {
    resp = await hsPost("crm/hubspot/state.php");
  } catch (e) {
    console.error("HubSpot state error", e);
    statusEl.textContent = "Error checking HubSpot state.";
    btn.disabled = false;
    return;
  }

  const connected = !!(resp && resp.hs_ready);

  if (connected) {
    statusEl.textContent = "Connected to HubSpot ✔";
    btn.textContent = "Reconnect HubSpot";
  } else {
    statusEl.textContent = "Not connected to HubSpot";
    btn.textContent = "Connect HubSpot";
  }

  btn.disabled = false;
}

async function onHubSpotConnectClick() {
  const statusEl = $("hs-status");
  const btn = $("btn-hs-connect");
  if (!btn) return;

  if (statusEl) statusEl.textContent = "Starting HubSpot connection…";
  btn.disabled = true;

  try {
    const resp = await hsPost("crm/hubspot/oauth_hs_start.php");
    const authUrl = resp && resp.auth_url;

    if (!authUrl) {
      console.error("oauth_hs_start missing auth_url:", resp);
      alert("Server did not return auth_url. Check server logs.");
      if (statusEl) statusEl.textContent = "Error starting HubSpot OAuth.";
      btn.disabled = false;
      return;
    }

    chrome.tabs.create({ url: authUrl });
    window.close();
  } catch (e) {
    console.error("HubSpot connect error", e);
    alert("Error starting HubSpot connection: " + (e?.message || String(e)));
    if (statusEl) statusEl.textContent = "Error starting HubSpot OAuth.";
    btn.disabled = false;
  }
}

async function onHubSpotLaunchClick() {
  const statusEl = $("hs-status");
  const btnLaunch = $("btn-hs-launch");
  if (!btnLaunch) return;

  btnLaunch.disabled = true;
  if (statusEl) statusEl.textContent = "Preparing dial session…";

  try {
    // IMPORTANT: popup delegates everything to background.js for HubSpot.
    // background.js will:
    //  - ask content.js for selection
    //  - call server pb_dialsession_selection endpoint
    //  - open PB popup + register follow session
    const resp = await sendToBackground({ type: "HS_LAUNCH_FROM_SELECTED" });

    if (!resp || !resp.ok) {
      const msg = resp?.error || resp || "Could not launch from HubSpot selection.";
      alert(pbToText(msg));
      if (statusEl) statusEl.textContent = resp?.error || "Could not launch from HubSpot selection.";
      btnLaunch.disabled = false;
      return;
    }

    if (statusEl) statusEl.textContent = "Dial session created ✔";
    window.close();
  } catch (e) {
    console.error("HS launch error", e);
    alert("Error launching HubSpot dial session: " + (e?.message || String(e)));
    if (statusEl) statusEl.textContent = "Error launching HubSpot dial session.";
    btnLaunch.disabled = false;
  }
}

// ---------------------------
// PhoneBurner state / PAT
// ---------------------------

async function refreshState() {
  const stateEl = $("pb-status");
  const patInput = $("pat");
  const savePatBtn = $("save-pat");

  if (!stateEl || !patInput || !savePatBtn) return;

  stateEl.textContent = "Checking...";

  const resp = await sendToBackground({ type: "GET_STATE" });

  if (!resp || !resp.ok) {
    stateEl.textContent = "Error checking state";
    patInput.disabled = false;
    savePatBtn.textContent = "Save PAT";
    savePatBtn.dataset.mode = "save";
    return;
  }

  const connected = !!(resp.phoneburner && resp.phoneburner.connected);

  if (connected) {
    stateEl.textContent = "Connected to PhoneBurner ✔";
    patInput.value = "";
    patInput.placeholder = "PAT saved";
    patInput.disabled = true;
    savePatBtn.textContent = "Disconnect";
    savePatBtn.dataset.mode = "disconnect";
  } else {
    stateEl.textContent = "Not connected to PhoneBurner";
    patInput.disabled = false;
    patInput.placeholder = "Paste PAT here";
    savePatBtn.textContent = "Save PAT";
    savePatBtn.dataset.mode = "save";
  }
}

async function savePAT() {
  const patInput = $("pat");
  if (!patInput) return;

  const pat = patInput.value.trim();
  if (!pat) {
    alert("Please paste your PAT first.");
    return;
  }

  const resp = await sendToBackground({ type: "SAVE_PAT", pat });
  if (resp && resp.ok) {
    alert("PAT saved.");
    patInput.value = "";
    await refreshState();
  } else {
    const msg =
      (resp && (resp.error || resp.details)) ||
      JSON.stringify(resp || {}) ||
      "Unknown error";
    alert("Error saving PAT: " + msg);
  }
}

async function disconnectPAT() {
  const confirmed = confirm("Disconnect from PhoneBurner and clear your PAT?");
  if (!confirmed) return;

  const resp = await sendToBackground({ type: "CLEAR_PAT" });
  if (resp && resp.ok) {
    alert("Disconnected from PhoneBurner.");
    await refreshState();
  } else {
    const msg =
      (resp && (resp.error || resp.details)) ||
      JSON.stringify(resp || {}) ||
      "Unknown error";
    alert("Error disconnecting: " + msg);
  }
}

function onPatButtonClick() {
  const btn = $("save-pat");
  if (!btn) return;
  const mode = btn.dataset.mode || "save";
  if (mode === "disconnect") disconnectPAT();
  else savePAT();
}

// ---------------------------
// Level 1/2: Scan & Launch
// ---------------------------

async function scanAndLaunch() {
  // If HubSpot L3, prevent generic scan flow.
  const isHubSpotL3 = ACTIVE_CTX && ACTIVE_CTX.crmId === "hubspot" && ACTIVE_CTX.level === 3;
  if (isHubSpotL3) {
    alert("On HubSpot, use 'Launch HubSpot Dial Session' (Level 3).");
    return;
  }

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab || !tab.id) {
    alert("No active tab found.");
    return;
  }

  console.log("[PB-UNIFIED] popup: sending SCAN_PAGE to tab", tab.id);

  chrome.tabs.sendMessage(tab.id, { type: "SCAN_PAGE" }, (resp) => {
    if (chrome.runtime.lastError) {
      console.error("[PB-UNIFIED] popup: SCAN_PAGE lastError", chrome.runtime.lastError);
      alert("Could not scan this page. Is the content script loaded?");
      return;
    }

    console.log("[PB-UNIFIED] popup: SCAN_PAGE response:", resp);

    if (!resp) {
      alert(
        "Scan request sent but no response from the page.\n" +
          "Open DevTools (F12) on the page and check for red errors in the console."
      );
      return;
    }

    if (resp && resp.ok) {
      console.log("[PB-UNIFIED] popup: scan started OK");
    } else {
      console.warn("[PB-UNIFIED] popup: scan reported not-ok:", resp);
    }
  });
}

// ---------------------------
// Goals (primary / secondary)
// ---------------------------

function loadGoalsFromStorage() {
  const primaryInput = $("goal-primary");
  const secondaryInput = $("goal-secondary");
  const statusEl = $("goals-status");

  if (!primaryInput || !secondaryInput || !statusEl) return;

  chrome.storage.local.get(["pb_goal_primary", "pb_goal_secondary"], (res) => {
    const primary = res.pb_goal_primary || "Set Appointment";
    const secondary = res.pb_goal_secondary || "Follow Up";

    primaryInput.value = primary;
    secondaryInput.value = secondary;

    statusEl.textContent =
      'Goals saved: Primary = "' + primary + '", Secondary = "' + secondary + '"';
  });
}

async function saveGoals() {
  const primaryInput = $("goal-primary");
  const secondaryInput = $("goal-secondary");
  const statusEl = $("goals-status");

  if (!primaryInput || !secondaryInput || !statusEl) return;

  const primary = primaryInput.value.trim() || "Set Appointment";
  const secondary = secondaryInput.value.trim() || "Follow Up";

  chrome.storage.local.set({ pb_goal_primary: primary, pb_goal_secondary: secondary }, () => {
    statusEl.textContent =
      'Goals saved: Primary = "' + primary + '", Secondary = "' + secondary + '"';
  });

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (tab && tab.id) {
    chrome.tabs.sendMessage(tab.id, { type: "PB_GOAL_UPDATED", primary, secondary }, () => {});
  }
}

// ---------------------------
// Tabs
// ---------------------------

function activateTab(tabName) {
  const dialBtn = $("tab-dial");
  const settingsBtn = $("tab-settings");
  const dialPanel = $("panel-dial");
  const settingsPanel = $("panel-settings");

  if (!dialBtn || !settingsBtn || !dialPanel || !settingsPanel) return;

  if (tabName === "settings") {
    dialBtn.classList.remove("active");
    settingsBtn.classList.add("active");
    dialPanel.classList.remove("active");
    settingsPanel.classList.add("active");
  } else {
    dialBtn.classList.add("active");
    settingsBtn.classList.remove("active");
    dialPanel.classList.add("active");
    settingsPanel.classList.remove("active");
  }
}

// ---------------------------
// Init
// ---------------------------

document.addEventListener("DOMContentLoaded", () => {
  // Wire tab buttons
  const tabDial = $("tab-dial");
  const tabSettings = $("tab-settings");
  if (tabDial) tabDial.addEventListener("click", () => activateTab("dial"));
  if (tabSettings) tabSettings.addEventListener("click", () => activateTab("settings"));

  // PAT buttons
  const patBtn = $("save-pat");
  if (patBtn) patBtn.addEventListener("click", onPatButtonClick);

  // Scan & Launch (Level 1/2)
  const scanBtn = $("scan-launch");
  if (scanBtn) scanBtn.addEventListener("click", scanAndLaunch);

  // Save Goals
  const saveGoalsBtn = $("save-goals");
  if (saveGoalsBtn) saveGoalsBtn.addEventListener("click", saveGoals);

  // HubSpot buttons
  const hsBtn = $("btn-hs-connect");
  if (hsBtn) hsBtn.addEventListener("click", onHubSpotConnectClick);

  const hsLaunchBtn = $("btn-hs-launch");
  if (hsLaunchBtn) hsLaunchBtn.addEventListener("click", onHubSpotLaunchClick);

  // Ask background what CRM/level this tab is
  chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    const activeTab = tabs && tabs[0];
    if (!activeTab) {
      setCrmHeader(null);
      return;
    }

    chrome.runtime.sendMessage({ type: "GET_CONTEXT", tabId: activeTab.id }, (resp) => {
      if (chrome.runtime.lastError) {
        setCrmHeader(null);
        return;
      }

      const ctx = resp && resp.context ? resp.context : null;
      setCrmHeader(ctx);

      // If HubSpot L3, refresh HubSpot connection state
      if (ctx && ctx.crmId === "hubspot" && ctx.level === 3) {
        refreshHubSpotState();
      }
    });
  });

  // Initial load state
  refreshState();
  loadGoalsFromStorage();
});
