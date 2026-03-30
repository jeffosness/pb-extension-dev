// background.js — Unified extension service worker (on-demand injection + no follow-me toggles)

importScripts("crm_config.js");

const BASE_URL = "https://extension-dev.phoneburner.biz";

// Content script files (crm_config.js must be first — defines CRM_REGISTRY)
const CONTENT_SCRIPT_FILES = ["crm_config.js", "content.js"];

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

  if (host.includes("hubspot.com")) {
    // Extract portalId from /contacts/{portalId}/
    let portalId = null;
    const portalMatch = path.match(/\/contacts\/(\d+)\//);
    if (portalMatch) portalId = portalMatch[1];

    // Detect page type and object context from URL
    let pageType = "other";
    let objectType = null;
    let recordId = null;

    // Record pages: /record/0-X/{recordId}
    const recordMatch = path.match(/\/record\/(0-[0-9]+)\/(\d+)/);
    if (recordMatch) {
      pageType = "record";
      recordId = recordMatch[2];
      const typeId = recordMatch[1];
      if (typeId === "0-1") objectType = "contact";
      else if (typeId === "0-2") objectType = "company";
      else if (typeId === "0-3") objectType = "deal";
    }
    // List pages: /objects/0-X/
    else if (path.match(/\/objects\/(0-[0-9]+)\//)) {
      pageType = "list";
      const listMatch = path.match(/\/objects\/(0-[0-9]+)\//);
      const typeId = listMatch[1];
      if (typeId === "0-1") objectType = "contact";
      else if (typeId === "0-2") objectType = "company";
      else if (typeId === "0-3") objectType = "deal";
    }

    return { host, path, crmId: "hubspot", crmName: "HubSpot", level: 3, objectType, pageType, recordId, portalId };
  }
  // Look up all other CRMs from shared registry
  for (const crm of CRM_REGISTRY) {
    const matches = Array.isArray(crm.hostMatch) ? crm.hostMatch : [crm.hostMatch];
    if (matches.some((m) => m && host.includes(m))) {
      return { host, path, crmId: crm.id, crmName: crm.displayName, level: crm.level || 1 };
    }
  }

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
async function registerSessionForTab(tabId, sessionToken, tempCode, backendBase) {
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
        tempCode,
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

  let res;
  try {
    res = await fetch(`${baseUrl}/api/${path}`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Client-Id": client_id },
      body: JSON.stringify(payload),
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

        // Get tab URL to always detect objectType
        const [tab] = await chrome.tabs.query({
          active: true,
          currentWindow: true,
        });
        const urlContext = detectCrmFromUrl(tab?.url || "");

        // If we have cached context from content script, merge with URL detection
        if (
          Object.prototype.hasOwnProperty.call(tabContexts, tabId) &&
          tabContexts[tabId]
        ) {
          // Merge: prefer cached context but always use URL-detected fields
          return sendResponse({
            context: {
              ...tabContexts[tabId],
              objectType: urlContext.objectType,  // Always use URL-detected objectType
              pageType: urlContext.pageType,
              recordId: urlContext.recordId,
              portalId: urlContext.portalId || tabContexts[tabId].portalId,
            }
          });
        }

        // Otherwise use URL-inferred context
        return sendResponse({ context: urlContext });
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
          (t.url || "").includes("hubspot.com"),
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
            launch_source: "selection",
          });
        } catch (e) {}

        let mode = "contacts";
        if (objectType === "deal") mode = "deals";
        if (objectType === "company") mode = "companies";

        // Extract call_target from message (for company dual-mode)
        const callTarget = msg.call_target || null;

        // Extract the actual HubSpot hostname for regional subdomain support
        const hsHost = deriveHostPathFromTabUrl(hubTab.url || "").host || "";

        const resp = await api("crm/hubspot/pb_dialsession_selection.php", {
          mode,
          call_target: callTarget, // NEW: Pass call_target to server
          hs_host: hsHost,
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
        const tempCode =
          resp.temp_code || resp.data?.temp_code || null;
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

        await registerSessionForTab(hubTab.id, sessionToken, tempCode, BASE_URL);

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
      // HubSpot L3: launch from single record
      // -------------------------
      if (msg.type === "HS_LAUNCH_FROM_RECORD") {
        const recordId = msg.recordId;
        const objectType = msg.objectType || "contact";
        const portalId = msg.portalId || null;
        const callTarget = msg.call_target || null;

        if (!recordId) {
          return sendResponse({ ok: false, error: "No record ID available." });
        }

        let mode = "contacts";
        if (objectType === "deal") mode = "deals";
        if (objectType === "company") mode = "companies";

        // Get the active tab URL for regional HubSpot subdomain support
        const recordTabs = await chrome.tabs.query({
          active: true,
          currentWindow: true,
        });
        const recordTabUrl = recordTabs?.[0]?.url || "";
        const recordHsHost = deriveHostPathFromTabUrl(recordTabUrl).host || "";

        // Track usage (best effort)
        try {
          await api("core/track_crm_usage.php", {
            crm_id: "hubspot",
            host: recordHsHost || "app.hubspot.com",
            level: 3,
            portal_id: portalId,
            object_type: objectType,
            launch_source: "record",
          });
        } catch (e) {}

        const resp = await api("crm/hubspot/pb_dialsession_selection.php", {
          mode,
          call_target: callTarget,
          hs_host: recordHsHost,
          records: [{ id: String(recordId) }],
          context: {
            objectType,
            portalId,
            selectedCount: 1,
          },
        });

        const sessionToken =
          resp.session_token || resp.data?.session_token || null;
        const tempCode =
          resp.temp_code || resp.data?.temp_code || null;
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

        // Register follow on current tab (already on the record page)
        const tabs = await chrome.tabs.query({
          active: true,
          currentWindow: true,
        });
        const followTabId = tabs?.[0]?.id || null;
        if (followTabId) {
          await registerSessionForTab(followTabId, sessionToken, tempCode, BASE_URL);
        }

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
      // HubSpot L3: fetch available lists
      // -------------------------
      if (msg.type === "HS_FETCH_LISTS") {
        const body = {};
        if (msg.query && typeof msg.query === 'string') {
          body.query = msg.query;
        }
        const resp = await api("crm/hubspot/hs_lists.php", body);

        if (!resp || resp.ok !== true) {
          return sendResponse({
            ok: false,
            error: resp?.error || "Failed to fetch HubSpot lists.",
          });
        }

        return sendResponse({
          ok: true,
          lists: resp.data?.lists || resp.lists || [],
        });
      }

      // -------------------------
      // L3: fetch phone properties for primary phone preference
      // -------------------------
      if (msg.type === "HS_FETCH_PHONE_PROPERTIES") {
        const objectType = msg.object_type || "contacts";
        const resp = await api("crm/hubspot/hs_phone_properties.php", {
          object_type: objectType,
        });

        if (!resp || resp.ok !== true) {
          return sendResponse({
            ok: false,
            error: resp?.error || "Failed to fetch phone properties.",
          });
        }

        const phoneProps = resp.data?.phone_properties || resp.phone_properties || [];
        return sendResponse({ ok: true, phone_properties: phoneProps });
      }

      // -------------------------
      // L3: save CRM preferences (e.g., preferred phone property)
      // -------------------------
      if (msg.type === "SAVE_CRM_PREFERENCES") {
        const provider = msg.provider || "hubspot";
        const preferences = msg.preferences || {};

        const resp = await api("core/user_settings_save.php", {
          crm_preferences: { [provider]: preferences },
        });

        if (!resp || resp.ok !== true) {
          return sendResponse({
            ok: false,
            error: resp?.error || "Failed to save preferences.",
          });
        }

        return sendResponse({ ok: true });
      }

      // -------------------------
      // L3: load CRM preferences
      // -------------------------
      if (msg.type === "GET_CRM_PREFERENCES") {
        const resp = await api("core/user_settings_get.php");

        if (!resp || resp.ok !== true) {
          return sendResponse({
            ok: false,
            error: resp?.error || "Failed to load preferences.",
          });
        }

        const crmPrefs = resp.data?.crm_preferences || resp.crm_preferences || {};
        return sendResponse({ ok: true, crm_preferences: crmPrefs });
      }

      // -------------------------
      // HubSpot L3: launch from list
      // -------------------------
      if (msg.type === "HS_LAUNCH_FROM_LIST") {
        const listId = msg.list_id;
        const objectType = msg.object_type || "contacts";

        if (!listId) {
          return sendResponse({ ok: false, error: "No list selected." });
        }

        // Find the active HubSpot tab for portal_id and session registration
        const tabs = await chrome.tabs.query({
          active: true,
          currentWindow: true,
        });
        const hubTab = (tabs || []).find((t) =>
          (t.url || "").includes("hubspot.com"),
        );

        // Extract portal_id from URL if on a HubSpot tab, fallback to message
        let portalId = msg.portal_id || null;
        if (hubTab?.url) {
          const portalMatch = hubTab.url.match(/\/contacts\/(\d+)\//);
          if (portalMatch) portalId = portalMatch[1];
        }

        // Track usage (best effort)
        try {
          const hp = deriveHostPathFromTabUrl(hubTab?.url || "");
          await api("core/track_crm_usage.php", {
            crm_id: "hubspot",
            host: hp.host || "app.hubspot.com",
            path: hp.path || "",
            level: 3,
            portal_id: portalId,
            object_type: objectType,
            launch_source: "list",
          });
        } catch (e) {}

        // Extract actual HubSpot hostname for regional subdomain support
        const listHsHost = deriveHostPathFromTabUrl(hubTab?.url || "").host || "";

        const resp = await api("crm/hubspot/pb_dialsession_from_list.php", {
          list_id: listId,
          portal_id: portalId || "",
          object_type: objectType,
          hs_host: listHsHost,
        });

        const sessionToken =
          resp.session_token || resp.data?.session_token || null;
        const tempCode =
          resp.temp_code || resp.data?.temp_code || null;
        const dialUrl =
          resp.launch_url ||
          resp.dialsession_url ||
          resp.data?.launch_url ||
          resp.data?.dialsession_url ||
          null;

        if (!sessionToken || !dialUrl) {
          return sendResponse({
            ok: false,
            error: resp?.error || "Failed to create dial session from list.",
            details: resp,
          });
        }

        // Register follow session — prefer HubSpot tab, fallback to any active tab
        // (on non-HS pages, the first SSE navigation will take them to HubSpot)
        const followTabId = hubTab?.id || tabs?.[0]?.id || null;
        if (followTabId) {
          await registerSessionForTab(followTabId, sessionToken, tempCode, BASE_URL);
        }

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
            object_type: "contacts",
            launch_source: "scan",
          });
        } catch (e) {}

        const resp = await api("crm/generic/dialsession_from_scan.php", {
          contacts,
          context: ctx,
        });

        const sessionToken = resp.session_token || null;
        const tempCode = resp.temp_code || null;
        const dialUrl = resp.launch_url || resp.dialsession_url || null;

        if (sessionToken && dialUrl) {
          const tabId =
            sender.tab && sender.tab.id != null ? sender.tab.id : null;
          await registerSessionForTab(tabId, sessionToken, tempCode, BASE_URL);

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

      if (msg.type === "REFRESH_SSE_CODE") {
        const { sessionToken } = msg;
        try {
          const resp = await api("core/refresh_sse_code.php", {
            session_token: sessionToken,
          });
          return sendResponse({
            ok: !!resp?.ok,
            temp_code: resp?.temp_code || null,
          });
        } catch (e) {
          console.error("REFRESH_SSE_CODE failed:", e);
          return sendResponse({ ok: false, error: e.message });
        }
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
      const errStr = String(err);
      let userMessage = errStr;
      if (/fetch|network|blocked|refused|timeout|abort/i.test(errStr)) {
        userMessage = "Could not reach the server. Check your internet connection or firewall settings.";
      }
      return sendResponse({
        ok: false,
        error: { code: "internal_error", message: userMessage, details: errStr },
      });
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

    // Fetch a fresh temp code for the restored session so the content
    // script can connect via ?code= instead of falling back to ?s=
    let freshTempCode = null;
    try {
      const resp = await api("core/refresh_sse_code.php", {
        session_token: currentSession.token,
      });
      if (resp?.ok && resp?.temp_code) {
        freshTempCode = resp.temp_code;
      }
    } catch (e) {
      // Non-fatal: content.js will fall back to reconnect via REFRESH_SSE_CODE
    }

    chrome.tabs.sendMessage(
      tabId,
      {
        type: "START_FOLLOW_SESSION",
        sessionToken: currentSession.token,
        tempCode: freshTempCode,
        backendBase: currentSession.backendBase || BASE_URL,
        restore: true,
      },
      { frameId: 0 },
      () => {},
    );
  } catch (e) {}
});
