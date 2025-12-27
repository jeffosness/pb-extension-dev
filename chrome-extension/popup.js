// popup.js — DROP-IN
const BASE_URL = "https://extension-dev.phoneburner.biz";

function $(id) { return document.getElementById(id); }
function setVisible(el, isVisible) { if (el) el.classList.toggle("hidden", !isVisible); }

function sendToBackground(msg) {
  return new Promise((resolve) => {
    try { chrome.runtime.sendMessage(msg, (resp) => resolve(resp)); }
    catch (e) { resolve({ ok: false, error: e?.message || String(e) }); }
  });
}

function getClientIdFromBackground() {
  return new Promise((resolve) => {
    try {
      chrome.runtime.sendMessage({ type: "GET_CLIENT_ID" }, (resp) => {
        if (resp && resp.ok && resp.client_id) resolve(resp.client_id);
        else resolve(null);
      });
    } catch (e) { resolve(null); }
  });
}

// ---------------------------
// CRM header + Level UI toggles
// ---------------------------

let ACTIVE_CTX = null;

function setCrmHeader(context) {
  ACTIVE_CTX = context || null;

  const nameEl = $("crm-name");
  const levelEl = $("crm-level");

  if (nameEl) nameEl.textContent = context?.crmName || context?.host || "Unknown";

  if (levelEl) {
    const lvl = context?.level;
    levelEl.textContent =
      lvl === 3 ? "Level 3 – Full integration" :
      lvl === 2 ? "Level 2 – Optimized scraping" :
      lvl === 1 ? "Level 1 – Generic mode" :
      "Unknown level";
  }

  applyLevelUi(context);
}

function isHubSpotL3(ctx) {
  return !!(ctx && ctx.crmId === "hubspot" && ctx.level === 3);
}

function applyLevelUi(context) {
  const currentPageCard = $("card-current-page");
  const hsDialCard = $("hubspot-dial-card");

  const level = context?.level || 0;
  const showLevel3 = level === 3;

  // Hide current page on Level 3
  setVisible(currentPageCard, !showLevel3);

  // Show HubSpot card only for HubSpot L3
  setVisible(hsDialCard, isHubSpotL3(context));
}

// ---------------------------
// HubSpot server helpers
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

  return await res.json().catch(() => ({}));
}

async function refreshHubSpotUi() {
  if (!isHubSpotL3(ACTIVE_CTX)) return;

  const dialStatus = $("hs-dial-status");
  const dialHelp = $("hs-dial-help");
  const dialAction = $("hs-dial-action");

  const settingsCard = $("hubspot-settings-card");
  const settingsStatus = $("hs-settings-status");
  const disconnectBtn = $("hs-disconnect");

  if (dialStatus) dialStatus.textContent = "Checking HubSpot connection…";
  if (dialHelp) dialHelp.textContent = "";
  if (dialAction) { dialAction.disabled = true; dialAction.textContent = "Loading…"; }

  let state;
  try {
    state = await hsPost("crm/hubspot/state.php");
  } catch (e) {
    if (dialStatus) dialStatus.textContent = "Error checking HubSpot connection.";
    if (dialAction) { dialAction.disabled = false; dialAction.textContent = "Retry"; }
    setVisible(settingsCard, false);
    return;
  }

  const connected = !!state?.hs_ready;

  // DIAL TAB UI
  if (connected) {
    if (dialStatus) dialStatus.textContent = "Connected to HubSpot ✔";
    if (dialHelp) dialHelp.textContent = "Launch a dial session from the currently selected records.";
    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Launch HubSpot Dial Session";
      dialAction.dataset.mode = "launch";
    }
  } else {
    if (dialStatus) dialStatus.textContent = "Not connected to HubSpot";
    if (dialHelp) dialHelp.textContent = "Connect HubSpot to enable API-based selection + call logging.";
    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Connect HubSpot";
      dialAction.dataset.mode = "connect";
    }
  }

  // SETTINGS TAB UI (only show when connected)
  setVisible(settingsCard, connected);
  if (connected) {
    if (settingsStatus) settingsStatus.textContent = "Connected ✔";
    if (disconnectBtn) disconnectBtn.disabled = false;
  }
}

async function startHubSpotOAuth() {
  const dialStatus = $("hs-dial-status");
  const dialAction = $("hs-dial-action");

  if (dialStatus) dialStatus.textContent = "Starting HubSpot connection…";
  if (dialAction) dialAction.disabled = true;

  const resp = await hsPost("crm/hubspot/oauth_hs_start.php");
  const authUrl = resp?.auth_url;

  if (!authUrl) {
    if (dialStatus) dialStatus.textContent = "Could not start HubSpot OAuth (missing auth_url).";
    if (dialAction) dialAction.disabled = false;
    alert("Server did not return auth_url. Check server logs.");
    return;
  }

  chrome.tabs.create({ url: authUrl });
  window.close();
}

async function launchHubSpotDialSession() {
  const dialStatus = $("hs-dial-status");
  const dialAction = $("hs-dial-action");

  if (dialAction) dialAction.disabled = true;
  if (dialStatus) dialStatus.textContent = "Preparing dial session…";

  const resp = await sendToBackground({ type: "HS_LAUNCH_FROM_SELECTED" });

  if (!resp || !resp.ok) {
    if (dialStatus) dialStatus.textContent = resp?.error || "Could not launch from selection.";
    if (dialAction) dialAction.disabled = false;
    alert(resp?.error || "Could not launch HubSpot dial session.");
    return;
  }

  if (dialStatus) dialStatus.textContent = "Dial session created ✔";
  window.close();
}

async function disconnectHubSpot() {
  const confirmed = confirm("Disconnect HubSpot for this session?");
  if (!confirmed) return;

  const btn = $("hs-disconnect");
  const settingsStatus = $("hs-settings-status");
  if (btn) btn.disabled = true;
  if (settingsStatus) settingsStatus.textContent = "Disconnecting…";

  const resp = await hsPost("crm/hubspot/oauth_disconnect.php", { provider: "hs" });

  if (!resp || resp.ok !== true) {
    if (settingsStatus) settingsStatus.textContent = "Failed to disconnect.";
    if (btn) btn.disabled = false;
    alert(resp?.error || "Failed to disconnect HubSpot.");
    return;
  }

  await refreshHubSpotUi();
  activateTab("dial");
}

// ---------------------------
// PhoneBurner PAT UI
// ---------------------------
function applyGetPbUi(isConnected) {
  const card = $("get-pb-card");
  setVisible(card, !isConnected);
}

function applyPatUi(isConnected) {
  const pbStatus = $("pb-status");
  const patCardDial = $("pat-card-dial");
  const patCardSettings = $("pat-card-settings");
  if (pbStatus) pbStatus.textContent = isConnected ? "Connected ✔" : "Not connected";
  setVisible(patCardDial, !isConnected);
  setVisible(patCardSettings, isConnected);
  applyGetPbUi(isConnected);
}

async function refreshState() {
  const resp = await sendToBackground({ type: "GET_STATE" });
  if (!resp || resp.ok !== true) return applyPatUi(false);
  applyPatUi(!!resp?.phoneburner?.connected);
}

async function savePAT() {
  const patInput = $("pat");
  const btn = $("save-pat");
  const pat = patInput?.value?.trim();
  if (!pat) return alert("Please paste your PAT first.");
  if (btn) btn.disabled = true;

  const resp = await sendToBackground({ type: "SAVE_PAT", pat });
  if (resp && resp.ok) {
    alert("PAT saved.");
    await refreshState();
    activateTab("settings");
  } else {
    alert("Error saving PAT: " + (resp?.error || "Unknown error"));
    if (btn) btn.disabled = false;
  }
}

async function disconnectPAT() {
  const confirmed = confirm("Disconnect from PhoneBurner and clear your PAT?");
  if (!confirmed) return;
  const btn = $("disconnect-pat");
  if (btn) btn.disabled = true;

  const resp = await sendToBackground({ type: "CLEAR_PAT" });
  if (resp && resp.ok) {
    alert("Disconnected from PhoneBurner.");
    await refreshState();
    activateTab("dial");
  } else {
    alert("Error disconnecting: " + (resp?.error || "Unknown error"));
    if (btn) btn.disabled = false;
  }
}

// ---------------------------
// Scan & Launch (Level 1/2 only)
// ---------------------------

async function scanAndLaunch() {
  if (ACTIVE_CTX?.level === 3) return; // hidden anyway
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id) return alert("No active tab found.");
  chrome.tabs.sendMessage(tab.id, { type: "SCAN_PAGE" }, () => {
    if (chrome.runtime.lastError) alert("Could not scan this page. Is the content script loaded?");
  });
}

// ---------------------------
// Goals
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
    statusEl.textContent = `Goals saved: Primary="${primary}", Secondary="${secondary}"`;
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
    statusEl.textContent = `Goals saved: Primary="${primary}", Secondary="${secondary}"`;
  });

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (tab?.id) chrome.tabs.sendMessage(tab.id, { type: "PB_GOAL_UPDATED", primary, secondary }, () => {});
}

// ---------------------------
// Follow widget preferences
// ---------------------------

const FOLLOW_AUTO_COLLAPSE_KEY = "pb_follow_widget_auto_collapse";

function loadFollowWidgetPrefs() {
  const cb = $("follow-auto-collapse");
  if (!cb) return;

  chrome.storage.local.get([FOLLOW_AUTO_COLLAPSE_KEY], (res) => {
    // Default ON
    const val = res && Object.prototype.hasOwnProperty.call(res, FOLLOW_AUTO_COLLAPSE_KEY)
      ? !!res[FOLLOW_AUTO_COLLAPSE_KEY]
      : true;

    cb.checked = val;
  });

  cb.addEventListener("change", () => {
    chrome.storage.local.set({ [FOLLOW_AUTO_COLLAPSE_KEY]: !!cb.checked });
  });
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

  const isSettings = tabName === "settings";
  dialBtn.classList.toggle("active", !isSettings);
  settingsBtn.classList.toggle("active", isSettings);
  dialPanel.classList.toggle("active", !isSettings);
  settingsPanel.classList.toggle("active", isSettings);
}

// ---------------------------
// Init
// ---------------------------

document.addEventListener("DOMContentLoaded", () => {
  // PAT
  $("save-pat")?.addEventListener("click", savePAT);
  $("disconnect-pat")?.addEventListener("click", disconnectPAT);

  // Get PhoneBurner CTA
  $("get-pb-btn")?.addEventListener("click", () => {
    chrome.tabs.create({ url: "https://phoneburner.biz/" });
  });
  
  // Scan
  $("scan-launch")?.addEventListener("click", scanAndLaunch);

  // Goals
  $("save-goals")?.addEventListener("click", saveGoals);

  // HubSpot dial action (connect or launch depending on state)
  $("hs-dial-action")?.addEventListener("click", async () => {
    const mode = $("hs-dial-action")?.dataset?.mode;
    if (mode === "launch") return launchHubSpotDialSession();
    return startHubSpotOAuth();
  });

  // HubSpot disconnect (settings)
  $("hs-disconnect")?.addEventListener("click", disconnectHubSpot);

  // Tabs
  $("tab-dial")?.addEventListener("click", () => activateTab("dial"));
  $("tab-settings")?.addEventListener("click", () => activateTab("settings"));

  // Follow widget prefs
  loadFollowWidgetPrefs();

  // CRM context
  chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    const activeTab = tabs?.[0];
    if (!activeTab) return setCrmHeader(null);

    chrome.runtime.sendMessage({ type: "GET_CONTEXT", tabId: activeTab.id }, async (resp) => {
      const ctx = resp?.context || null;
      setCrmHeader(ctx);

      // If HubSpot L3, build HubSpot UI state
      if (isHubSpotL3(ctx)) {
        await refreshHubSpotUi();
      }
    });
  });

  refreshState();
  loadGoalsFromStorage();
});
