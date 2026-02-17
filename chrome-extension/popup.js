// popup.js — DROP-IN (Permission-on-start, no Follow Me toggle UI)
const BASE_URL = "https://extension-dev.phoneburner.biz";

function $(id) {
  return document.getElementById(id);
}
function setVisible(el, isVisible) {
  if (el) el.classList.toggle("hidden", !isVisible);
}

/**
 * Extract error message from API response
 * Handles both string errors and structured error objects
 */
function getErrorMessage(resp, fallback = "An error occurred") {
  if (!resp?.error) return fallback;

  if (typeof resp.error === 'string') {
    return resp.error;
  }

  if (typeof resp.error === 'object') {
    let msg = resp.error.message || fallback;
    // Add skip details if present (for dial session errors)
    if (resp.error.skipped > 0) {
      msg += ` (${resp.error.skipped} records without phone numbers were skipped)`;
    }
    return msg;
  }

  return fallback;
}

// ---------------------------
// Custom modal dialogs (replaces native alert/confirm to prevent Chrome rendering bugs)
// ---------------------------

function showAlert(message, title = "PhoneBurner Extension") {
  return new Promise((resolve) => {
    const overlay = $("modal-overlay");
    const titleEl = $("modal-title");
    const messageEl = $("modal-message");
    const buttonsEl = $("modal-buttons");

    if (!overlay || !titleEl || !messageEl || !buttonsEl) {
      // Fallback to console if modal not available
      console.error("Modal not available:", message);
      resolve();
      return;
    }

    titleEl.textContent = title;
    messageEl.textContent = message;
    buttonsEl.innerHTML = "";

    const okBtn = document.createElement("button");
    okBtn.textContent = "OK";
    okBtn.className = "primary";
    okBtn.addEventListener("click", () => {
      overlay.classList.remove("show");
      resolve();
    });

    buttonsEl.appendChild(okBtn);
    overlay.classList.add("show");

    // Allow Esc key to dismiss
    const escHandler = (e) => {
      if (e.key === "Escape") {
        overlay.classList.remove("show");
        document.removeEventListener("keydown", escHandler);
        resolve();
      }
    };
    document.addEventListener("keydown", escHandler);

    // Focus OK button for accessibility
    setTimeout(() => okBtn.focus(), 0);
  });
}

function showConfirm(message, title = "Confirm") {
  return new Promise((resolve) => {
    const overlay = $("modal-overlay");
    const titleEl = $("modal-title");
    const messageEl = $("modal-message");
    const buttonsEl = $("modal-buttons");

    if (!overlay || !titleEl || !messageEl || !buttonsEl) {
      // Fallback to console if modal not available
      console.error("Modal not available:", message);
      resolve(false);
      return;
    }

    titleEl.textContent = title;
    messageEl.textContent = message;
    buttonsEl.innerHTML = "";

    const cancelBtn = document.createElement("button");
    cancelBtn.textContent = "Cancel";
    cancelBtn.addEventListener("click", () => {
      overlay.classList.remove("show");
      resolve(false);
    });

    const confirmBtn = document.createElement("button");
    confirmBtn.textContent = "OK";
    confirmBtn.className = "primary";
    confirmBtn.addEventListener("click", () => {
      overlay.classList.remove("show");
      resolve(true);
    });

    buttonsEl.appendChild(cancelBtn);
    buttonsEl.appendChild(confirmBtn);
    overlay.classList.add("show");

    // Allow Esc key to cancel
    const escHandler = (e) => {
      if (e.key === "Escape") {
        overlay.classList.remove("show");
        document.removeEventListener("keydown", escHandler);
        resolve(false);
      }
    };
    document.addEventListener("keydown", escHandler);

    // Focus confirm button for accessibility
    setTimeout(() => confirmBtn.focus(), 0);
  });
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
//
// IMPORTANT: Chrome permission dialogs can sometimes get stuck (browser bug).
// We add a 30-second timeout to prevent blocking the entire browser.
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

    // Add 30-second timeout to prevent stuck modal from blocking browser
    return await new Promise((resolve) => {
      let resolved = false;

      const timeout = setTimeout(() => {
        if (!resolved) {
          resolved = true;
          console.warn("Permission request timed out after 30 seconds");
          resolve({
            ok: false,
            error: "Permission request timed out. Try closing and reopening the popup.",
            timeout: true
          });
        }
      }, 30000);

      chrome.permissions.request({ origins: [originPattern] }, (granted) => {
        if (!resolved) {
          resolved = true;
          clearTimeout(timeout);
          resolve({ ok: !!granted, originPattern });
        }
      });
    });
  } catch (e) {
    console.error("Permission request error:", e);
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
  const dialAction = $("hs-dial-action");      // Single button (contacts/deals)
  const dialContacts = $("hs-dial-contacts");  // Company → contacts
  const dialCompanies = $("hs-dial-companies");// Company → companies

  const settingsCard = $("hubspot-settings-card");
  const settingsStatus = $("hs-settings-status");
  const disconnectBtn = $("hs-disconnect");

  // Determine which buttons to show based on object type
  const objectType = ACTIVE_CTX?.objectType || 'contact';
  const isCompanyList = objectType === 'company';

  // Show appropriate buttons based on object type
  if (dialAction) setVisible(dialAction, !isCompanyList);
  if (dialContacts) setVisible(dialContacts, isCompanyList);
  if (dialCompanies) setVisible(dialCompanies, isCompanyList);

  if (dialStatus) dialStatus.textContent = "Checking HubSpot connection…";
  if (dialHelp) dialHelp.textContent = "";
  if (dialAction) {
    dialAction.disabled = true;
    dialAction.textContent = "Loading…";
  }
  if (dialContacts) {
    dialContacts.disabled = true;
    dialContacts.textContent = "Loading…";
  }
  if (dialCompanies) {
    dialCompanies.disabled = true;
    dialCompanies.textContent = "Loading…";
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

    // Update single button (for contacts/deals)
    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Launch HubSpot Dial Session";
      dialAction.dataset.mode = "launch";
    }

    // Update company buttons
    if (dialContacts) {
      dialContacts.disabled = false;
      dialContacts.textContent = "Launch Dial Session (Contacts)";
    }
    if (dialCompanies) {
      dialCompanies.disabled = false;
      dialCompanies.textContent = "Launch Dial Session (Companies)";
    }
  } else {
    if (dialStatus) dialStatus.textContent = "Not connected to HubSpot";
    if (dialHelp)
      dialHelp.textContent =
        "Connect HubSpot to enable API-based selection + call logging.";

    // Update single button (for contacts/deals)
    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Connect HubSpot";
      dialAction.dataset.mode = "connect";
    }

    // Update company buttons
    if (dialContacts) {
      dialContacts.disabled = false;
      dialContacts.textContent = "Connect HubSpot";
    }
    if (dialCompanies) {
      dialCompanies.disabled = false;
      dialCompanies.textContent = "Connect HubSpot";
    }
  }

  // Show/hide list section based on connection state
  const listSection = $("hs-list-section");
  setVisible(listSection, connected);

  // Fetch lists when connected (non-blocking)
  if (connected) {
    fetchHubSpotLists();
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
    await showAlert("Server did not return auth_url. Check server logs.");
    return;
  }

  chrome.tabs.create({ url: authUrl });
  window.close();
}

async function launchHubSpotDialSession(callTarget = null) {
  const dialStatus = $("hs-dial-status");
  const allButtons = [
    $("hs-dial-action"),
    $("hs-dial-contacts"),
    $("hs-dial-companies")
  ];

  // Best-effort permission request (only at user click)
  try {
    const permResult = await requestOptionalPermissionForActiveSiteBestEffort();
    if (permResult.timeout) {
      // Permission dialog timed out - warn user but continue
      console.warn("Permission request timed out, continuing anyway");
      if (dialStatus) {
        dialStatus.textContent = "Permission timeout - extension may not persist after navigation";
      }
    } else if (!permResult.ok) {
      console.warn("Permission request failed:", permResult.error);
      // Continue anyway - extension will work, just might not persist
    }
  } catch (err) {
    console.error("Permission request crashed:", err);
    // Recover gracefully - continue with dial session
  }

  // Disable all buttons during request
  allButtons.forEach(btn => { if (btn) btn.disabled = true; });
  if (dialStatus) dialStatus.textContent = "Preparing dial session…";

  // Build message with optional call_target
  const message = { type: "HS_LAUNCH_FROM_SELECTED" };
  if (callTarget) message.call_target = callTarget;

  const resp = await sendToBackground(message);

  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Could not launch from selection.");
    if (dialStatus) dialStatus.textContent = errorMsg;
    allButtons.forEach(btn => { if (btn) btn.disabled = false; });
    await showAlert(errorMsg);
    return;
  }

  if (dialStatus) dialStatus.textContent = "Dial session created ✔";
  window.close();
}

// Wrapper functions for new buttons
async function launchHubSpotDialSessionContacts() {
  await launchHubSpotDialSession('contacts');
}

async function launchHubSpotDialSessionCompanies() {
  await launchHubSpotDialSession('companies');
}

// ---------------------------
// HubSpot List-based Dial Session
// ---------------------------

// Cache of fetched list metadata (keyed by listId) for the current popup session
let _hsListsCache = [];

async function fetchHubSpotLists() {
  const listSection = $("hs-list-section");
  const listSelect = $("hs-list-select");
  const listInfo = $("hs-list-info");
  const listBtn = $("hs-dial-from-list");

  if (!listSection || !listSelect) return;

  // Reset
  listSelect.innerHTML = '<option value="">Loading lists…</option>';
  listSelect.disabled = true;
  setVisible(listBtn, false);
  if (listInfo) listInfo.textContent = "";

  const resp = await sendToBackground({ type: "HS_FETCH_LISTS" });

  if (!resp || !resp.ok || !Array.isArray(resp.lists)) {
    // Silently hide the list section if endpoint not available (backward compatibility)
    // This handles old servers that don't have hs_lists.php yet
    setVisible(listSection, false);
    return;
  }

  _hsListsCache = resp.lists;

  if (resp.lists.length === 0) {
    listSelect.innerHTML = '<option value="">No lists found</option>';
    if (listInfo) listInfo.textContent = "Create lists in HubSpot to use this feature.";
    return;
  }

  // Populate dropdown
  listSelect.innerHTML = '<option value="">Select a list…</option>';
  for (const list of resp.lists) {
    const opt = document.createElement("option");
    opt.value = list.listId;
    const typeLabel = list.objectType === "companies" ? "companies" : "contacts";

    // Only show count if we have it (HubSpot API doesn't always return size)
    if (list.size > 0) {
      opt.textContent = `${list.name} (${list.size} ${typeLabel})`;
    } else {
      opt.textContent = `${list.name} (${typeLabel} list)`;
    }

    opt.dataset.objectType = list.objectType;
    opt.dataset.size = list.size;
    listSelect.appendChild(opt);
  }
  listSelect.disabled = false;
}

function onHsListSelectChange() {
  const listSelect = $("hs-list-select");
  const listInfo = $("hs-list-info");
  const listBtn = $("hs-dial-from-list");

  if (!listSelect) return;

  const selectedOpt = listSelect.selectedOptions[0];
  const listId = listSelect.value;

  if (!listId) {
    setVisible(listBtn, false);
    if (listInfo) listInfo.textContent = "";
    return;
  }

  const size = parseInt(selectedOpt?.dataset?.size || "0", 10);
  const objectType = selectedOpt?.dataset?.objectType || "contacts";
  const typeLabel = objectType === "companies" ? "companies" : "contacts";

  if (size > 500) {
    if (listInfo) {
      listInfo.textContent = `This list has ${size} ${typeLabel}. PhoneBurner limit is 500 — the first 500 will be used.`;
      listInfo.style.color = "var(--danger)";
    }
  } else if (size > 0) {
    if (listInfo) {
      listInfo.textContent = `${size} ${typeLabel} will be added to the dial session.`;
      listInfo.style.color = "var(--muted)";
    }
  } else {
    // Size unknown (HubSpot API doesn't always return member count)
    if (listInfo) {
      listInfo.textContent = `All ${typeLabel} from this list will be added (up to 500).`;
      listInfo.style.color = "var(--muted)";
    }
  }

  setVisible(listBtn, true);
}

async function launchDialSessionFromList() {
  const listSelect = $("hs-list-select");
  const listBtn = $("hs-dial-from-list");
  const listInfo = $("hs-list-info");
  const dialStatus = $("hs-dial-status");

  if (!listSelect || !listSelect.value) {
    await showAlert("Please select a list first.");
    return;
  }

  const listId = listSelect.value;
  const selectedOpt = listSelect.selectedOptions[0];
  const objectType = selectedOpt?.dataset?.objectType || "contacts";

  // Best-effort permission request
  try {
    const permResult = await requestOptionalPermissionForActiveSiteBestEffort();
    if (permResult.timeout) {
      console.warn("Permission request timed out, continuing anyway");
    }
  } catch (err) {
    console.error("Permission request crashed:", err);
  }

  // Disable controls during request
  if (listBtn) listBtn.disabled = true;
  if (listSelect) listSelect.disabled = true;
  if (dialStatus) dialStatus.textContent = "Creating dial session from list…";

  const resp = await sendToBackground({
    type: "HS_LAUNCH_FROM_LIST",
    list_id: listId,
    object_type: objectType,
  });

  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Could not launch from list.");
    if (dialStatus) dialStatus.textContent = errorMsg;
    if (listBtn) listBtn.disabled = false;
    if (listSelect) listSelect.disabled = false;
    await showAlert(errorMsg);
    return;
  }

  if (dialStatus) dialStatus.textContent = "Dial session created ✔";
  window.close();
}

async function disconnectHubSpot() {
  const confirmed = await showConfirm("Disconnect HubSpot for this session?");
  if (!confirmed) return;

  const btn = $("hs-disconnect");
  const settingsStatus = $("hs-settings-status");
  if (btn) btn.disabled = true;
  if (settingsStatus) settingsStatus.textContent = "Disconnecting…";

  const resp = await hsPost("crm/hubspot/oauth_disconnect.php", {
    provider: "hs",
  });

  if (!resp || resp.ok !== true) {
    const errorMsg = getErrorMessage(resp, "Failed to disconnect HubSpot.");
    if (settingsStatus) settingsStatus.textContent = "Failed to disconnect.";
    if (btn) btn.disabled = false;
    await showAlert(errorMsg);
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

  if (!pat) {
    await showAlert("Please paste your PAT first.");
    return;
  }
  if (btn) btn.disabled = true;

  const resp = await sendToBackground({ type: "SAVE_PAT", pat });
  if (resp && resp.ok) {
    await showAlert("PAT saved.");
    await refreshState();
    activateTab("settings");
  } else {
    const errorMsg = getErrorMessage(resp, "Unknown error");
    await showAlert("Error saving PAT: " + errorMsg);
    if (btn) btn.disabled = false;
  }
}

async function disconnectPAT() {
  const confirmed = await showConfirm("Disconnect from PhoneBurner and clear your PAT?");
  if (!confirmed) return;

  const btn = $("disconnect-pat");
  if (btn) btn.disabled = true;

  const resp = await sendToBackground({ type: "CLEAR_PAT" });
  if (resp && resp.ok) {
    await showAlert("Disconnected from PhoneBurner.");
    await refreshState();
    activateTab("dial");
  } else {
    const errorMsg = getErrorMessage(resp, "Unknown error");
    await showAlert("Error disconnecting: " + errorMsg);
    if (btn) btn.disabled = false;
  }
}

// ---------------------------
// Scan & Launch (Level 1/2 only)
// ---------------------------

async function scanAndLaunch() {
  if (ACTIVE_CTX?.level === 3) return;
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id) {
    await showAlert("No active tab found.");
    return;
  }

  // Best-effort permission request (only at user click)
  try {
    const permResult = await requestOptionalPermissionForActiveSiteBestEffort();
    if (permResult.timeout) {
      console.warn("Permission request timed out, continuing anyway");
      // Show warning but continue
      const continueAnyway = await showConfirm(
        "Permission request timed out. Continue anyway?\n\n" +
        "Note: Extension may not persist after page navigation.\n" +
        "Press ESC if a permission dialog is stuck.",
        "Permission Timeout"
      );
      if (!continueAnyway) return;
    } else if (!permResult.ok) {
      console.warn("Permission request failed:", permResult.error);
      // Continue anyway - extension will work
    }
  } catch (err) {
    console.error("Permission request crashed:", err);
    // Recover gracefully - continue with scan
  }

  const resp = await sendToBackground({
    type: "SCAN_AND_LAUNCH",
    tabId: tab.id,
  });
  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Could not scan this page.");
    await showAlert(errorMsg);
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

  // HubSpot company buttons
  $("hs-dial-contacts")?.addEventListener("click", async () => {
    // If not connected, start OAuth flow
    const mode = $("hs-dial-contacts")?.textContent;
    if (mode && mode.includes("Connect")) return startHubSpotOAuth();
    return launchHubSpotDialSessionContacts();
  });

  $("hs-dial-companies")?.addEventListener("click", async () => {
    // If not connected, start OAuth flow
    const mode = $("hs-dial-companies")?.textContent;
    if (mode && mode.includes("Connect")) return startHubSpotOAuth();
    return launchHubSpotDialSessionCompanies();
  });

  // HubSpot list-based dial
  $("hs-list-select")?.addEventListener("change", onHsListSelectChange);
  $("hs-dial-from-list")?.addEventListener("click", launchDialSessionFromList);

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
