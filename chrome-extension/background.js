// background.js

const BASE_URL = "https://extension-dev.phoneburner.biz";

// Default icon set (you can customize per CRM later if you like)
const DEFAULT_ICON_PATH = {
  16: "icons/icon-16.png",
  32: "icons/icon-32.png",
  48: "icons/icon-48.png",
  128: "icons/icon-128.png",
};

// Track CRM context per tab
const tabContexts = {}; // { [tabId]: { crmId, crmName, level, host, path } }

// Track which tab "owns" the current session
let currentSession = {
  token: null,
  tabId: null,
  backendBase: null, // where the SSE endpoint lives
};

// --- Persist currentSession so it survives service worker restarts ---

function saveCurrentSessionToStorage() {
  return new Promise((resolve) => {
    chrome.storage.local.set({ pb_current_session: currentSession }, () => {
      resolve();
    });
  });
}

function loadCurrentSessionFromStorage() {
  return new Promise((resolve) => {
    chrome.storage.local.get(["pb_current_session"], (res) => {
      if (res && res.pb_current_session) {
        currentSession = res.pb_current_session;
      }
      resolve(currentSession);
    });
  });
}

// Helper: currently active tab id (used for HubSpot Level 3)
function getActiveTabId() {
  return new Promise((resolve) => {
    chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
      resolve(tabs && tabs[0] ? tabs[0].id : null);
    });
  });
}

// Re-use this for both unified and HubSpot sessions
async function registerSessionForTab(tabId, sessionToken, backendBase) {
  if (!tabId || !sessionToken) return;

  currentSession = {
    token: sessionToken,
    tabId: tabId,
    backendBase: backendBase || BASE_URL,
  };
  await saveCurrentSessionToStorage();

  chrome.tabs.sendMessage(tabId, {
    type: "START_FOLLOW_SESSION",
    sessionToken,
    backendBase: currentSession.backendBase,
  });
}

// --- client_id management (unified) ---

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
        chrome.storage.local.set({ pb_unified_client_id: id }, () =>
          resolve(id)
        );
      }
    });
  });
}

// --- API helper ---

async function api(path, body = {}) {
  const client_id = await getClientId();

  const payload = {
    client_id,
    ...body,
  };

  const res = await fetch(`${BASE_URL}/api/${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });

  const json = await res.json().catch(() => ({}));
  return json;
}


// --- Message handlers from popup & content scripts ---

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  // --- Content script tells us its CRM context for this tab ---
  if (msg.type === "CRM_CONTEXT" && sender.tab && sender.tab.id != null) {
    const tabId = sender.tab.id;
    tabContexts[tabId] = msg.context || null;

    chrome.action.setIcon({
      tabId,
      path: DEFAULT_ICON_PATH,
    });

    sendResponse && sendResponse({ ok: true });
    return;
  }


  // --- Popup asks for context for a given tab ---
  if (msg.type === "GET_CONTEXT") {
    const tabId = msg.tabId ?? (sender.tab && sender.tab.id);
    const context =
      tabId != null && Object.prototype.hasOwnProperty.call(tabContexts, tabId)
        ? tabContexts[tabId]
        : null;
    sendResponse({ context });
    return;
  }

  // --- Everything else uses async flow ---
  (async () => {
    try {
      if (msg.type === "GET_STATE") {
        const state = await api("core/state.php");
        sendResponse(state);

      } else if (msg.type === "GET_CLIENT_ID") {
        const client_id = await getClientId();
        sendResponse({ ok: true, client_id });

      } else if (msg.type === "SAVE_PAT") {
        const { pat } = msg;
        const resp = await api("core/oauth_pb_save.php", { pat });
        sendResponse(resp);

      } else if (msg.type === "CLEAR_PAT") {
        const resp = await api("core/oauth_pb_clear.php");
        currentSession = { token: null, tabId: null, backendBase: null };
        await saveCurrentSessionToStorage();
        sendResponse(resp);

      } else if (msg.type === "SCANNED_CONTACTS") {
        // Unified Level 1/2 flow
        const { contacts, context } = msg || {};
        const ctx = context || {};

        // --- 1) Determine CRM context for logging ---
        const senderTabId = sender.tab && sender.tab.id != null ? sender.tab.id : null;
        const tabCtx = senderTabId != null ? tabContexts[senderTabId] || null : null;

        // crmId: prefer what the content script detected, fall back to "generic"
        const crmId =
          (tabCtx && tabCtx.crmId) ||
          (ctx && ctx.crm_id) ||
          "generic";

            // 🔍 SF DEBUG: log what background actually sees before doing anything else
  if (crmId === "salesforce") {
    console.log("[PB-UNIFIED][BG] SF contacts received from content:", contacts.length, contacts);
    console.log("[PB-UNIFIED][BG] SF context:", ctx, "tabCtx:", tabCtx);
  }

        // host/path: prefer stored tab context, otherwise derive from sender.tab.url
        let host = (tabCtx && tabCtx.host) || "";
        let path = (tabCtx && tabCtx.path) || "";

        if ((!host || !path) && sender.tab && sender.tab.url) {
          try {
            const u = new URL(sender.tab.url);
            if (!host) host = u.hostname;
            if (!path) path = u.pathname + u.search;
          } catch (e) {
            // ignore URL parse errors; host/path will just be empty
          }
        }

        // level: 1/2/3 – default to 1 if not known
        const level = (tabCtx && tabCtx.level) || 1;

        // --- 2) Log CRM usage for this Scan & Launch attempt ---
        // We want this to happen even if the dial session fails.
        try {
          await api("core/track_crm_usage.php", {
            crm_id: crmId,
            host,
            path,
            level,
          });
        } catch (e) {
          console.warn("track_crm_usage failed:", e);
        }

        // --- 3) Create the PhoneBurner dial session as before ---
        const resp = await api("crm/generic/dialsession_from_scan.php", {
          contacts,
          context: ctx,
        });

        const sessionToken = resp.session_token || null;
        const dialUrl = resp.launch_url || resp.dialsession_url || null;

        if (sessionToken && dialUrl) {
          const tabId =
            sender.tab && sender.tab.id != null ? sender.tab.id : null;

          // Register session with backend
          await registerSessionForTab(tabId, sessionToken, BASE_URL);

          chrome.windows.create({
            url: dialUrl,
            type: "popup",
            focused: true,
            width: 1200,
            height: 900,
          });

          sendResponse({
            ok: true,
            sessionToken,
            dialUrl,
          });
        } else {
          const errorMsg =
            resp.error ||
            "Failed to create PhoneBurner dial session (missing launch URL or session token)";
          console.error("SCANNED_CONTACTS error:", resp);

          sendResponse({
            ok: false,
            error: errorMsg,
            details: resp,
          });
        }


      } else if (msg.type === "GET_ACTIVE_SESSION_FOR_TAB") {
        // Content script asks whether THIS tab should auto-follow
        await loadCurrentSessionFromStorage();

        const senderTabId = sender.tab && sender.tab.id;
        if (
          currentSession.token &&
          currentSession.tabId != null &&
          senderTabId === currentSession.tabId
        ) {
          sendResponse({
            ok: true,
            sessionToken: currentSession.token,
            backendBase: currentSession.backendBase || BASE_URL,
          });
        } else {
          sendResponse({ ok: true, sessionToken: null });
        }

      } else if (msg.type === "STOP_FOLLOW_SESSION") {
        currentSession = { token: null, tabId: null, backendBase: null };
        await saveCurrentSessionToStorage();
        sendResponse({ ok: true });

      } else if (msg.type === "GET_USER_SETTINGS") {
        // Generic “get everything” – still here for any settings UI
        const resp = await api("core/user_settings_get.php");
        sendResponse(resp);

      } else if (msg.type === "LOAD_SERVER_GOALS") {
        // Lightweight endpoint used by content.js to sync goalConfig
        const resp = await api("core/user_settings_get.php");

        if (!resp || !resp.ok) {
          sendResponse({
            ok: false,
            error: (resp && resp.error) || "Unable to load goals from server",
          });
        } else {
          const goals = resp.goals || {};
          sendResponse({
            ok: true,
            primary: goals.primary || null,
            secondary: goals.secondary || null,
          });
        }

      } else if (msg.type === "SAVE_GOALS") {
        // Save goals on the server so they follow the user between browsers
        const { goals } = msg;
        const resp = await api("core/user_settings_save.php", {
          goals: goals || {},
        });
        sendResponse(resp);

      } else if (msg.type === "HS_SESSION_STARTED") {
        // HubSpot Level 3 flow – called from popup.js
        const sessionToken = msg.session_token;
        const backendBase = msg.backend_base || BASE_URL;

        if (!sessionToken) {
          sendResponse({
            ok: false,
            error: "Missing session_token for HS_SESSION_STARTED",
          });
          return;
        }

        const tabId = await getActiveTabId();
        if (tabId) {
          await registerSessionForTab(tabId, sessionToken, backendBase);
          sendResponse({ ok: true });
        } else {
          sendResponse({
            ok: false,
            error: "No active tab found for HS_SESSION_STARTED",
          });
        }

      } else {
        sendResponse({ ok: false, error: "Unknown message type" });
      }

    } catch (err) {
      console.error("background error", err);
      sendResponse({ ok: false, error: String(err) });
    }
  })();

  return true;
});

chrome.tabs.onRemoved.addListener((tabId) => {
  if (Object.prototype.hasOwnProperty.call(tabContexts, tabId)) {
    delete tabContexts[tabId];
  }
});
