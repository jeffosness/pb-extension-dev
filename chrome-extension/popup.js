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
    if (message instanceof HTMLElement) {
      messageEl.textContent = "";
      messageEl.style.whiteSpace = "normal";
      messageEl.appendChild(message);
    } else {
      messageEl.style.whiteSpace = "pre-wrap";
      messageEl.textContent = message;
    }
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
// What's New / Welcome modal (changelog.js must be loaded first)
// ---------------------------

const PB_VERSION_STORAGE_KEY = "pb_last_seen_version";

/**
 * Compare two semver-like version strings (e.g., "0.4.6" < "0.5.0").
 * Returns negative if a < b, 0 if equal, positive if a > b.
 */
function compareVersions(a, b) {
  const pa = (a || "0").split(".").map(Number);
  const pb = (b || "0").split(".").map(Number);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const diff = (pa[i] || 0) - (pb[i] || 0);
    if (diff !== 0) return diff;
  }
  return 0;
}

/**
 * Check if this is a first install or version upgrade, and show
 * the appropriate modal (welcome or what's-new).
 */
async function checkChangelog() {
  const manifest = chrome.runtime.getManifest();
  const currentVersion = manifest.version;

  const storageData = await new Promise((resolve) => {
    chrome.storage.local.get([PB_VERSION_STORAGE_KEY, "pb_unified_client_id"], (res) => {
      resolve(res || {});
    });
  });

  const stored = storageData[PB_VERSION_STORAGE_KEY] || null;
  const hasClientId = !!storageData["pb_unified_client_id"];

  if (stored === null) {
    if (hasClientId) {
      // Existing user upgrading to a version with changelog support for the first time.
      // Treat as an upgrade from "unknown old version" — show what's-new, not welcome.
      // Fall through to the upgrade logic below by pretending they were on "0.0.0".
      // (The upgrade block will handle showing the latest changelog entry.)
    } else {
      // Genuine first install — show welcome
      if (typeof PB_WELCOME !== "undefined" && PB_WELCOME.title) {
        const welcomeContent = (typeof buildWelcomeMessage === "function")
          ? buildWelcomeMessage()
          : (PB_WELCOME.message || "Welcome!");
        await showAlert(welcomeContent, PB_WELCOME.title);
      }
      chrome.storage.local.set({ [PB_VERSION_STORAGE_KEY]: currentVersion });
      return;
    }
  }

  const effectiveStored = stored || "0.0.0";
  if (compareVersions(currentVersion, effectiveStored) > 0) {
    // Version upgrade — find the most relevant changelog entry
    // Show entries for versions newer than what the user last saw
    if (typeof PB_CHANGELOG !== "undefined") {
      const newEntries = Object.keys(PB_CHANGELOG)
        .filter((v) => compareVersions(v, effectiveStored) > 0)
        .sort(compareVersions);

      if (newEntries.length > 0) {
        // Show the latest entry (most relevant to user)
        const latest = newEntries[newEntries.length - 1];
        const entry = PB_CHANGELOG[latest];

        const message = entry.items.map((item) => "\u2022 " + item).join("\n");
        await showAlert(message, entry.title);
      }
    }
    chrome.storage.local.set({ [PB_VERSION_STORAGE_KEY]: currentVersion });
  }
  // If same version, do nothing
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
let CLOSE_STATE = { connected: false };
let APOLLO_STATE = { connected: false };

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

function isCloseL3(ctx) {
  return !!(ctx && ctx.crmId === "close" && ctx.level === 3);
}

function isApolloL3(ctx) {
  return !!(ctx && ctx.crmId === "apollo" && ctx.level === 3);
}

function applyContextVisibility(ctx, pbConnected) {
  const currentPageCard = $("card-current-page");
  const hsDialCard = $("hubspot-dial-card");
  const hsRecordCard = $("hubspot-record-card");
  const hsListCard = $("hs-list-card");

  const isHS = isHubSpotL3(ctx);
  const isClose = isCloseL3(ctx);
  const isApollo = isApolloL3(ctx);
  const pageType = ctx?.pageType || "other";
  const bothAuth = pbConnected && HS_STATE.connected;

  // Scan & Launch: any non-L3 page (generic scanner may work on obscure CRMs)
  setVisible(currentPageCard, !isHS && !isClose && !isApollo);

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

  // Close CRM cards
  const closeDialCard = $("close-dial-card");
  const closeSettingsCard = $("close-settings-card");
  // Close dial card: show on Close pages
  setVisible(closeDialCard, isClose);

  // Close settings card: show when Close is connected
  setVisible(closeSettingsCard, CLOSE_STATE.connected);
  if (CLOSE_STATE.connected) {
    const closeSettingsStatus = $("close-settings-status");
    const closeDisconnectBtn = $("close-disconnect");
    if (closeSettingsStatus) closeSettingsStatus.textContent = "Connected ✔";
    if (closeDisconnectBtn) closeDisconnectBtn.disabled = false;
  }

  // Populate Close dial card
  if (isClose) {
    refreshCloseDialUi();
  }

  // Apollo CRM cards
  const apolloDialCard = $("apollo-dial-card");
  const apolloSequenceCard = $("apollo-sequence-card");
  const apolloSettingsCard = $("apollo-settings-card");

  // Apollo dial card: show on Apollo People pages
  setVisible(apolloDialCard, isApollo);
  // Apollo sequence card: show when Apollo connected + PB connected (works from any page)
  setVisible(apolloSequenceCard, APOLLO_STATE.connected && pbConnected);
  // Apollo settings card: show when Apollo is connected
  setVisible(apolloSettingsCard, APOLLO_STATE.connected);
  if (APOLLO_STATE.connected) {
    const apolloSettingsStatus = $("apollo-settings-status");
    const apolloDisconnectBtn = $("apollo-disconnect");
    if (apolloSettingsStatus) apolloSettingsStatus.textContent = "Connected \u2714";
    if (apolloDisconnectBtn) apolloDisconnectBtn.disabled = false;
  }

  // Populate Apollo cards
  if (isApollo) {
    refreshApolloDialUi();
  }
  if (APOLLO_STATE.connected && pbConnected) {
    fetchApolloSequences();
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

  let res;
  try {
    res = await fetch(`${BASE_URL}/api/${path}`, {
      method: "POST",
      headers,
      body: JSON.stringify(body),
      credentials: "include",
    });
  } catch (fetchErr) {
    console.error("Network error calling", path, fetchErr);
    return {
      ok: false,
      error: {
        code: "network_error",
        message: "Could not reach the server. Check your internet connection or firewall settings.",
        details: String(fetchErr),
      },
    };
  }

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

// Cache of fetched list metadata for the current popup session
let _hsListsCache = [];
let _hsListsPrefetch = [];    // Original prefetched lists (restored when search is cleared)
let _hsListSearchTimer = null; // Debounce timer for search input

async function fetchHubSpotLists(query = '') {
  const listCard = $("hs-list-card");
  const listSelect = $("hs-list-select");
  const listInfo = $("hs-list-info");
  const listBtn = $("hs-dial-from-list");
  const searchStatus = $("hs-list-search-status");

  if (!listCard || !listSelect) return;

  const isSearch = (query !== '');

  // Reset select state
  listSelect.innerHTML = '<option value="">' + (isSearch ? 'Searching…' : 'Loading lists…') + '</option>';
  listSelect.disabled = true;
  setVisible(listBtn, false);
  if (listInfo) listInfo.textContent = "";

  if (isSearch && searchStatus) {
    searchStatus.textContent = 'Searching…';
    setVisible(searchStatus, true);
    searchStatus.classList.add('loading');
  }

  const message = { type: "HS_FETCH_LISTS" };
  if (isSearch) message.query = query;

  const resp = await sendToBackground(message);

  if (searchStatus) searchStatus.classList.remove('loading');

  if (!resp || !resp.ok || !Array.isArray(resp.lists)) {
    if (!isSearch) {
      setVisible(listCard, false);
      return;
    }
    // Search failed — restore prefetched lists
    if (searchStatus) {
      searchStatus.textContent = 'Search failed. Showing recent lists.';
      setVisible(searchStatus, true);
    }
    populateListDropdown(_hsListsPrefetch);
    return;
  }

  const lists = resp.lists;
  _hsListsCache = lists;
  if (!isSearch) {
    _hsListsPrefetch = lists;
  }

  // Update search status
  if (isSearch && searchStatus) {
    if (lists.length === 0) {
      searchStatus.textContent = 'No lists match \u201c' + query + '\u201d';
    } else {
      searchStatus.textContent = lists.length + ' list' + (lists.length !== 1 ? 's' : '') + ' found';
    }
    setVisible(searchStatus, true);
  } else if (searchStatus) {
    setVisible(searchStatus, false);
  }

  populateListDropdown(lists, isSearch ? 'No matches' : 'No lists found');
}

function populateListDropdown(lists, emptyMessage = 'No lists found') {
  const listSelect = $("hs-list-select");
  const listInfo = $("hs-list-info");

  if (!listSelect) return;

  if (!lists || lists.length === 0) {
    listSelect.innerHTML = '<option value="">' + emptyMessage + '</option>';
    if (listInfo) listInfo.textContent = "";
    return;
  }

  listSelect.innerHTML = '<option value="">Select a list…</option>';
  for (const list of lists) {
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

function onHsListSearchInput() {
  const searchInput = $("hs-list-search");
  const clearBtn = $("hs-list-search-clear");
  if (!searchInput) return;

  const query = searchInput.value.trim();

  // Show/hide clear button
  setVisible(clearBtn, query.length > 0);

  // Clear any pending debounce
  if (_hsListSearchTimer) {
    clearTimeout(_hsListSearchTimer);
    _hsListSearchTimer = null;
  }

  // If empty, restore prefetched lists immediately
  if (query === '') {
    const searchStatus = $("hs-list-search-status");
    if (searchStatus) setVisible(searchStatus, false);
    populateListDropdown(_hsListsPrefetch);
    return;
  }

  // Require at least 2 characters to search
  if (query.length < 2) return;

  // Debounce 300ms
  _hsListSearchTimer = setTimeout(() => {
    fetchHubSpotLists(query);
  }, 300);
}

function onHsListSearchClear() {
  const searchInput = $("hs-list-search");
  const clearBtn = $("hs-list-search-clear");
  const searchStatus = $("hs-list-search-status");

  if (searchInput) {
    searchInput.value = '';
    searchInput.focus();
  }
  setVisible(clearBtn, false);
  if (searchStatus) setVisible(searchStatus, false);

  populateListDropdown(_hsListsPrefetch);
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

// ---------------------------
// L3 Phone Preference (settings)
// ---------------------------

async function loadPhonePreference() {
  const section = $("hs-phone-pref-section");
  const select = $("hs-phone-pref");
  if (!section || !select) return;

  // Only show when HS connected
  if (!HS_STATE.connected) {
    setVisible(section, false);
    return;
  }
  setVisible(section, true);

  // Fetch phone properties and saved preference in parallel
  const [propsResp, prefsResp] = await Promise.all([
    sendToBackground({ type: "HS_FETCH_PHONE_PROPERTIES", object_type: "contacts" }),
    sendToBackground({ type: "GET_CRM_PREFERENCES" }),
  ]);

  const phoneProps = propsResp?.ok ? (propsResp.phone_properties || []) : [];
  const savedPref = prefsResp?.ok
    ? (prefsResp.crm_preferences?.hubspot?.preferred_phone_property_contacts || "")
    : "";

  // Populate dropdown
  select.innerHTML = '<option value="">Default (first available)</option>';
  for (const prop of phoneProps) {
    const opt = document.createElement("option");
    opt.value = prop.name;
    opt.textContent = prop.label || prop.name;
    if (prop.name === savedPref) opt.selected = true;
    select.appendChild(opt);
  }
  select.disabled = false;
}

async function onPhonePrefChange() {
  const select = $("hs-phone-pref");
  const status = $("hs-phone-pref-status");
  if (!select) return;

  const value = select.value; // "" means default
  if (status) {
    status.textContent = "Saving…";
    status.classList.add("loading");
  }

  const resp = await sendToBackground({
    type: "SAVE_CRM_PREFERENCES",
    provider: "hubspot",
    preferences: { preferred_phone_property_contacts: value || null },
  });

  if (status) status.classList.remove("loading");

  if (resp?.ok) {
    if (status) status.textContent = value
      ? "Saved — dial sessions will use this field as primary"
      : "Saved — using default phone field priority";
    setTimeout(() => { if (status) status.textContent = ""; }, 3000);
  } else {
    if (status) status.textContent = "Failed to save preference";
  }
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
// Close CRM connection
// ---------------------------

async function checkCloseConnectionState() {
  try {
    const state = await hsPost("crm/close/state.php");
    CLOSE_STATE.connected = !!state?.close_ready;
  } catch (e) {
    CLOSE_STATE.connected = false;
  }
  return CLOSE_STATE.connected;
}

async function startCloseOAuth() {
  const status = $("close-dial-status");
  if (status) status.textContent = "Starting Close connection…";

  const resp = await hsPost("crm/close/oauth_close_start.php");
  const authUrl = resp?.auth_url;

  if (!authUrl) {
    if (status) status.textContent = "Could not start Close OAuth.";
    await showAlert("Server did not return auth_url. Check server logs.");
    return;
  }

  chrome.tabs.create({ url: authUrl });
  window.close();
}

async function disconnectClose() {
  const confirmed = await showConfirm("Disconnect Close for this session?");
  if (!confirmed) return;

  const btn = $("close-disconnect");
  const settingsStatus = $("close-settings-status");
  if (btn) btn.disabled = true;
  if (settingsStatus) settingsStatus.textContent = "Disconnecting…";

  const resp = await hsPost("crm/close/oauth_disconnect.php");

  if (!resp || resp.ok !== true) {
    const errorMsg = getErrorMessage(resp, "Failed to disconnect Close.");
    if (settingsStatus) settingsStatus.textContent = "Failed to disconnect.";
    if (btn) btn.disabled = false;
    await showAlert(errorMsg);
    return;
  }

  CLOSE_STATE.connected = false;
  applyContextVisibility(ACTIVE_CTX, PB_CONNECTED);
  activateTab("dial");
}

function refreshCloseDialUi() {
  const btn = $("close-dial-action");
  const status = $("close-dial-status");

  if (!CLOSE_STATE.connected) {
    if (btn) {
      btn.textContent = "Connect Close";
      btn.dataset.mode = "connect";
      btn.disabled = false;
    }
    if (status) status.textContent = "Connect Close to launch dial sessions with full contact data.";
  } else if (!PB_CONNECTED) {
    if (btn) {
      btn.textContent = "Launch Dial Session";
      btn.disabled = true;
    }
    if (status) status.textContent = "Save your PhoneBurner PAT first.";
  } else {
    if (btn) {
      btn.textContent = "Launch Dial Session";
      btn.dataset.mode = "launch";
      btn.disabled = false;
    }
    if (status) status.textContent = "";
  }
}

async function launchCloseDialSession() {
  const btn = $("close-dial-action");
  const status = $("close-dial-status");

  if (btn) btn.disabled = true;
  if (status) status.textContent = "Launching dial session…";

  const resp = await sendToBackground({ type: "CLOSE_LAUNCH_FROM_SELECTED" });

  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Failed to launch dial session.");
    if (status) status.textContent = "";
    if (btn) btn.disabled = false;
    await showAlert(errorMsg);
    return;
  }

  if (status) status.textContent = "Dial session launched!";
  window.close();
}

// ---------------------------
// Apollo CRM connection
// ---------------------------

async function checkApolloConnectionState() {
  try {
    const state = await hsPost("crm/apollo/state.php");
    APOLLO_STATE.connected = !!state?.apollo_ready;
  } catch (e) {
    APOLLO_STATE.connected = false;
  }
  return APOLLO_STATE.connected;
}

async function startApolloOAuth() {
  const status = $("apollo-dial-status");
  if (status) status.textContent = "Starting Apollo connection\u2026";

  const resp = await hsPost("crm/apollo/oauth_apollo_start.php");
  const authUrl = resp?.auth_url;

  if (!authUrl) {
    if (status) status.textContent = "Could not start Apollo OAuth.";
    await showAlert("Server did not return auth_url. Check server logs.");
    return;
  }

  chrome.tabs.create({ url: authUrl });
  window.close();
}

async function disconnectApollo() {
  const confirmed = await showConfirm("Disconnect Apollo for this session?");
  if (!confirmed) return;

  const btn = $("apollo-disconnect");
  const settingsStatus = $("apollo-settings-status");
  if (btn) btn.disabled = true;
  if (settingsStatus) settingsStatus.textContent = "Disconnecting\u2026";

  const resp = await hsPost("crm/apollo/oauth_disconnect.php");

  if (!resp || resp.ok !== true) {
    const errorMsg = getErrorMessage(resp, "Failed to disconnect Apollo.");
    if (settingsStatus) settingsStatus.textContent = "Failed to disconnect.";
    if (btn) btn.disabled = false;
    await showAlert(errorMsg);
    return;
  }

  APOLLO_STATE.connected = false;
  applyContextVisibility(ACTIVE_CTX, PB_CONNECTED);
  activateTab("dial");
}

function refreshApolloDialUi() {
  const btn = $("apollo-dial-action");
  const status = $("apollo-dial-status");

  if (!APOLLO_STATE.connected) {
    if (btn) {
      btn.textContent = "Connect Apollo";
      btn.dataset.mode = "connect";
      btn.disabled = false;
    }
    if (status) status.textContent = "Connect Apollo to launch dial sessions with full contact data.";
  } else if (!PB_CONNECTED) {
    if (btn) {
      btn.textContent = "Dial Selected Contacts";
      btn.disabled = true;
    }
    if (status) status.textContent = "Save your PhoneBurner PAT first.";
  } else {
    if (btn) {
      btn.textContent = "Dial Selected Contacts";
      btn.dataset.mode = "launch";
      btn.disabled = false;
    }
    if (status) status.textContent = "Select contacts on the People page, then click to dial.";
  }
}

async function launchApolloDialSession() {
  const btn = $("apollo-dial-action");
  const status = $("apollo-dial-status");

  if (btn) btn.disabled = true;
  if (status) status.textContent = "Launching dial session\u2026";

  const resp = await sendToBackground({ type: "APOLLO_LAUNCH_FROM_SELECTED" });

  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Failed to launch dial session.");
    if (status) status.textContent = "";
    if (btn) btn.disabled = false;
    await showAlert(errorMsg);
    return;
  }

  if (status) status.textContent = "Dial session launched!";
  window.close();
}

// ---------------------------
// Apollo Sequences
// ---------------------------

async function fetchApolloSequences() {
  const select = $("apollo-sequence-select");
  if (!select) return;

  select.innerHTML = '<option value="">Loading sequences\u2026</option>';
  select.disabled = true;

  try {
    const resp = await hsPost("crm/apollo/apollo_sequences.php");
    const sequences = resp?.data?.sequences || resp?.sequences || [];

    select.innerHTML = '<option value="">-- Select a sequence --</option>';

    if (!sequences.length) {
      select.innerHTML = '<option value="">No sequences found</option>';
      select.disabled = true;
      return;
    }

    for (const seq of sequences) {
      const opt = document.createElement("option");
      opt.value = seq.id;
      let displayName = seq.name || "Unnamed";
      if (displayName.length > 40) {
        displayName = displayName.substring(0, 37) + "\u2026";
      }
      const badge = seq.status === "active" ? " \u25CF" : "";
      opt.textContent = displayName + badge;
      opt.title = seq.name;
      select.appendChild(opt);
    }

    select.disabled = false;
  } catch (e) {
    select.innerHTML = '<option value="">Failed to load sequences</option>';
  }
}

async function onApolloSequenceChange() {
  const select = $("apollo-sequence-select");
  const filterSelect = $("apollo-task-filter");
  const preview = $("apollo-task-preview");
  const launchBtn = $("apollo-sequence-launch");

  const sequenceId = select?.value || "";
  if (!sequenceId) {
    if (preview) preview.textContent = "";
    if (launchBtn) launchBtn.disabled = true;
    return;
  }

  const filter = filterSelect?.value || "all_open";
  if (preview) preview.textContent = "Checking call tasks\u2026";
  if (launchBtn) launchBtn.disabled = true;

  try {
    const resp = await hsPost("crm/apollo/apollo_sequence_tasks.php", {
      sequence_id: sequenceId,
      filter: filter,
    });

    const tasks = resp?.data?.tasks || resp?.tasks || [];
    const total = resp?.data?.total || resp?.total || tasks.length;

    if (total === 0) {
      if (preview) preview.textContent = "No open call tasks found.";
      if (launchBtn) launchBtn.disabled = true;
    } else {
      if (preview) preview.textContent = total + " call task" + (total !== 1 ? "s" : "") + " ready to dial";
      if (launchBtn) launchBtn.disabled = false;
    }
  } catch (e) {
    if (preview) preview.textContent = "Failed to check tasks.";
  }
}

async function launchApolloFromTasks() {
  const select = $("apollo-sequence-select");
  const filterSelect = $("apollo-task-filter");
  const launchBtn = $("apollo-sequence-launch");
  const preview = $("apollo-task-preview");

  const sequenceId = select?.value || "";
  const filter = filterSelect?.value || "all_open";

  if (!sequenceId) return;

  if (launchBtn) launchBtn.disabled = true;
  if (preview) preview.textContent = "Launching dial session\u2026";

  const resp = await sendToBackground({
    type: "APOLLO_LAUNCH_FROM_TASKS",
    sequence_id: sequenceId,
    filter: filter,
  });

  if (!resp || !resp.ok) {
    const errorMsg = getErrorMessage(resp, "Failed to launch dial session from tasks.");
    if (preview) preview.textContent = "";
    if (launchBtn) launchBtn.disabled = false;
    await showAlert(errorMsg);
    return;
  }

  if (preview) preview.textContent = "Dial session launched!";
  window.close();
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
  if (pat.length < 20) {
    await showAlert("That doesn't look like a valid PAT. Please check and try again.");
    return;
  }
  if (/\s/.test(pat)) {
    await showAlert("PAT should not contain spaces. Please check for extra whitespace.");
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
  $("hs-list-search")?.addEventListener("input", onHsListSearchInput);
  $("hs-list-search-clear")?.addEventListener("click", onHsListSearchClear);
  $("hs-list-select")?.addEventListener("change", onHsListSelectChange);
  $("hs-dial-from-list")?.addEventListener("click", launchDialSessionFromList);

  $("hs-phone-pref")?.addEventListener("change", onPhonePrefChange);
  $("hs-disconnect")?.addEventListener("click", disconnectHubSpot);

  // Close CRM
  $("close-dial-action")?.addEventListener("click", async () => {
    if ($("close-dial-action")?.dataset?.mode === "connect") return startCloseOAuth();
    return launchCloseDialSession();
  });
  $("close-disconnect")?.addEventListener("click", disconnectClose);

  // Apollo CRM
  $("apollo-dial-action")?.addEventListener("click", async () => {
    if ($("apollo-dial-action")?.dataset?.mode === "connect") return startApolloOAuth();
    return launchApolloDialSession();
  });
  $("apollo-disconnect")?.addEventListener("click", disconnectApollo);
  $("apollo-sequence-select")?.addEventListener("change", onApolloSequenceChange);
  $("apollo-task-filter")?.addEventListener("change", onApolloSequenceChange);
  $("apollo-sequence-launch")?.addEventListener("click", launchApolloFromTasks);

  // Tabs
  $("tab-dial")?.addEventListener("click", () => activateTab("dial"));
  $("tab-settings")?.addEventListener("click", () => activateTab("settings"));

  // Version indicator
  const versionEl = $("ext-version");
  if (versionEl) {
    const manifest = chrome.runtime.getManifest();
    versionEl.textContent = `v${manifest.version}`;
  }

  // 0. Show welcome or what's-new modal (first install or version upgrade)
  await checkChangelog();

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

  // 3b. Check Close connection
  await checkCloseConnectionState();
  await checkApolloConnectionState();

  // 4. Apply context-aware visibility
  applyContextVisibility(ACTIVE_CTX, PB_CONNECTED);

  // 5. Load L3 phone preference (if HS connected)
  if (HS_STATE.connected) {
    loadPhonePreference();
  }
});
