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
    buttonsEl.replaceChildren();

    // Shared cleanup + resolve
    const escHandler = (e) => {
      if (e.key === "Escape") closeModal();
    };
    function closeModal() {
      overlay.classList.remove("show");
      document.removeEventListener("keydown", escHandler);
      resolve();
    }

    const okBtn = document.createElement("button");
    okBtn.textContent = "OK";
    okBtn.className = "primary";
    okBtn.addEventListener("click", closeModal);

    buttonsEl.appendChild(okBtn);
    overlay.classList.add("show");

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
    buttonsEl.replaceChildren();

    // Shared cleanup
    const escHandler = (e) => {
      if (e.key === "Escape") closeModal(false);
    };
    function closeModal(result) {
      overlay.classList.remove("show");
      document.removeEventListener("keydown", escHandler);
      resolve(result);
    }

    const cancelBtn = document.createElement("button");
    cancelBtn.textContent = "Cancel";
    cancelBtn.addEventListener("click", () => closeModal(false));

    const confirmBtn = document.createElement("button");
    confirmBtn.textContent = "OK";
    confirmBtn.className = "primary";
    confirmBtn.addEventListener("click", () => closeModal(true));

    buttonsEl.appendChild(cancelBtn);
    buttonsEl.appendChild(confirmBtn);
    overlay.classList.add("show");

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
let PB_CONNECTED = false;
let HS_STATE = { connected: false, portalId: null };

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
}

function isHubSpotL3(ctx) {
  return !!(ctx && ctx.crmId === "hubspot" && ctx.level === 3);
}

function applyContextVisibility(ctx, pbConnected) {
  const currentPageCard = $("card-current-page");
  const hsDialCard = $("hubspot-dial-card");
  const hsRecordCard = $("hubspot-record-card");
  const hsListCard = $("hs-list-card");

  const isHS = isHubSpotL3(ctx);
  const pageType = ctx?.pageType || "other";
  const bothAuth = pbConnected && HS_STATE.connected;

  // Scan & Launch: any non-HubSpot page (generic scanner may work on obscure CRMs)
  setVisible(currentPageCard, !isHS);

  // Selection card: HS list pages only
  setVisible(hsDialCard, isHS && pageType === "list");

  // Record card: HS record pages only
  setVisible(hsRecordCard, isHS && pageType === "record");

  // List card: any page when both PB + HS connected
  setVisible(hsListCard, bothAuth);

  // Settings card visibility
  const settingsCard = $("hubspot-settings-card");
  const settingsStatus = $("hs-settings-status");
  const disconnectBtn = $("hs-disconnect");
  setVisible(settingsCard, HS_STATE.connected);
  if (HS_STATE.connected) {
    if (settingsStatus) settingsStatus.textContent = "Connected ✔";
    if (disconnectBtn) disconnectBtn.disabled = false;
  }

  // Populate active cards
  if (isHS && pageType === "list") {
    refreshHubSpotSelectionUi(ctx?.objectType || "contact");
  }
  if (isHS && pageType === "record") {
    refreshHubSpotRecordUi(ctx?.objectType || "contact");
  }
  if (bothAuth) {
    fetchHubSpotLists();
  }
}

async function checkHubSpotConnectionState() {
  try {
    const state = await hsPost("crm/hubspot/state.php");
    HS_STATE.connected = !!state?.hs_ready;
    // portal_id is nested under hubspot object in state response
    const hsPortalId = state?.hubspot?.portal_id || null;
    if (hsPortalId) HS_STATE.portalId = hsPortalId;
  } catch (e) {
    HS_STATE.connected = false;
  }
  return HS_STATE.connected;
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

function refreshHubSpotSelectionUi(objectType) {
  const dialStatus = $("hs-dial-status");
  const dialHelp = $("hs-dial-help");
  const dialAction = $("hs-dial-action");
  const dialContacts = $("hs-dial-contacts");
  const dialCompanies = $("hs-dial-companies");

  const isCompanyList = objectType === "company";

  setVisible(dialAction, !isCompanyList);
  setVisible(dialContacts, isCompanyList);
  setVisible(dialCompanies, isCompanyList);

  if (HS_STATE.connected) {
    if (dialStatus) dialStatus.textContent = "Connected to HubSpot ✔";
    if (dialHelp) dialHelp.textContent = "Launch a dial session from the currently selected records.";

    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Launch HubSpot Dial Session";
      dialAction.dataset.mode = "launch";
    }
    if (dialContacts) {
      dialContacts.disabled = false;
      dialContacts.textContent = "Launch Dial Session (Contacts)";
      dialContacts.dataset.mode = "launch";
    }
    if (dialCompanies) {
      dialCompanies.disabled = false;
      dialCompanies.textContent = "Launch Dial Session (Companies)";
      dialCompanies.dataset.mode = "launch";
    }
  } else {
    if (dialStatus) dialStatus.textContent = "Not connected to HubSpot";
    if (dialHelp) dialHelp.textContent = "Connect HubSpot to enable API-based selection + call logging.";

    if (dialAction) {
      dialAction.disabled = false;
      dialAction.textContent = "Connect HubSpot";
      dialAction.dataset.mode = "connect";
    }
    if (dialContacts) {
      dialContacts.disabled = false;
      dialContacts.textContent = "Connect HubSpot";
      dialContacts.dataset.mode = "connect";
    }
    if (dialCompanies) {
      dialCompanies.disabled = false;
      dialCompanies.textContent = "Connect HubSpot";
      dialCompanies.dataset.mode = "connect";
    }
  }
}

function refreshHubSpotRecordUi(objectType) {
  const recordStatus = $("hs-record-status");
  const recordHelp = $("hs-record-help");
  const recordAction = $("hs-record-action");
  const recordContacts = $("hs-record-contacts");
  const recordCompanies = $("hs-record-companies");

  const isCompany = objectType === "company";

  setVisible(recordAction, !isCompany);
  setVisible(recordContacts, isCompany);
  setVisible(recordCompanies, isCompany);

  if (HS_STATE.connected) {
    if (recordStatus) recordStatus.textContent = "Connected to HubSpot ✔";

    if (objectType === "contact") {
      if (recordHelp) recordHelp.textContent = "Dial this contact directly.";
      if (recordAction) {
        recordAction.disabled = false;
        recordAction.textContent = "Dial This Contact";
        recordAction.dataset.mode = "launch";
      }
    } else if (objectType === "company") {
      if (recordHelp) recordHelp.textContent = "Dial contacts or this company directly.";
      if (recordContacts) {
        recordContacts.disabled = false;
        recordContacts.textContent = "Dial Contacts at This Company";
        recordContacts.dataset.mode = "launch";
      }
      if (recordCompanies) {
        recordCompanies.disabled = false;
        recordCompanies.textContent = "Dial This Company";
        recordCompanies.dataset.mode = "launch";
      }
    } else if (objectType === "deal") {
      if (recordHelp) recordHelp.textContent = "Dial contacts on this deal.";
      if (recordAction) {
        recordAction.disabled = false;
        recordAction.textContent = "Dial Contacts on This Deal";
        recordAction.dataset.mode = "launch";
      }
    }
  } else {
    if (recordStatus) recordStatus.textContent = "Not connected to HubSpot";
    if (recordHelp) recordHelp.textContent = "Connect HubSpot to dial from this record.";

    if (recordAction) {
      recordAction.disabled = false;
      recordAction.textContent = "Connect HubSpot";
      recordAction.dataset.mode = "connect";
    }
    if (recordContacts) {
      recordContacts.disabled = false;
      recordContacts.textContent = "Connect HubSpot";
      recordContacts.dataset.mode = "connect";
    }
    if (recordCompanies) {
      recordCompanies.disabled = false;
      recordCompanies.textContent = "Connect HubSpot";
      recordCompanies.dataset.mode = "connect";
    }
  }
}

async function startHubSpotOAuth() {
  // Update status on whichever card is visible
  const dialStatus = $("hs-dial-status");
  const recordStatus = $("hs-record-status");
  if (dialStatus) dialStatus.textContent = "Starting HubSpot connection…";
  if (recordStatus) recordStatus.textContent = "Starting HubSpot connection…";

  const resp = await hsPost("crm/hubspot/oauth_hs_start.php");
  const authUrl = resp?.auth_url;

  if (!authUrl) {
    if (dialStatus) dialStatus.textContent = "Could not start HubSpot OAuth.";
    if (recordStatus) recordStatus.textContent = "Could not start HubSpot OAuth.";
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
  if (dialStatus) {
    dialStatus.textContent = "Building dial session from selected records…";
    dialStatus.classList.add("loading");
  }

  // Build message with optional call_target
  const message = { type: "HS_LAUNCH_FROM_SELECTED" };
  if (callTarget) message.call_target = callTarget;

  const resp = await sendToBackground(message);

  if (dialStatus) dialStatus.classList.remove("loading");

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
// HubSpot Record-based Dial Session (single record pages)
// ---------------------------

async function launchHubSpotRecordDial(callTarget = null) {
  const recordStatus = $("hs-record-status");
  const allButtons = [
    $("hs-record-action"),
    $("hs-record-contacts"),
    $("hs-record-companies")
  ];

  // Best-effort permission request
  try {
    const permResult = await requestOptionalPermissionForActiveSiteBestEffort();
    if (permResult.timeout) {
      console.warn("Permission request timed out, continuing anyway");
      if (recordStatus) {
        recordStatus.textContent = "Permission timeout - extension may not persist after navigation";
      }
    } else if (!permResult.ok) {
      console.warn("Permission request failed:", permResult.error);
    }
  } catch (err) {
    console.error("Permission request crashed:", err);
  }

  allButtons.forEach(btn => { if (btn) btn.disabled = true; });
  if (recordStatus) {
    recordStatus.textContent = "Building dial session…";
    recordStatus.classList.add("loading");
  }

  const message = {
    type: "HS_LAUNCH_FROM_RECORD",
    recordId: ACTIVE_CTX?.recordId,
    objectType: ACTIVE_CTX?.objectType || "contact",
    portalId: ACTIVE_CTX?.portalId || HS_STATE.portalId,
  };
  if (callTarget) message.call_target = callTarget;

  const resp = await sendToBackground(message);

  if (recordStatus) recordStatus.classList.remove("loading");

  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Could not launch from record.");
    if (recordStatus) recordStatus.textContent = errorMsg;
    allButtons.forEach(btn => { if (btn) btn.disabled = false; });
    await showAlert(errorMsg);
    return;
  }

  if (recordStatus) recordStatus.textContent = "Dial session created ✔";
  window.close();
}

// ---------------------------
// HubSpot List-based Dial Session
// ---------------------------

// Cache of fetched list metadata (keyed by listId) for the current popup session
let _hsListsCache = [];

async function fetchHubSpotLists() {
  const listCard = $("hs-list-card");
  const listSelect = $("hs-list-select");
  const listInfo = $("hs-list-info");
  const listBtn = $("hs-dial-from-list");

  if (!listCard || !listSelect) return;

  // Reset
  listSelect.innerHTML = '<option value="">Loading lists…</option>';
  listSelect.disabled = true;
  setVisible(listBtn, false);
  if (listInfo) listInfo.textContent = "";

  const resp = await sendToBackground({ type: "HS_FETCH_LISTS" });

  if (!resp || !resp.ok || !Array.isArray(resp.lists)) {
    // Silently hide the list card if endpoint not available (backward compatibility)
    setVisible(listCard, false);
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

    // Truncate long list names to prevent dropdown overflow
    let displayName = list.name;
    if (displayName.length > 35) {
      displayName = displayName.substring(0, 32) + '...';
    }

    // Only show count if we have it (HubSpot API doesn't always return size)
    if (list.size > 0) {
      opt.textContent = `${displayName} (${list.size} ${typeLabel})`;
    } else {
      opt.textContent = `${displayName} (${typeLabel} list)`;
    }

    // Store full name in title for tooltip
    opt.title = list.name;
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
  const listStatus = $("hs-list-status");

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

  // Build contextual loading message
  const size = parseInt(selectedOpt?.dataset?.size || "0", 10);
  const typeLabel = objectType === "companies" ? "companies" : "contacts";
  let loadingMsg = "Building dial session";
  if (size > 0) {
    loadingMsg += ` from ${Math.min(size, 500)} ${typeLabel}`;
  }
  loadingMsg += "…";
  if (size > 50) {
    loadingMsg += " This may take a moment.";
  }

  // Disable controls during request
  if (listBtn) {
    listBtn.disabled = true;
    listBtn.textContent = "Building Session…";
  }
  if (listSelect) listSelect.disabled = true;
  if (listStatus) {
    listStatus.textContent = loadingMsg;
    listStatus.classList.add("loading");
  }

  const resp = await sendToBackground({
    type: "HS_LAUNCH_FROM_LIST",
    list_id: listId,
    object_type: objectType,
    portal_id: ACTIVE_CTX?.portalId || HS_STATE.portalId,
  });

  // Remove loading animation
  if (listStatus) listStatus.classList.remove("loading");

  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Could not launch from list.");
    if (listStatus) listStatus.textContent = errorMsg;
    if (listBtn) {
      listBtn.disabled = false;
      listBtn.textContent = "Launch Dial Session from List";
    }
    if (listSelect) listSelect.disabled = false;
    await showAlert(errorMsg);
    return;
  }

  if (listStatus) listStatus.textContent = "Dial session created ✔";
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

  HS_STATE.connected = false;
  HS_STATE.portalId = null;
  applyContextVisibility(ACTIVE_CTX, PB_CONNECTED);
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
  if (!resp || resp.ok !== true) {
    PB_CONNECTED = false;
    return applyPatUi(false);
  }
  PB_CONNECTED = !!resp?.phoneburner?.connected;
  applyPatUi(PB_CONNECTED);
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

  const btn = $("scan-launch");
  const statusEl = $("scan-status");
  const originalText = btn ? btn.textContent : "";

  // Show loading state
  if (btn) { btn.disabled = true; btn.textContent = "Scanning…"; }
  if (statusEl) {
    statusEl.textContent = "Scanning page for contacts…";
    statusEl.classList.add("loading");
  }

  // Best-effort permission request (only at user click)
  try {
    const permResult = await requestOptionalPermissionForActiveSiteBestEffort();
    if (permResult.timeout) {
      console.warn("Permission request timed out, continuing anyway");
      const continueAnyway = await showConfirm(
        "Permission request timed out. Continue anyway?\n\n" +
        "Note: Extension may not persist after page navigation.\n" +
        "Press ESC if a permission dialog is stuck.",
        "Permission Timeout"
      );
      if (!continueAnyway) {
        if (btn) { btn.disabled = false; btn.textContent = originalText; }
        if (statusEl) { statusEl.textContent = ""; statusEl.classList.remove("loading"); }
        return;
      }
    } else if (!permResult.ok) {
      console.warn("Permission request failed:", permResult.error);
    }
  } catch (err) {
    console.error("Permission request crashed:", err);
  }

  if (statusEl) statusEl.textContent = "Building dial session…";

  const resp = await sendToBackground({
    type: "SCAN_AND_LAUNCH",
    tabId: tab.id,
  });

  if (!resp || !resp.ok) {
    if (statusEl) statusEl.classList.remove("loading");
    const errorMsg = getErrorMessage(resp, "Could not scan this page.");
    if (statusEl) statusEl.textContent = "";
    if (btn) { btn.disabled = false; btn.textContent = originalText; }
    await showAlert(errorMsg);
  } else {
    // The scan response returns before the dial session window opens
    // (content script fires SCANNED_CONTACTS asynchronously), so show
    // brief feedback then auto-close to give the window time to open.
    if (statusEl) statusEl.textContent = "Launching dial session…";
    if (btn) btn.textContent = "Launching…";
    setTimeout(() => window.close(), 2000);
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
  dialBtn.setAttribute("aria-selected", String(!isSettings));
  settingsBtn.setAttribute("aria-selected", String(isSettings));
  dialPanel.classList.toggle("active", !isSettings);
  settingsPanel.classList.toggle("active", isSettings);
}

// ---------------------------
// Init
// ---------------------------

document.addEventListener("DOMContentLoaded", async () => {
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

  // HubSpot selection buttons (list pages)
  $("hs-dial-action")?.addEventListener("click", async () => {
    const mode = $("hs-dial-action")?.dataset?.mode;
    if (mode === "launch") return launchHubSpotDialSession();
    return startHubSpotOAuth();
  });

  $("hs-dial-contacts")?.addEventListener("click", async () => {
    if ($("hs-dial-contacts")?.dataset?.mode === "connect") return startHubSpotOAuth();
    return launchHubSpotDialSessionContacts();
  });

  $("hs-dial-companies")?.addEventListener("click", async () => {
    if ($("hs-dial-companies")?.dataset?.mode === "connect") return startHubSpotOAuth();
    return launchHubSpotDialSessionCompanies();
  });

  // HubSpot record buttons (record pages)
  $("hs-record-action")?.addEventListener("click", async () => {
    if ($("hs-record-action")?.dataset?.mode === "connect") return startHubSpotOAuth();
    return launchHubSpotRecordDial();
  });

  $("hs-record-contacts")?.addEventListener("click", async () => {
    if ($("hs-record-contacts")?.dataset?.mode === "connect") return startHubSpotOAuth();
    return launchHubSpotRecordDial("contacts");
  });

  $("hs-record-companies")?.addEventListener("click", async () => {
    if ($("hs-record-companies")?.dataset?.mode === "connect") return startHubSpotOAuth();
    return launchHubSpotRecordDial("companies");
  });

  // HubSpot list-based dial
  $("hs-list-select")?.addEventListener("change", onHsListSelectChange);
  $("hs-dial-from-list")?.addEventListener("click", launchDialSessionFromList);

  $("hs-disconnect")?.addEventListener("click", disconnectHubSpot);

  // Tabs
  $("tab-dial")?.addEventListener("click", () => activateTab("dial"));
  $("tab-settings")?.addEventListener("click", () => activateTab("settings"));

  // Version indicator
  const versionEl = $("ext-version");
  if (versionEl) {
    const manifest = chrome.runtime.getManifest();
    versionEl.textContent = `v${manifest.version}`;
  }

  // 1. Get CRM context from background (includes pageType, recordId, portalId)
  const [activeTab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (activeTab) {
    const resp = await sendToBackground({ type: "GET_CONTEXT", tabId: activeTab.id });
    setCrmHeader(resp?.context || null);
  } else {
    setCrmHeader(null);
  }

  // 2. Check PB connection
  await refreshState();

  // 3. Check HS connection (always, even on non-HS pages — needed for list card)
  await checkHubSpotConnectionState();

  // 4. Apply context-aware visibility
  applyContextVisibility(ACTIVE_CTX, PB_CONNECTED);
});
