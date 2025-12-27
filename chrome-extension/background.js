// background.js — Unified extension service worker
// Fixes HubSpot selection flow + preserves Level 1/2 scan flow

const BASE_URL = "https://extension-dev.phoneburner.biz";

// Default icon set
const DEFAULT_ICON_PATH = {
  16: "icons/icon-16.png",
  32: "icons/icon-32.png",
  48: "icons/icon-48.png",
  128: "icons/icon-128.png",
};

// Track CRM context per tab
const tabContexts = {}; // { [tabId]: { crmId, crmName, level, host, path } }

// Track which tab "owns" the current follow-me session
let currentSession = {
  token: null,
  tabId: null,
  backendBase: null,
};

// -------------------------
// Persist currentSession
// -------------------------

function saveCurrentSessionToStorage() {
  return new Promise((resolve) => {
    chrome.storage.local.set({ pb_current_session: currentSession }, () => resolve());
  });
}

function loadCurrentSessionFromStorage() {
  return new Promise((resolve) => {
    chrome.storage.local.get(["pb_current_session"], (res) => {
      if (res && res.pb_current_session) currentSession = res.pb_current_session;
      resolve(currentSession);
    });
  });
}

// Helper: current active tab id
function getActiveTabId() {
  return new Promise((resolve) => {
    chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
      resolve(tabs && tabs[0] ? tabs[0].id : null);
    });
  });
}

// Register session + tell content script to follow
async function registerSessionForTab(tabId, sessionToken, backendBase) {
  if (!tabId || !sessionToken) return;

  currentSession = {
    token: sessionToken,
    tabId,
    backendBase: backendBase || BASE_URL,
  };
  await saveCurrentSessionToStorage();

  try {
    chrome.tabs.sendMessage(tabId, {
      type: "START_FOLLOW_SESSION",
      sessionToken,
      backendBase: currentSession.backendBase,
    });
  } catch (e) {
    // ignore if tab isn't ready
  }
}

// -------------------------
// client_id management
// -------------------------

async function getClientId() {
  return new Promise((resolve) => {
    chrome.storage.local.get(["pb_unified_client_id"], (res) => {
      if (res.pb_unified_client_id) {
        resolve(res.pb_unified_client_id);
      } else {
        const id =
          typeof crypto !== "undefined" && crypto.randomUUID
            ? crypto.randomUUID()
            : String(Date.now());
        chrome.storage.local.set({ pb_unified_client_id: id }, () => resolve(id));
      }
    });
  });
}

// -------------------------
// API helper (unified)
// -------------------------

async function api(path, body = {}) {
  const client_id = await getClientId();
  const payload = { client_id, ...(body || {}) };

  const res = await fetch(`${BASE_URL}/api/${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Client-Id": client_id },
    body: JSON.stringify(payload),
    credentials: "include",
  });

  const json = await res.json().catch(() => ({}));
  return json;
}

// -------------------------
// Utility: safe tab URL info
// -------------------------

function deriveHostPathFromTabUrl(tabUrl) {
  try {
    const u = new URL(tabUrl);
    return { host: u.hostname, path: u.pathname + u.search };
  } catch (e) {
    return { host: "", path: "" };
  }
}

// -------------------------
// Message handler
// -------------------------

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  // Content script reports CRM context for the tab
  if (msg.type === "CRM_CONTEXT" && sender.tab && sender.tab.id != null) {
    const tabId = sender.tab.id;
    tabContexts[tabId] = msg.context || null;

    chrome.action.setIcon({ tabId, path: DEFAULT_ICON_PATH });
    sendResponse && sendResponse({ ok: true });
    return;
  }

  // Popup asks for context
  if (msg.type === "GET_CONTEXT") {
    const tabId = msg.tabId ?? (sender.tab && sender.tab.id);
    const context =
      tabId != null && Object.prototype.hasOwnProperty.call(tabContexts, tabId)
        ? tabContexts[tabId]
        : null;
    sendResponse({ context });
    return;
  }

  // Everything else async
  (async () => {
    try {
// -------------------------
// Core state / PAT
// -------------------------
if (msg.type === "GET_STATE") {
  const raw = await api("core/state.php");

  console.log("[GET_STATE] raw from core/state.php:", raw);

  // NOTE: server returns connection under raw.data.phoneburner.connected
  const connected =
    raw?.data?.phoneburner?.connected === true ||
    raw?.data?.phoneburner?.connected === 1 ||
    raw?.data?.phoneburner?.connected === "1" ||
    raw?.data?.phoneburner?.connected === "true" ||

    // Back-compat / other shapes (keep these)
    raw?.phoneburner?.connected === true ||
    raw?.phoneburner?.connected === 1 ||
    raw?.phoneburner?.connected === "1" ||
    raw?.phoneburner?.connected === "true" ||
    raw?.phoneburner?.ok === true ||
    raw?.connected === true ||
    raw?.connected === 1 ||
    raw?.connected === "1" ||
    raw?.pb_connected === true ||
    raw?.pb_connected === 1 ||
    raw?.pb_connected === "1" ||
    raw?.has_pat === true ||
    raw?.has_pat === 1 ||
    raw?.has_pat === "1";

  const normalized = {
    ok: raw?.ok === true,
    phoneburner: { connected: !!connected },
    raw,
  };

  console.log("[GET_STATE] normalized returned to popup:", normalized);

  return sendResponse(normalized);
}


if (msg.type === "GET_CLIENT_ID") {
  const client_id = await getClientId();
  return sendResponse({ ok: true, client_id });
}

if (msg.type === "SAVE_PAT") {
  const { pat } = msg;
  const resp = await api("core/oauth_pb_save.php", { pat });
  return sendResponse(resp);
}

if (msg.type === "CLEAR_PAT") {
  const resp = await api("core/oauth_pb_clear.php");
  currentSession = { token: null, tabId: null, backendBase: null };
  await saveCurrentSessionToStorage();
  return sendResponse(resp);
}

      // -------------------------
// HubSpot Level 3: launch from selected
// IMPORTANT: this should NOT scrape phone/email from DOM.
// It only collects IDs; server resolves details via HubSpot API.
// NOW includes dashboard tracking (track_crm_usage.php) like Level 1/2.
// -------------------------
if (msg.type === "HS_LAUNCH_FROM_SELECTED") {
  // Find active HubSpot tab (don’t over-restrict URL; HubSpot changes routes a lot)
  const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
  const hubTab = (tabs || []).find((t) => (t.url || "").includes("app.hubspot.com"));
  if (!hubTab || !hubTab.id) {
    return sendResponse({
      ok: false,
      error: "Open a HubSpot contacts/companies/deals page with selected records.",
    });
  }

  // Ask content script for selection (IDs + context)
  const selected = await new Promise((resolve) => {
    try {
      chrome.tabs.sendMessage(hubTab.id, { type: "HS_GET_SELECTED_IDS" }, (res) => {
        if (chrome.runtime.lastError) {
          return resolve({ error: chrome.runtime.lastError.message });
        }
        resolve(res);
      });
    } catch (e) {
      resolve({ error: String(e) });
    }
  });

  if (!selected || selected.error) {
    return sendResponse({
      ok: false,
      error: selected?.error || "Could not read HubSpot selection.",
    });
  }

  const ids = Array.isArray(selected.ids) ? selected.ids : [];
  const objectType = selected.objectType || "contact";
  const portalId = selected.portalId || null;
  const pageUrl = selected.url || hubTab.url || null;
  const pageTitle = selected.title || null;

  if (!ids.length) {
    return sendResponse({ ok: false, error: "No records selected in this view." });
  }

  // --- NEW: Track HubSpot usage (best-effort) so dashboard stats include Level 3 ---
  try {
    // host/path from the HubSpot tab (not sender tab)
    const hp = deriveHostPathFromTabUrl(hubTab.url || "");
    await api("core/track_crm_usage.php", {
      crm_id: "hubspot",
      host: hp.host || "app.hubspot.com",
      path: hp.path || "",
      level: 3,

      // Optional extra metadata (safe to ignore server-side if not used)
      portal_id: portalId,
      object_type: objectType,
      selected_count: ids.length,
    });
  } catch (e) {
    // ignore tracking failures (should not block dial session launch)
  }

  // Translate objectType -> server mode
  // server expects: "contacts" | "deals" | "companies"
  let mode = "contacts";
  if (objectType === "deal") mode = "deals";
  if (objectType === "company") mode = "companies";

  const resp = await api("crm/hubspot/pb_dialsession_selection.php", {
    mode,
    records: ids.map((id) => ({ id: String(id) })),
    context: {
      objectType,
      portalId,
      url: pageUrl,
      title: pageTitle,
      selectedCount: ids.length,
    },
  });

  const sessionToken = resp.session_token || resp.data?.session_token || null;
  const dialUrl =
    resp.launch_url ||
    resp.dialsession_url ||
    resp.data?.launch_url ||
    resp.data?.dialsession_url ||
    null;

  if (!sessionToken || !dialUrl) {
    return sendResponse({
      ok: false,
      error: resp?.error || "Failed to create dial session (missing token or URL).",
      details: resp,
    });
  }

  // Register follow-me for the HubSpot tab
  await registerSessionForTab(hubTab.id, sessionToken, BASE_URL);

  // Open PB dialer window
  chrome.windows.create({
    url: dialUrl,
    type: "popup",
    focused: true,
    width: 1200,
    height: 900,
  });

  return sendResponse({ ok: true, sessionToken, dialUrl });
}


      // -------------------------
      // Unified Level 1/2: scan -> server -> dialsession
      // -------------------------
      if (msg.type === "SCANNED_CONTACTS") {
        const { contacts, context } = msg || {};
        const ctx = context || {};

        const senderTabId = sender.tab && sender.tab.id != null ? sender.tab.id : null;
        const tabCtx = senderTabId != null ? tabContexts[senderTabId] || null : null;

        const crmId = (tabCtx && tabCtx.crmId) || (ctx && ctx.crm_id) || "generic";

        // host/path (prefer tabCtx, else parse sender.tab.url)
        let host = (tabCtx && tabCtx.host) || "";
        let path = (tabCtx && tabCtx.path) || "";
        if ((!host || !path) && sender.tab && sender.tab.url) {
          const hp = deriveHostPathFromTabUrl(sender.tab.url);
          if (!host) host = hp.host;
          if (!path) path = hp.path;
        }

        const level = (tabCtx && tabCtx.level) || 1;

        // Track CRM usage (best-effort)
        try {
          await api("core/track_crm_usage.php", { crm_id: crmId, host, path, level });
        } catch (e) {
          // ignore
        }

        const resp = await api("crm/generic/dialsession_from_scan.php", {
          contacts,
          context: ctx,
        });

        const sessionToken = resp.session_token || null;
        const dialUrl = resp.launch_url || resp.dialsession_url || null;

        if (sessionToken && dialUrl) {
          const tabId = sender.tab && sender.tab.id != null ? sender.tab.id : null;

          await registerSessionForTab(tabId, sessionToken, BASE_URL);

          chrome.windows.create({
            url: dialUrl,
            type: "popup",
            focused: true,
            width: 1200,
            height: 900,
          });

          return sendResponse({ ok: true, sessionToken, dialUrl });
        }

        const errorMsg =
          resp.error ||
          "Failed to create PhoneBurner dial session (missing launch URL or session token)";

        return sendResponse({ ok: false, error: errorMsg, details: resp });
      }

      // -------------------------
      // Follow session restore/stop
      // -------------------------
      if (msg.type === "GET_ACTIVE_SESSION_FOR_TAB") {
        await loadCurrentSessionFromStorage();

        const senderTabId = sender.tab && sender.tab.id;
        if (
          currentSession.token &&
          currentSession.tabId != null &&
          senderTabId === currentSession.tabId
        ) {
          return sendResponse({
            ok: true,
            sessionToken: currentSession.token,
            backendBase: currentSession.backendBase || BASE_URL,
          });
        }
        return sendResponse({ ok: true, sessionToken: null });
      }

      if (msg.type === "STOP_FOLLOW_SESSION") {
        currentSession = { token: null, tabId: null, backendBase: null };
        await saveCurrentSessionToStorage();
        return sendResponse({ ok: true });
      }

      // -------------------------
      // Server goals/settings
      // -------------------------
      if (msg.type === "GET_USER_SETTINGS") {
        const resp = await api("core/user_settings_get.php");
        return sendResponse(resp);
      }

      if (msg.type === "LOAD_SERVER_GOALS") {
        const resp = await api("core/user_settings_get.php");
        if (!resp || !resp.ok) {
          return sendResponse({
            ok: false,
            error: (resp && resp.error) || "Unable to load goals from server",
          });
        }
        const goals = resp.goals || {};
        return sendResponse({
          ok: true,
          primary: goals.primary || null,
          secondary: goals.secondary || null,
        });
      }

      if (msg.type === "SAVE_GOALS") {
        const { goals } = msg;
        const resp = await api("core/user_settings_save.php", { goals: goals || {} });
        return sendResponse(resp);
      }

      // Back-compat (if anything still sends HS_SESSION_STARTED)
      if (msg.type === "HS_SESSION_STARTED") {
        const sessionToken = msg.session_token;
        const backendBase = msg.backend_base || BASE_URL;

        if (!sessionToken) {
          return sendResponse({ ok: false, error: "Missing session_token for HS_SESSION_STARTED" });
        }

        const tabId = await getActiveTabId();
        if (tabId) {
          await registerSessionForTab(tabId, sessionToken, backendBase);
          return sendResponse({ ok: true });
        }
        return sendResponse({ ok: false, error: "No active tab found for HS_SESSION_STARTED" });
      }

      // -------------------------
      // Unknown
      // -------------------------
      return sendResponse({ ok: false, error: "Unknown message type" });
    } catch (err) {
      console.error("background error", err);
      return sendResponse({ ok: false, error: String(err) });
    }
  })();

  return true; // keep port open for async sendResponse
});

// Clean up contexts when tabs close
chrome.tabs.onRemoved.addListener((tabId) => {
  if (Object.prototype.hasOwnProperty.call(tabContexts, tabId)) {
    delete tabContexts[tabId];
  }
});
