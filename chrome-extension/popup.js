// popup.js

const BASE_URL = "https://extension-dev.phoneburner.biz";

function setCrmHeader(context) {
  const nameEl = document.getElementById("crm-name");
  const levelEl = document.getElementById("crm-level");
  const hsPanel = document.getElementById("hubspot-panel");

  if (!nameEl || !levelEl) return;

  const crmName = context?.crmName || context?.host || "Unknown";
  nameEl.textContent = crmName;

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

  if (hsPanel) {
    hsPanel.style.display =
      context && context.crmId === "hubspot" ? "block" : "none";
  }
}

document.addEventListener("DOMContentLoaded", () => {
  // Ask background what CRM / level this active tab belongs to
  chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    const activeTab = tabs && tabs[0];
    if (!activeTab) {
      setCrmHeader(null);
      return;
    }

    chrome.runtime.sendMessage(
      { type: "GET_CONTEXT", tabId: activeTab.id },
      (resp) => {
        if (chrome.runtime.lastError) {
          setCrmHeader(null);
          return;
        }

        const ctx = resp && resp.context ? resp.context : null;
        setCrmHeader(ctx);

        // If we are on HubSpot (Level 3), load HS connection state
        if (ctx && ctx.crmId === "hubspot" && ctx.level === 3) {
          refreshHubSpotState();
        }
      }
    );
  });
});

function getClientIdFromBackground() {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage({ type: "GET_CLIENT_ID" }, (resp) => {
      if (resp && resp.ok && resp.client_id) {
        resolve(resp.client_id);
      } else {
        resolve(null);
      }
    });
  });
}

function sendToBackground(msg) {
  return new Promise((resolve) => {
    chrome.runtime.sendMessage(msg, (resp) => resolve(resp));
  });
}

// --- HubSpot (Level 3) helpers ---

async function hsPost(path, body = {}) {
  const clientId = await getClientIdFromBackground();

  const headers = {
    "Content-Type": "application/json",
  };
  if (clientId) {
    headers["X-Client-Id"] = clientId;
  }

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
  const panel = document.getElementById("hubspot-panel");
  const statusEl = document.getElementById("hs-status");
  const btn = document.getElementById("btn-hs-connect");

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
  const statusEl = document.getElementById("hs-status");
  const btn = document.getElementById("btn-hs-connect");

  if (!btn) return;

  if (statusEl) {
    statusEl.textContent = "Starting HubSpot connection…";
  }
  btn.disabled = true;

  try {
    const resp = await hsPost("crm/hubspot/oauth_hs_start.php");

    const authUrl = resp && resp.auth_url;
    if (!authUrl) {
      console.error("oauth_hs_start response missing auth_url:", resp);
      alert("Server did not return auth_url. Check console/server logs.");
      if (statusEl) statusEl.textContent = "Error starting HubSpot OAuth.";
      btn.disabled = false;
      return;
    }

    chrome.tabs.create({ url: authUrl });
    window.close();
  } catch (e) {
    console.error("HubSpot connect error", e);
    alert("Error starting HubSpot connection: " + e.message);
    if (statusEl) statusEl.textContent = "Error starting HubSpot OAuth.";
    btn.disabled = false;
  }
}

async function getActiveTab() {
  return new Promise((resolve) => {
    chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
      resolve(tabs && tabs[0] ? tabs[0] : null);
    });
  });
}

async function getHubSpotSelectionFromPage() {
  const tab = await getActiveTab();
  if (!tab || !tab.id) {
    return { ok: false, error: "No active tab" };
  }

  return new Promise((resolve) => {
    chrome.tabs.sendMessage(
      tab.id,
      { type: "HS_GET_SELECTION" },
      (resp) => {
        if (chrome.runtime.lastError) {
          console.error("HS_GET_SELECTION error", chrome.runtime.lastError);
          resolve({ ok: false, error: "No response from content script" });
          return;
        }
        resolve(resp || { ok: false, error: "No selection response" });
      }
    );
  });
}

async function onHubSpotLaunchClick() {
  const statusEl = document.getElementById("hs-status");
  const btnLaunch = document.getElementById("btn-hs-launch");

  if (!btnLaunch) return;

  btnLaunch.disabled = true;
  if (statusEl) statusEl.textContent = "Collecting selected records…";

  const sel = await getHubSpotSelectionFromPage();
  if (!sel || !sel.ok) {
    console.warn("HS selection failed", sel);
    alert(sel && sel.error ? sel.error : "Could not read selection on this page.");
    if (statusEl) statusEl.textContent = "Could not read HubSpot selection.";
    btnLaunch.disabled = false;
    return;
  }

  if (!sel.records || !sel.records.length) {
    alert("No HubSpot records selected.");
    if (statusEl) statusEl.textContent = "No selected HubSpot records.";
    btnLaunch.disabled = false;
    return;
  }

  if (statusEl) statusEl.textContent = "Creating PhoneBurner dial session…";

  try {
    const resp = await hsPost("crm/hubspot/pb_dialsession_selection.php", {
      mode: sel.mode,
      records: sel.records,
      context: sel.context || {},
    });

    console.log("HS pb_dialsession resp", resp);

    if (!resp || !resp.launch_url) {
      const msg =
        (resp && (resp.error || resp.details)) ||
        JSON.stringify(resp || {}) ||
        "HubSpot dial session did not return a launch URL.";

      alert(msg);
      if (statusEl) statusEl.textContent = "Error creating HubSpot dial session.";
      btnLaunch.disabled = false;
      return;
    }

    await sendToBackground({
      type: "HS_SESSION_STARTED",
      session_token: resp.session_token,
      backend_base: BASE_URL,
    });

    chrome.windows.create(
      {
        url: resp.launch_url,
        type: "popup",
        width: 1200,
        height: 800,
      },
      () => {
        window.close();
      }
    );
  } catch (e) {
    console.error("HS launch error", e);
    alert("Error launching HubSpot dial session: " + e.message);
    if (statusEl) statusEl.textContent = "Error launching HubSpot dial session.";
    btnLaunch.disabled = false;
  }
}

// --- PhoneBurner state / PAT management ---

async function refreshState() {
  const stateEl = document.getElementById("pb-status");
  const patInput = document.getElementById("pat");
  const savePatBtn = document.getElementById("save-pat");

  stateEl.textContent = "Checking...";

  const resp = await sendToBackground({ type: "GET_STATE" });

  if (!resp || !resp.ok) {
    stateEl.textContent = "Error checking state";
    patInput.disabled = false;
    savePatBtn.textContent = "Save PAT";
    savePatBtn.dataset.mode = "save";
    return;
  }

  const connected = resp.phoneburner && resp.phoneburner.connected;

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
  const patInput = document.getElementById("pat");
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
  const btn = document.getElementById("save-pat");
  const mode = btn.dataset.mode || "save";

  if (mode === "disconnect") {
    disconnectPAT();
  } else {
    savePAT();
  }
}

// Ask active tab to scan, then background will handle SCANNED_CONTACTS
async function scanAndLaunch() {
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

    // If content script never called sendResponse, resp will be undefined/null.
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


// --- Goals (primary / secondary dispositions) ---

function loadGoalsFromStorage() {
  chrome.storage.local.get(
    ["pb_goal_primary", "pb_goal_secondary"],
    (res) => {
      const primaryInput = document.getElementById("goal-primary");
      const secondaryInput = document.getElementById("goal-secondary");
      const statusEl = document.getElementById("goals-status");

      const primary = res.pb_goal_primary || "Set Appointment";
      const secondary = res.pb_goal_secondary || "Follow Up";

      primaryInput.value = primary;
      secondaryInput.value = secondary;

      statusEl.textContent =
        'Goals saved: Primary = "' +
        primary +
        '", Secondary = "' +
        secondary +
        '"';
    }
  );
}

async function saveGoals() {
  const primaryInput = document.getElementById("goal-primary");
  const secondaryInput = document.getElementById("goal-secondary");
  const statusEl = document.getElementById("goals-status");

  let primary = primaryInput.value.trim() || "Set Appointment";
  let secondary = secondaryInput.value.trim() || "Follow Up";

  chrome.storage.local.set(
    {
      pb_goal_primary: primary,
      pb_goal_secondary: secondary,
    },
    () => {
      statusEl.textContent =
        'Goals saved: Primary = "' +
        primary +
        '", Secondary = "' +
        secondary +
        '"';
    }
  );

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (tab && tab.id) {
    chrome.tabs.sendMessage(
      tab.id,
      {
        type: "PB_GOAL_UPDATED",
        primary,
        secondary,
      },
      () => {}
    );
  }
}

// --- Tabs ---

function activateTab(tabName) {
  const dialBtn = document.getElementById("tab-dial");
  const settingsBtn = document.getElementById("tab-settings");
  const dialPanel = document.getElementById("panel-dial");
  const settingsPanel = document.getElementById("panel-settings");

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

// --- Wire up events on load ---

document.getElementById("tab-dial").addEventListener("click", () => {
  activateTab("dial");
});

document
  .getElementById("tab-settings")
  .addEventListener("click", () => activateTab("settings"));

document
  .getElementById("save-pat")
  .addEventListener("click", onPatButtonClick);

document
  .getElementById("scan-launch")
  .addEventListener("click", scanAndLaunch);

document
  .getElementById("save-goals")
  .addEventListener("click", saveGoals);

// HubSpot buttons
const hsBtn = document.getElementById("btn-hs-connect");
if (hsBtn) {
  hsBtn.addEventListener("click", onHubSpotConnectClick);
}

const hsLaunchBtn = document.getElementById("btn-hs-launch");
if (hsLaunchBtn) {
  hsLaunchBtn.addEventListener("click", onHubSpotLaunchClick);
}

// Initial load
refreshState();
loadGoalsFromStorage();
