// background.js — Unified extension service worker (on-demand injection + no follow-me toggles)

const BASE_URL = "https://extension-dev.phoneburner.biz";

// Content script file
const CONTENT_SCRIPT_FILES = ["content.js"];

// Track CRM context per tab (populated when content.js runs)
const tabContexts = {}; // { [tabId]: { crmId, crmName, level, host, path } }

// Track which tab "owns" the current follow session
let currentSession = {
  token: null,
  tabId: null,
  backendBase: null,
};

// Default icon set
const DEFAULT_ICON_PATH = {
  16: "icons/icon-16.png",
  32: "icons/icon-32.png",
  48: "icons/icon-48.png",
  128: "icons/icon-128.png",
};

// Persist currentSession
function saveCurrentSessionToStorage() {
  return new Promise((resolve) => {
    chrome.storage.local.set({ pb_current_session: currentSession }, () =>
      resolve(),
    );
  });
}

function loadCurrentSessionFromStorage() {
  return new Promise((resolve) => {
    chrome.storage.local.get(["pb_current_session"], (res) => {
      if (res && res.pb_current_session)
        currentSession = res.pb_current_session;
      resolve(currentSession);
    });
  });
}

// Safe parse host/path
function deriveHostPathFromTabUrl(tabUrl) {
  try {
    const u = new URL(tabUrl);
    return { host: u.hostname, path: u.pathname + u.search };
  } catch (e) {
    return { host: "", path: "" };
  }
}

// Lightweight CRM detection WITHOUT content.js
function detectCrmFromUrl(tabUrl) {
  const { host, path } = deriveHostPathFromTabUrl(tabUrl || "");
  if (!host)
    return {
      host: "",
      path: "",
      crmId: "generic",
      crmName: "Unknown",
      level: 1,
    };

  if (host.includes("app.hubspot.com")) {
    // Detect object type from HubSpot URL pattern: /objects/0-X/
    let objectType = "contact"; // default
    if (path.includes("/objects/0-1/")) objectType = "contact";
    else if (path.includes("/objects/0-2/")) objectType = "company";
    else if (path.includes("/objects/0-3/")) objectType = "deal";

    return { host, path, crmId: "hubspot", crmName: "HubSpot", level: 3, objectType };
  }
  if (host.includes("pipedrive.com"))
    return { host, path, crmId: "pipedrive", crmName: "Pipedrive", level: 2 };
  if (host.includes("lightning.force.com"))
    return { host, path, crmId: "salesforce", crmName: "Salesforce", level: 1 };
  if (host.includes("crm.zoho.com"))
    return { host, path, crmId: "zoho", crmName: "Zoho CRM", level: 1 };
  if (host.includes("monday.com"))
    return { host, path, crmId: "monday", crmName: "monday.com", level: 1 };

  return { host, path, crmId: "generic", crmName: host, level: 1 };
}

// Ensure content script is injected on a tab (top frame only; safe if already injected)
async function ensureContentScript(tabId) {
  if (!tabId) return;

  const pong = await new Promise((resolve) => {
    try {
      chrome.tabs.sendMessage(
        tabId,
        { type: "PING" },
        { frameId: 0 },
        (res) => {
          if (chrome.runtime.lastError) return resolve(null);
          resolve(res);
        },
      );
    } catch (e) {
      resolve(null);
    }
  });

  if (pong && pong.ok) return;

  await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    files: CONTENT_SCRIPT_FILES,
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

  await ensureContentScript(tabId);

  try {
    chrome.tabs.sendMessage(
      tabId,
      {
        type: "START_FOLLOW_SESSION",
        sessionToken,
        backendBase: currentSession.backendBase,
      },
      { frameId: 0 },
      () => {},
    );
  } catch (e) {}
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
        chrome.storage.local.set({ pb_unified_client_id: id }, () =>
          resolve(id),
        );
      }
    });
  });
}

// -------------------------
// API helper (unified)
// -------------------------

async function api(path, body = {}, baseUrl = BASE_URL) {
  const client_id = await getClientId();
  const payload = { client_id, ...(body || {}) };

  const res = await fetch(`${baseUrl}/api/${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Client-Id": client_id },
    body: JSON.stringify(payload),
    credentials: "include",
  });

  const json = await res.json().catch(() => ({}));
  return json;
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

  // Popup asks for context (works even if content.js not injected yet)
  if (msg.type === "GET_CONTEXT") {
    const tabId = msg.tabId ?? (sender.tab && sender.tab.id);

    (async () => {
      try {
        if (tabId == null) return sendResponse({ context: null });

        // Prefer context from injected content.js if we have it
        if (
          Object.prototype.hasOwnProperty.call(tabContexts, tabId) &&
          tabContexts[tabId]
        ) {
          return sendResponse({ context: tabContexts[tabId] });
        }

        // Otherwise infer from the active tab URL
        const [tab] = await chrome.tabs.query({
          active: true,
          currentWindow: true,
        });
        const inferred = detectCrmFromUrl(tab?.url || "");
        return sendResponse({ context: inferred });
      } catch {
        return sendResponse({ context: null });
      }
    })();

    return true;
  }

  (async () => {
    try {
      // -------------------------
      // SCAN + LAUNCH (Level 1/2)
      // Send SCAN_PAGE to TOP FRAME ONLY (frameId: 0) to avoid iframe "ignored_iframe"
      // -------------------------
      if (msg.type === "SCAN_AND_LAUNCH") {
        const tabId = msg.tabId || (sender.tab && sender.tab.id);
        if (!tabId) return sendResponse({ ok: false, error: "Missing tabId" });

        await ensureContentScript(tabId);

        // Use callback-style sendMessage to avoid async channel timing issues
        chrome.tabs.sendMessage(
          tabId,
          { type: "SCAN_PAGE" },
          { frameId: 0 },
          (resp) => {
            if (chrome.runtime.lastError) {
              return sendResponse({
                ok: false,
                error: chrome.runtime.lastError.message,
              });
            }
            return sendResponse(resp || { ok: true });
          },
        );

        return; // sendResponse happens in callback
      }

      // -------------------------
      // Core state / PAT
      // -------------------------
      if (msg.type === "GET_STATE") {
        const raw = await api("core/state.php");

        const connected =
          raw?.data?.phoneburner?.connected === true ||
          raw?.data?.phoneburner?.connected === 1 ||
          raw?.data?.phoneburner?.connected === "1" ||
          raw?.data?.phoneburner?.connected === "true" ||
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

        return sendResponse({
          ok: raw?.ok === true,
          phoneburner: { connected: !!connected },
          raw,
        });
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
      // HubSpot L3: launch from selected
      // -------------------------
      if (msg.type === "HS_LAUNCH_FROM_SELECTED") {
        const tabs = await chrome.tabs.query({
          active: true,
          currentWindow: true,
        });
        const hubTab = (tabs || []).find((t) =>
          (t.url || "").includes("app.hubspot.com"),
        );
        if (!hubTab || !hubTab.id) {
          return sendResponse({
            ok: false,
            error: "Open a HubSpot view with selected records.",
          });
        }

        await ensureContentScript(hubTab.id);

        const selected = await new Promise((resolve) => {
          chrome.tabs.sendMessage(
            hubTab.id,
            { type: "HS_GET_SELECTED_IDS" },
            { frameId: 0 },
            (res) => {
              if (chrome.runtime.lastError)
                return resolve({ error: chrome.runtime.lastError.message });
              resolve(res);
            },
          );
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

        if (!ids.length)
          return sendResponse({
            ok: false,
            error: "No records selected in this view.",
          });

        // Track usage (best effort)
        try {
          const hp = deriveHostPathFromTabUrl(hubTab.url || "");
          await api("core/track_crm_usage.php", {
            crm_id: "hubspot",
            host: hp.host || "app.hubspot.com",
            path: hp.path || "",
            level: 3,
            portal_id: portalId,
            object_type: objectType,
            selected_count: ids.length,
          });
        } catch (e) {}

        let mode = "contacts";
        if (objectType === "deal") mode = "deals";
        if (objectType === "company") mode = "companies";

        // Extract call_target from message (for company dual-mode)
        const callTarget = msg.call_target || null;

        const resp = await api("crm/hubspot/pb_dialsession_selection.php", {
          mode,
          call_target: callTarget, // NEW: Pass call_target to server
          records: ids.map((id) => ({ id: String(id) })),
          context: {
            objectType,
            portalId,
            url: selected.url || hubTab.url || null,
            title: selected.title || null,
            selectedCount: ids.length,
          },
        });

        const sessionToken =
          resp.session_token || resp.data?.session_token || null;
        const dialUrl =
          resp.launch_url ||
          resp.dialsession_url ||
          resp.data?.launch_url ||
          resp.data?.dialsession_url ||
          null;

        if (!sessionToken || !dialUrl) {
          return sendResponse({
            ok: false,
            error: resp?.error || "Failed to create dial session.",
            details: resp,
          });
        }

        await registerSessionForTab(hubTab.id, sessionToken, BASE_URL);

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
      // Level 1/2: scanned -> server -> dialsession
      // -------------------------
      if (msg.type === "SCANNED_CONTACTS") {
        const { contacts, context } = msg || {};
        const ctx = context || {};

        const senderTabId =
          sender.tab && sender.tab.id != null ? sender.tab.id : null;
        const tabCtx =
          senderTabId != null ? tabContexts[senderTabId] || null : null;

        const crmId =
          (tabCtx && tabCtx.crmId) || (ctx && ctx.crm_id) || "generic";

        let host = (tabCtx && tabCtx.host) || "";
        let path = (tabCtx && tabCtx.path) || "";
        if ((!host || !path) && sender.tab && sender.tab.url) {
          const hp = deriveHostPathFromTabUrl(sender.tab.url);
          if (!host) host = hp.host;
          if (!path) path = hp.path;
        }

        const level = (tabCtx && tabCtx.level) || 1;

        try {
          await api("core/track_crm_usage.php", {
            crm_id: crmId,
            host,
            path,
            level,
          });
        } catch (e) {}

        const resp = await api("crm/generic/dialsession_from_scan.php", {
          contacts,
          context: ctx,
        });

        const sessionToken = resp.session_token || null;
        const dialUrl = resp.launch_url || resp.dialsession_url || null;

        if (sessionToken && dialUrl) {
          const tabId =
            sender.tab && sender.tab.id != null ? sender.tab.id : null;
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

        return sendResponse({
          ok: false,
          error:
            resp.error || "Failed to create dial session (missing token/url).",
          details: resp,
        });
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
        // Load session from storage first (service workers restart)
        await loadCurrentSessionFromStorage();

        // Notify server that session was explicitly stopped (for dashboard metrics)
        if (currentSession.token) {
          try {
            const backendBase = currentSession.backendBase || BASE_URL;
            await api("core/session_stop.php", {
              session_token: currentSession.token,
            }, backendBase);
          } catch (err) {
            console.warn("Failed to notify server of session stop:", err);
            // Continue anyway - this is best-effort for metrics
          }
        }

        // Notify content script to stop follow UI
        if (currentSession.tabId != null) {
          try {
            chrome.tabs.sendMessage(
              currentSession.tabId,
              { type: "STOP_FOLLOW_SESSION_UI" },
              { frameId: 0 },
              () => {}
            );
          } catch (e) {
            // Ignore errors if tab is closed or content script not available
          }
        }

        currentSession = { token: null, tabId: null, backendBase: null };
        await saveCurrentSessionToStorage();
        return sendResponse({ ok: true });
      }

      // -------------------------
      // Emergency reset (if extension gets stuck)
      // -------------------------
      if (msg.type === "FORCE_RESET_ALL_STATE") {
        console.warn("FORCE_RESET_ALL_STATE invoked - clearing all extension state");

        // Clear session
        currentSession = { token: null, tabId: null, backendBase: null };
        await saveCurrentSessionToStorage();

        // Clear all storage
        await new Promise(resolve => chrome.storage.local.clear(resolve));

        // Clear tab contexts
        for (const tabId in tabContexts) {
          delete tabContexts[tabId];
        }

        return sendResponse({
          ok: true,
          message: "All extension state cleared. Reload any open CRM tabs and reopen the popup."
        });
      }

      // -------------------------
      // Server goals/settings
      // -------------------------
      if (msg.type === "LOAD_SERVER_GOALS") {
        const resp = await api("core/user_settings_get.php");
        if (!resp || !resp.ok) {
          return sendResponse({
            ok: false,
            error: resp?.error || "Unable to load goals from server",
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
        const resp = await api("core/user_settings_save.php", {
          goals: goals || {},
        });
        return sendResponse(resp);
      }

      return sendResponse({ ok: false, error: "Unknown message type" });
    } catch (err) {
      console.error("background error", err);
      return sendResponse({ ok: false, error: String(err) });
    }
  })();

  return true;
});

// Clean up contexts when tabs close
chrome.tabs.onRemoved.addListener((tabId) => {
  if (Object.prototype.hasOwnProperty.call(tabContexts, tabId))
    delete tabContexts[tabId];
});

// Re-inject after navigation/reload IF:
// - we have an active session
// - this is the session-owning tab
//
// NOTE: This does NOT run until a dial session is started (currentSession is set in registerSessionForTab).
chrome.tabs.onUpdated.addListener(async (tabId, changeInfo) => {
  if (changeInfo.status !== "complete") return;

  try {
    await loadCurrentSessionFromStorage();
    if (!currentSession?.token || currentSession.tabId !== tabId) return;

    await ensureContentScript(tabId);

    chrome.tabs.sendMessage(
      tabId,
      {
        type: "START_FOLLOW_SESSION",
        sessionToken: currentSession.token,
        backendBase: currentSession.backendBase || BASE_URL,
        restore: true,
      },
      { frameId: 0 },
      () => {},
    );
  } catch (e) {}
});
