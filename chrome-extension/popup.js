// popup.js — DROP-IN (Permission-on-start, no Follow Me toggle UI)
const BASE_URL = "https://extension-dev.phoneburner.biz";

function $(id) {
  return document.getElementById(id);
}
function setVisible(el, isVisible) {
  if (el) el.classList.toggle("hidden", !isVisible);
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
// Follow Widget prefs (auto-collapse)
// ---------------------------

const OVERLAY_STORAGE_KEYS = {
  autoCollapse: "pb_follow_widget_auto_collapse",
};

// Default behavior if nothing saved yet
const DEFAULT_OVERLAY_PREFS = {
  autoCollapse: true,
};

function loadFollowWidgetPrefs() {
  const cb = $("follow-auto-collapse");
  if (!cb) return;

  chrome.storage.local.get([OVERLAY_STORAGE_KEYS.autoCollapse], (res) => {
    const saved = res?.[OVERLAY_STORAGE_KEYS.autoCollapse];
    cb.checked =
      typeof saved === "boolean" ? saved : DEFAULT_OVERLAY_PREFS.autoCollapse;
  });
}

async function saveFollowWidgetPrefs() {
  const cb = $("follow-auto-collapse");
  if (!cb) return;

  const value = !!cb.checked;

  chrome.storage.local.set(
    { [OVERLAY_STORAGE_KEYS.autoCollapse]: value },
    () => {
      // nothing else needed
    },
  );
}

// ---------------------------
// Permission on start (best-effort)
// ---------------------------
// We request permission ONLY when user clicks to start a dial session.
// If the user denies, we still proceed; follow may not persist after navigation.
async function requestOptionalPermissionForActiveSiteBestEffort() {
  try {
    if (!chrome?.permissions?.request)
      return { ok: false, error: "permissions api not available" };

    const [tab] = await chrome.tabs.query({
      active: true,
      currentWindow: true,
    });
    if (!tab?.url) return { ok: false, error: "No active tab URL." };

    let originPattern = null;
    try {
      const u = new URL(tab.url);
      originPattern = `${u.origin}/*`;
    } catch {
      return { ok: false, error: "Invalid active tab URL." };
    }

    return await new Promise((resolve) => {
      chrome.permissions.request({ origins: [originPattern] }, (granted) => {
        resolve({ ok: !!granted, originPattern });
      });
    });
  } catch (e) {
    return { ok: false, error: e?.message || String(e) };
  }
}

// ---------------------------
// CRM header + Level UI toggles
// ---------------------------

let ACTIVE_CTX = null;

function setCrmHeader(context) {
  ACTIVE_CTX = context || null;

  const nameEl = $("crm-name");
  const levelEl = $("crm-level");

  if (nameEl)
    nameEl.textContent = context?.crmName || context?.host || "Unknown";

  if (levelEl) {
    const lvl = context?.level;
    levelEl.textContent =
      lvl === 3
        ? "Level 3 – Full integration"
        : lvl === 2
          ? "Level 2 – Optimized scraping"
          : lvl === 1
            ? "Level 1 – Generic mode"
            : "Unknown level";
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

  setVisible(currentPageCard, !showLevel3);
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
  if (dialAction) {
    dialAction.disabled = true;
    dialAction.textContent = "Loading…";
  }

  let state;
  try {
    state = await hsPost("crm/hubspot/state.php");
  } catch (e) {
    if (dialStatus)
      dialStatus.textContent = "Error checking HubSpot connection.";
    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Retry";
    }
    setVisible(settingsCard, false);
    return;
  }

  const connected = !!state?.hs_ready;

  if (connected) {
    if (dialStatus) dialStatus.textContent = "Connected to HubSpot ✔";
    if (dialHelp)
      dialHelp.textContent =
        "Launch a dial session from the currently selected records.";
    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Launch HubSpot Dial Session";
      dialAction.dataset.mode = "launch";
    }
  } else {
    if (dialStatus) dialStatus.textContent = "Not connected to HubSpot";
    if (dialHelp)
      dialHelp.textContent =
        "Connect HubSpot to enable API-based selection + call logging.";
    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Connect HubSpot";
      dialAction.dataset.mode = "connect";
    }
  }

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
    if (dialStatus)
      dialStatus.textContent =
        "Could not start HubSpot OAuth (missing auth_url).";
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

  // Best-effort permission request (only at user click)
  await requestOptionalPermissionForActiveSiteBestEffort();

  if (dialAction) dialAction.disabled = true;
  if (dialStatus) dialStatus.textContent = "Preparing dial session…";

  const resp = await sendToBackground({ type: "HS_LAUNCH_FROM_SELECTED" });

  if (!resp || !resp.ok) {
    if (dialStatus)
      dialStatus.textContent =
        resp?.error || "Could not launch from selection.";
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

  const resp = await hsPost("crm/hubspot/oauth_disconnect.php", {
    provider: "hs",
  });

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
// PhoneBurner PAT UI (keep your existing handlers)
// ---------------------------

function applyGetPbUi(isConnected) {
  const card = $("get-pb-card");
  setVisible(card, !isConnected);
}

function applyPatUi(isConnected) {
  const pbStatus = $("pb-status");
  const patCardDial = $("pat-card-dial");
  const patCardSettings = $("pat-card-settings");
  if (pbStatus)
    pbStatus.textContent = isConnected ? "Connected ✔" : "Not connected";
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
  if (ACTIVE_CTX?.level === 3) return;
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id) return alert("No active tab found.");

  // Best-effort permission request (only at user click)
  await requestOptionalPermissionForActiveSiteBestEffort();

  const resp = await sendToBackground({
    type: "SCAN_AND_LAUNCH",
    tabId: tab.id,
  });
  if (!resp || !resp.ok) alert(resp?.error || "Could not scan this page.");
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
  $("save-pat")?.addEventListener("click", savePAT);
  $("disconnect-pat")?.addEventListener("click", disconnectPAT);

  // Follow Widget prefs
  loadFollowWidgetPrefs();
  $("follow-auto-collapse")?.addEventListener("change", saveFollowWidgetPrefs);

  // Get PB
  $("get-pb-btn")?.addEventListener("click", () =>
    chrome.tabs.create({ url: "https://phoneburner.biz/" }),
  );

  // Scan
  $("scan-launch")?.addEventListener("click", scanAndLaunch);

  // HubSpot
  $("hs-dial-action")?.addEventListener("click", async () => {
    const mode = $("hs-dial-action")?.dataset?.mode;
    if (mode === "launch") return launchHubSpotDialSession();
    return startHubSpotOAuth();
  });
  $("hs-disconnect")?.addEventListener("click", disconnectHubSpot);

  // Tabs
  $("tab-dial")?.addEventListener("click", () => activateTab("dial"));
  $("tab-settings")?.addEventListener("click", () => activateTab("settings"));

  // CRM context (no injection required; background can infer from URL)
  chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    const activeTab = tabs?.[0];
    if (!activeTab) return setCrmHeader(null);

    chrome.runtime.sendMessage(
      { type: "GET_CONTEXT", tabId: activeTab.id },
      async (resp) => {
        const ctx = resp?.context || null;
        setCrmHeader(ctx);
        if (isHubSpotL3(ctx)) await refreshHubSpotUi();
      },
    );
  });

  refreshState();
});
