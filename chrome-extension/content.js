// ============================================================================
//  content.js – Unified CRM extension (L1/L2 generic + L3 HubSpot standalone parity)
// ============================================================================

const BASE_URL = "https://extension-dev.phoneburner.biz";

// ---- CRM registry for context-aware behavior ----
const CRM_REGISTRY = [
  {
    id: "hubspot",
    displayName: "HubSpot",
    level: 3,
    match: (host) => host.includes("app.hubspot.com"),
  },
  {
    id: "salesforce",
    displayName: "Salesforce",
    level: 1,
    match: (host) => host.includes("lightning.force.com"),
  },
  {
    id: "zoho",
    displayName: "Zoho CRM",
    level: 1,
    match: (host) => host.includes("crm.zoho.com"),
  },
  {
    id: "monday",
    displayName: "monday.com",
    level: 1,
    match: (host) => host.includes("monday.com"),
  },
  {
    id: "pipedrive",
    displayName: "Pipedrive",
    level: 2,
    match: (host) => host.includes("pipedrive.com"),
  },
];

function pbToText(x) {
  if (x == null) return "";
  if (typeof x === "string") return x;
  if (x instanceof Error) return x.message || String(x);
  try {
    return JSON.stringify(x, null, 2);
  } catch (e) {
    return String(x);
  }
}

// ============================================================================
//  🔍 CRM DETECTION
// ============================================================================

function detectCrmContext() {
  const host = window.location.hostname;
  const path = window.location.pathname + window.location.search;
  let crmId = "generic";
  let level = 1;
  let crmName = host;

  for (const crm of CRM_REGISTRY) {
    if (crm.match && crm.match(host)) {
      crmId = crm.id;
      level = crm.level || 1;
      crmName = crm.displayName || host;
      break;
    }
  }

  return { host, path, crmId, level, crmName };
}

// Compute CRM context once per page and tell the background script
const CURRENT_CRM_CONTEXT = detectCrmContext();

try {
  chrome.runtime.sendMessage({
    type: "CRM_CONTEXT",
    context: CURRENT_CRM_CONTEXT,
  });
} catch (e) {
  // ignore
}

// ============================================================================
//  📡 GLOBAL STATE
// ============================================================================

let sse = null;
let currentSessionToken = null;
let sseReconnectTimer = null;

// Track record identity to avoid double navigations
let currentRecordId = null;
let pendingRecordId = null;

// ============================================================================
//  🎯 GOAL CONFIGURATION (Primary / Secondary dispositions)
// ============================================================================

let goalConfig = {
  primary: "Set Appointment",
  secondary: "Follow Up",
};

function loadGoalConfig() {
  try {
    if (!chrome?.storage?.local) return;
    chrome.storage.local.get(["pb_goal_primary", "pb_goal_secondary"], (res) => {
      if (res && typeof res === "object") {
        if (res.pb_goal_primary && res.pb_goal_primary.trim()) {
          goalConfig.primary = res.pb_goal_primary.trim();
        }
        if (res.pb_goal_secondary && res.pb_goal_secondary.trim()) {
          goalConfig.secondary = res.pb_goal_secondary.trim();
        }
      }
    });
  } catch (e) {
    console.error("Error loading goal config", e);
  }
}

function syncGoalsFromServer() {
  try {
    if (!chrome?.runtime) return;

    chrome.runtime.sendMessage({ type: "LOAD_SERVER_GOALS" }, (resp) => {
      if (chrome.runtime.lastError) {
        console.warn("LOAD_SERVER_GOALS error:", chrome.runtime.lastError.message);
        return;
      }
      if (!resp || !resp.ok) return;

      const { primary, secondary } = resp;

      let changed = false;

      if (primary && primary.trim() && primary !== goalConfig.primary) {
        goalConfig.primary = primary.trim();
        changed = true;
      }
      if (secondary && secondary.trim() && secondary !== goalConfig.secondary) {
        goalConfig.secondary = secondary.trim();
        changed = true;
      }

      if (changed && chrome?.storage?.local) {
        chrome.storage.local.set({
          pb_goal_primary: goalConfig.primary,
          pb_goal_secondary: goalConfig.secondary,
        });
      }
    });
  } catch (e) {
    console.error("Error syncing goals from server", e);
  }
}

loadGoalConfig();
syncGoalsFromServer();

// On initial load, try to infer the current SF record (if any)
if (window.top === window) {
  const initialId = extractSalesforceRecordIdSafe(window.location.href);
  if (initialId) {
    currentRecordId = initialId;
    pendingRecordId = initialId;
  }
}

// ============================================================================
//  🧮 GENERIC SCANNER HELPERS (L1/L2 ONLY)
// ============================================================================

function guessColumnIndex(headers, candidates) {
  const lower = headers.map((h) => h.toLowerCase());
  for (const candidate of candidates) {
    const idx = lower.findIndex((h) => h.includes(candidate));
    if (idx !== -1) return idx;
  }
  return -1;
}

function isRowSelected(row) {
  const cb = row.querySelector('input[type="checkbox"], input[type="radio"]');
  if (cb && cb.checked) return true;

  const aria = row.getAttribute("aria-selected");
  if (aria && aria.toLowerCase() === "true") return true;

  return false;
}

function isRowSelectedForActiveCrm(row) {
  const crmId = CURRENT_CRM_CONTEXT?.crmId || "generic";

  if (crmId === "salesforce") {
    const cb = row.querySelector('input[type="checkbox"], input[type="radio"]');
    return !!(cb && cb.checked);
  }

  return isRowSelected(row);
}

function findRecordUrlInRow(row) {
  const link =
    row.querySelector('a[data-recordid]') ||
    row.querySelector(
      'a[href*="/lightning/r/"], a[href*="/Contact/"], a[href^="/"], a[href^="http"]'
    );

  if (!link) return null;

  try {
    const href = link.getAttribute("href");
    const url = new URL(href, window.location.origin);
    return url.href;
  } catch (e) {
    return null;
  }
}

function looksLikePhone(text) {
  if (!text) return false;
  const digits = text.replace(/\D/g, "");
  return digits.length >= 7 && digits.length <= 15;
}

function extractPhoneFromRowFallback(row, currentPhone) {
  if (currentPhone && currentPhone.trim()) return currentPhone;

  const anchor = row.querySelector('a[data-phone], a[class*="call"], a[class*="phone"]');
  if (anchor) {
    const attrPhone = anchor.getAttribute("data-phone");
    if (attrPhone && attrPhone.trim()) return attrPhone.trim();
    if (anchor.innerText && anchor.innerText.trim()) return anchor.innerText.trim();
  }

  return currentPhone;
}

// ============================================================================
//  🆔 GENERIC ROW → CRM ID HELPERS (L1/L2)
// ============================================================================

const GENERIC_ID_PARAM_KEYS = [
  "id",
  "ID",
  "Id",
  "recordId",
  "record_id",
  "contactId",
  "contact_id",
  "leadId",
  "lead_id",
  "loanId",
  "loan_id",
  "process",
  "processId",
  "process_id",
];

function findNumericDataId(el) {
  if (!el?.getAttribute) return null;

  const attrNames = [
    "data-id",
    "data-record-id",
    "data-contact-id",
    "data-lead-id",
    "data-pid",
    "data-rowid",
    "data-process-id",
    "data-opportunity-id",
  ];

  for (const name of attrNames) {
    const val = el.getAttribute(name);
    if (val && /^\d+$/.test(val)) return val;
  }
  return null;
}

function extractNumericSuffix(str) {
  if (!str) return null;
  const m = str.match(/(\d+)(?:\D*)$/);
  return m ? m[1] : null;
}

function extractIdFromHrefQuery(href) {
  if (!href) return null;
  try {
    const url = new URL(href, window.location.origin);
    for (const key of GENERIC_ID_PARAM_KEYS) {
      const val = url.searchParams.get(key);
      if (val && /^\d+$/.test(val)) return val;
    }
  } catch (e) {}
  return null;
}

function extractIdFromHrefPath(href) {
  if (!href) return null;
  try {
    const url = new URL(href, window.location.origin);
    const m = url.pathname.match(/\/(person|contact|lead|candidate|record)\/(\d+)/i);
    if (m && m[2]) return m[2];
  } catch (e) {}
  return null;
}

function collectRowCandidateIds(row) {
  const candidates = new Set();
  if (!row) return [];

  const all = row.querySelectorAll("*");

  all.forEach((el) => {
    const dataId = findNumericDataId(el);
    if (dataId) candidates.add(dataId);

    ["id", "name"].forEach((attr) => {
      const val = el[attr];
      if (val) {
        const numeric = extractNumericSuffix(val);
        if (numeric && /^\d+$/.test(numeric)) candidates.add(numeric);
      }
    });

    if (el.tagName === "A" && el.href) {
      const q = extractIdFromHrefQuery(el.href);
      if (q) candidates.add(q);

      const p = extractIdFromHrefPath(el.href);
      if (p) candidates.add(p);
    }
  });

  return Array.from(candidates);
}

function pickBestRowId(row) {
  const ids = collectRowCandidateIds(row);
  return ids.length ? ids[0] : null;
}

// ============================================================================
//  📋 GENERIC HTML TABLE SCANNER (L1/L2 ONLY)
// ============================================================================

function scanHtmlTableContacts() {
  const tables = Array.from(document.querySelectorAll("table"));
  if (!tables.length) return [];

  let bestTable = null;
  let bestScore = 0;
  let bestConfig = null;

  for (const t of tables) {
    const headerRow = t.querySelector("thead tr") || t.querySelector("tr");
    if (!headerRow) continue;

    const headerCells = Array.from(headerRow.children).map((th) => th.innerText.trim());
    if (!headerCells.length) continue;

    let nameIdx = guessColumnIndex(headerCells, ["name", "contact", "person", "candidate", "full name"]);
    let phoneIdx = guessColumnIndex(headerCells, [
      "phone",
      "mobile",
      "cell",
      "work phone",
      "home phone",
      "phone number",
    ]);
    let emailIdx = guessColumnIndex(headerCells, ["email", "e-mail", "mail"]);

    let bodyRows = Array.from(t.querySelectorAll("tbody tr"));
    if (!bodyRows.length) {
      const allRows = Array.from(t.querySelectorAll("tr"));
      allRows.shift();
      bodyRows = allRows;
    }

    if (bodyRows.length < 2) continue;

    if (phoneIdx === -1 && headerCells.length) {
      const colCount = headerCells.length;
      const phoneLikeCounts = new Array(colCount).fill(0);
      let rowsChecked = 0;

      const sampleRows = bodyRows.slice(0, 20);
      for (const row of sampleRows) {
        const cells = Array.from(row.children);
        if (!cells.length) continue;
        rowsChecked++;

        for (let i = 0; i < Math.min(colCount, cells.length); i++) {
          const txt = cells[i].innerText || "";
          if (looksLikePhone(txt)) phoneLikeCounts[i]++;
        }
      }

      if (rowsChecked > 0) {
        const threshold = Math.max(2, Math.floor(rowsChecked * 0.3));
        for (let i = 0; i < phoneLikeCounts.length; i++) {
          if (phoneLikeCounts[i] >= threshold) {
            phoneIdx = i;
            break;
          }
        }
      }
    }

    const hasAnyKeyColumn = nameIdx >= 0 || phoneIdx >= 0 || emailIdx >= 0;
    if (!hasAnyKeyColumn) continue;

    let score = 0;
    if (nameIdx >= 0) score += 3;
    if (phoneIdx >= 0) score += 2;
    if (emailIdx >= 0) score += 1;
    score += Math.min(bodyRows.length, 20);

    if (score > bestScore) {
      bestScore = score;
      bestTable = t;
      bestConfig = { nameIdx, phoneIdx, emailIdx };
    }
  }

  if (!bestTable || !bestConfig) return [];

  const { nameIdx, phoneIdx, emailIdx } = bestConfig;

  let bodyRows = Array.from(bestTable.querySelectorAll("tbody tr"));
  if (!bodyRows.length) {
    const allRows = Array.from(bestTable.querySelectorAll("tr"));
    allRows.shift();
    bodyRows = allRows;
  }

  const selectedRows = bodyRows.filter(isRowSelectedForActiveCrm);
  const rowsToUse = selectedRows.length ? selectedRows : bodyRows;

  const contacts = [];
  rowsToUse.forEach((row, idx) => {
    const cells = Array.from(row.children);
    if (!cells.length) return;

    const name = nameIdx >= 0 && cells[nameIdx] ? cells[nameIdx].innerText.trim() : "";

    let phone = phoneIdx >= 0 && cells[phoneIdx] ? cells[phoneIdx].innerText.trim() : "";
    const email = emailIdx >= 0 && cells[emailIdx] ? cells[emailIdx].innerText.trim() : "";

    phone = extractPhoneFromRowFallback(row, phone);

    if (!name && !phone && !email) return;

    const recordUrl = findRecordUrlInRow(row);
    const crmIdentifier = pickBestRowId(row);

    contacts.push({
      name,
      phone,
      email,
      source_url: window.location.href,
      source_label: document.title || "",
      record_url: recordUrl || null,
      crm_identifier: crmIdentifier || null,
      row_index: idx,
    });
  });

  return contacts;
}

// ============================================================================
//  📋 GENERIC ARIA GRID SCANNER (L1/L2 ONLY)
// ============================================================================

function scanAriaGridContacts() {
  const grid = document.querySelector('[role="grid"]') || document.querySelector('[role="treegrid"]');
  if (!grid) return [];

  const rowEls = Array.from(grid.querySelectorAll('[role="row"]'));
  if (rowEls.length < 2) return [];

  const headerRow = rowEls[0];
  const headerCells = Array.from(
    headerRow.querySelectorAll('[role="columnheader"], [role="gridcell"]')
  ).map((el) => el.innerText.trim());

  const nameIdx = guessColumnIndex(headerCells, ["name", "contact", "person"]);
  const phoneIdx = guessColumnIndex(headerCells, ["phone", "mobile"]);
  const emailIdx = guessColumnIndex(headerCells, ["email", "e-mail"]);

  const dataRows = rowEls.slice(1);
  const selectedRows = dataRows.filter(isRowSelectedForActiveCrm);
  const rowsToUse = selectedRows.length ? selectedRows : dataRows;

  const contacts = [];
  rowsToUse.forEach((row, idx) => {
    const cells = Array.from(row.querySelectorAll('[role="gridcell"], [role="columnheader"]'));
    if (!cells.length) return;

    const name = nameIdx >= 0 && cells[nameIdx] ? cells[nameIdx].innerText.trim() : "";
    let phone = phoneIdx >= 0 && cells[phoneIdx] ? cells[phoneIdx].innerText.trim() : "";
    const email = emailIdx >= 0 && cells[emailIdx] ? cells[emailIdx].innerText.trim() : "";

    phone = extractPhoneFromRowFallback(row, phone);

    if (!name && !phone && !email) return;

    const recordUrl = findRecordUrlInRow(row);
    const crmIdentifier = pickBestRowId(row);

    contacts.push({
      name,
      phone,
      email,
      source_url: window.location.href,
      source_label: document.title || "",
      record_url: recordUrl || null,
      crm_identifier: crmIdentifier || null,
      row_index: idx,
    });
  });

  return contacts;
}

// ============================================================================
//  🧩 CRM-SPECIFIC SCANNERS (L1/L2)
// ============================================================================

function scanZohoContacts() {
  return [];
}

function scanMondayContacts() {
  return [];
}

// ============================================================================
//  🧩 PIPEDRIVE-SPECIFIC SCANNER (LEVEL 2)
// ============================================================================

function getPipedriveRowIdFromDataCy(dataCy) {
  if (!dataCy) return null;
  const m = dataCy.match(/grid-cell-column-(\d+)-1/);
  return m ? m[1] : null;
}

function findPipedriveEmailCell(rowId) {
  if (!rowId) return null;
  return document.querySelector(`[data-test="person.email"][data-cy^="grid-cell-column-${rowId}-"]`);
}

function findPipedrivePhoneCell(rowId) {
  if (!rowId) return null;
  return document.querySelector(`[data-test="person.phone"][data-cy^="grid-cell-column-${rowId}-"]`);
}

function extractPipedrivePersonIdFromHref(href) {
  if (!href) return null;
  try {
    const u = new URL(href, window.location.origin);
    const m = u.pathname.match(/\/person\/(\d+)/);
    if (m && m[1]) return m[1];
    const idParam = u.searchParams.get("id");
    if (idParam) return idParam;
  } catch (e) {}
  return null;
}

function scanPipedriveContacts(maxContacts = 500) {
  const host = window.location.hostname || "";
  const path = window.location.pathname || "";
  if (!host.includes("pipedrive.com") || !path.includes("/persons")) return [];

  const contacts = [];
  const seenKeys = new Set();

  const nameCells = document.querySelectorAll('[data-test="person.name"]');
  console.log("[PB-CRM] Pipedrive name cells:", nameCells.length);

  nameCells.forEach((nameCell) => {
    if (contacts.length >= maxContacts) return;

    const dataCy = nameCell.getAttribute("data-cy") || "";
    const rowId = getPipedriveRowIdFromDataCy(dataCy);
    if (!rowId) return;

    const personLink = nameCell.querySelector('a[href*="/person/"]');
    if (!personLink) return;

    const rawHref = personLink.getAttribute("href") || "";
    let personId = extractPipedrivePersonIdFromHref(rawHref);

    let recordUrl = null;
    try {
      recordUrl = new URL(rawHref, window.location.origin).href;
    } catch (e) {
      recordUrl = null;
    }

    const name = (personLink.textContent || "").replace(/\s+/g, " ").trim();

    let email = "";
    const emailCell = findPipedriveEmailCell(rowId);
    if (emailCell) {
      const emailLink = emailCell.querySelector('a[href^="mailto:"]');
      if (emailLink) {
        email = (emailLink.textContent || "").replace(/\s+/g, " ").trim();
        if (!personId) {
          const mailHref = emailLink.getAttribute("href") || "";
          const m = mailHref.match(/related_person_id=(\d+)/);
          if (m && m[1]) personId = m[1];
        }
      }
    }

    let phone = "";
    const phoneCell = findPipedrivePhoneCell(rowId);
    if (phoneCell) {
      const phoneLink =
        phoneCell.querySelector('a[href^="callto:"], a[href^="tel:"]') || phoneCell.querySelector("a[href]");
      if (phoneLink) phone = (phoneLink.textContent || "").replace(/\s+/g, " ").trim();
    }

    if (!phone && !email) return;

    const dedupeKey = [personId || rowId, email, phone].join("|").toLowerCase();
    if (seenKeys.has(dedupeKey)) return;
    seenKeys.add(dedupeKey);

    const contact = {
      name,
      phone,
      email,
      source_url: window.location.href,
      source_label: document.title || "",
      record_url: recordUrl,
    };
    if (personId) contact.crm_identifier = personId;

    contacts.push(contact);
  });

  console.log("[PB-CRM] Pipedrive contacts extracted:", contacts.length);
  return contacts.slice(0, maxContacts);
}

// ============================================================================
//  🔎 Dispatcher (IMPORTANT: HubSpot is NOT scanned via generic scanners)
// ============================================================================

function scanPageForContacts() {
  const crmId = CURRENT_CRM_CONTEXT?.crmId || "generic";

  // 🚫 HubSpot must NOT use generic scanning (standalone parity uses selected IDs + server fetch)
  if (crmId === "hubspot") return [];

  let contacts = [];

  if (crmId === "zoho") {
    contacts = scanZohoContacts();
  } else if (crmId === "monday") {
    contacts = scanMondayContacts();
  } else if (crmId === "pipedrive") {
    contacts = scanPipedriveContacts();
  }

  if (!contacts || !contacts.length) {
    contacts = scanHtmlTableContacts();
    if (!contacts.length) contacts = scanAriaGridContacts();
  }

  return contacts;
}

// ============================================================================
//  🧷 SALESFORCE / JOBDIVA HELPERS
// ============================================================================

function looksLikeSfId(str) {
  if (!str) return false;
  const len = str.length;
  if (len !== 15 && len !== 18) return false;
  return /^[A-Za-z0-9]+$/.test(str);
}

function extractSalesforceRecordIdSafe(urlString) {
  try {
    const u = new URL(urlString, window.location.origin);
    if (!u.hostname.includes("lightning.force.com")) return null;

    const parts = u.pathname.split("/").filter(Boolean);
    const rIdx = parts.indexOf("r");
    if (rIdx === -1) return null;

    for (let i = rIdx + 1; i < parts.length; i++) {
      if (looksLikeSfId(parts[i])) return parts[i];
    }
    return null;
  } catch (e) {
    return null;
  }
}

function extractJobDivaInfoFromUrl(urlString) {
  try {
    const u = new URL(urlString, window.location.origin);
    const cid = u.searchParams.get("cid");
    if (cid) return { crm: "jobdiva", type: "contact", id: cid };
    const candidateId = u.searchParams.get("candidateid");
    if (candidateId) return { crm: "jobdiva", type: "candidate", id: candidateId };
    return null;
  } catch (e) {
    return null;
  }
}

function isSameCrmRecord(currentUrl, targetUrl) {
  if (!targetUrl) return false;

  const curId = extractSalesforceRecordIdSafe(currentUrl);
  const tgtId = extractSalesforceRecordIdSafe(targetUrl);
  if (curId && tgtId) return curId === tgtId;

  try {
    const cur = new URL(currentUrl, window.location.origin);
    const tgt = new URL(targetUrl, window.location.origin);
    return cur.origin + cur.pathname === tgt.origin + tgt.pathname;
  } catch (e) {
    return false;
  }
}

// ============================================================================
//  📡 SSE FOLLOW-ME HANDLING
// ============================================================================

function startFollowingSession(sessionToken) {
  if (!sessionToken) return;

  currentSessionToken = sessionToken;

  if (sse) {
    sse.close();
    sse = null;
  }
  if (sseReconnectTimer) {
    clearTimeout(sseReconnectTimer);
    sseReconnectTimer = null;
  }

  // Draw attention on launch: expand briefly, then auto-collapse
  overlayEnsure();
  overlaySetMinimized(false, false);
  overlayScheduleAutoCollapse("session_start");

  openSseConnection(sessionToken);
}

function openSseConnection(sessionToken) {
  const url = `${BASE_URL}/sse.php?s=${encodeURIComponent(sessionToken)}`;
  const es = new EventSource(url);
  sse = es;

  es.addEventListener("update", (ev) => {
    try {
      const data = JSON.parse(ev.data);
      handleSessionUpdate(data);
    } catch (e) {
      console.error("Invalid SSE data", e);
    }
  });

  es.onerror = (err) => {
    console.error("SSE error", err);

    try {
      es.close();
    } catch (e) {}

    if (sse === es) sse = null;

    if (!currentSessionToken || currentSessionToken !== sessionToken) return;

    if (!sseReconnectTimer) {
      sseReconnectTimer = setTimeout(() => {
        sseReconnectTimer = null;
        if (currentSessionToken === sessionToken) openSseConnection(sessionToken);
      }, 5000);
    }
  };

  console.log("SSE opened for session", sessionToken);
}

function stopFollowingSession() {
  if (sse) {
    sse.close();
    sse = null;
  }
  currentSessionToken = null;

  if (sseReconnectTimer) {
    clearTimeout(sseReconnectTimer);
    sseReconnectTimer = null;
  }

  if (overlayEl) {
    overlayEl.remove();
    overlayEl = null;
  }

  try {
    chrome.runtime.sendMessage({ type: "STOP_FOLLOW_SESSION" }, () => {});
  } catch (e) {
    console.error("Error sending STOP_FOLLOW_SESSION", e);
  }
}

// ============================================================================
//  🧊 OVERLAY WIDGET + SESSION UPDATE HANDLING (Modern + Minimize + Auto-collapse)
// ============================================================================

let overlayEl = null;

// Widget UI preferences
const OVERLAY_STORAGE_KEYS = {
  minimized: "pb_follow_widget_minimized",
  autoCollapse: "pb_follow_widget_auto_collapse",
};

// Defaults
let overlayPrefs = {
  minimized: false,
  autoCollapse: true,
};

// Internal overlay state
let overlayLastInteractionTs = 0;
let overlayAutoCollapseTimer = null;
let overlayLastNowCallingKey = "";
let overlayInitializedPrefs = false;

function overlayNow() {
  return Date.now();
}

function overlayTouchInteraction() {
  overlayLastInteractionTs = overlayNow();
}

function overlayLoadPrefsOnce() {
  if (overlayInitializedPrefs) return;
  overlayInitializedPrefs = true;

  try {
    if (!chrome?.storage?.local) return;
    chrome.storage.local.get([OVERLAY_STORAGE_KEYS.minimized, OVERLAY_STORAGE_KEYS.autoCollapse], (res) => {
      if (!res || typeof res !== "object") return;

      if (typeof res[OVERLAY_STORAGE_KEYS.minimized] === "boolean") {
        overlayPrefs.minimized = res[OVERLAY_STORAGE_KEYS.minimized];
      }
      if (typeof res[OVERLAY_STORAGE_KEYS.autoCollapse] === "boolean") {
        overlayPrefs.autoCollapse = res[OVERLAY_STORAGE_KEYS.autoCollapse];
      }

      // If overlay already exists, apply
      if (overlayEl) overlayApplyMode();
    });
  } catch (e) {
    // ignore
  }
}

function overlaySavePref(key, value) {
  try {
    if (!chrome?.storage?.local) return;
    chrome.storage.local.set({ [key]: value }, () => {});
  } catch (e) {
    // ignore
  }
}

function overlayEnsure() {
  overlayLoadPrefsOnce();

  if (overlayEl) return overlayEl;

  overlayEl = document.createElement("div");
  overlayEl.id = "pb-follow-widget";
  overlayEl.style.position = "fixed";
  overlayEl.style.right = "10px";
  overlayEl.style.bottom = "10px";
  overlayEl.style.zIndex = "999999";
  overlayEl.style.maxWidth = "280px";
  overlayEl.style.fontFamily = "system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif";

  overlayEl.innerHTML = `
    <style>
      #pb-follow-widget * { box-sizing: border-box; }

      #pb-follow-shell {
        width: 270px;
        border-radius: 14px;
        background: rgba(14, 20, 34, 0.94);
        border: 1px solid rgba(255,255,255,0.10);
        box-shadow: 0 14px 34px rgba(0,0,0,0.35);
        overflow: hidden;
        color: rgba(255,255,255,0.92);
        backdrop-filter: blur(10px);
      }

      #pb-follow-shell.minimized {
        width: 210px;
      }

      .pb-follow-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 10px;
      }

      .pb-follow-title {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
      }
      .pb-follow-title strong {
        font-size: 12px;
        letter-spacing: 0.2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .pb-follow-sub {
        font-size: 11px;
        color: rgba(255,255,255,0.65);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .pb-follow-actions {
        display: flex;
        gap: 6px;
        flex-shrink: 0;
      }

      .pb-btn {
        appearance: none;
        border: 1px solid rgba(255,255,255,0.14);
        background: rgba(255,255,255,0.08);
        color: rgba(255,255,255,0.92);
        font-size: 11px;
        padding: 6px 8px;
        border-radius: 10px;
        cursor: pointer;
        line-height: 1;
      }
      .pb-btn:hover { background: rgba(255,255,255,0.12); }
      .pb-btn:active { transform: translateY(1px); }

      .pb-btn.primary {
        background: rgba(93, 149, 255, 0.95);
        border-color: rgba(93,149,255,0.60);
        color: #0b1020;
        font-weight: 700;
      }
      .pb-btn.primary:hover { background: rgba(93,149,255,1); }

      .pb-divider {
        height: 1px;
        background: rgba(255,255,255,0.10);
      }

      .pb-follow-body {
        padding: 10px;
        display: grid;
        gap: 10px;
      }

      .pb-block {
        padding: 10px;
        border-radius: 12px;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.10);
      }

      .pb-block h4 {
        margin: 0 0 6px 0;
        font-size: 11px;
        color: rgba(255,255,255,0.75);
        font-weight: 700;
        letter-spacing: 0.2px;
      }

      .pb-line {
        font-size: 12px;
        color: rgba(255,255,255,0.92);
        margin: 2px 0;
        word-break: break-word;
      }
      .pb-muted { color: rgba(255,255,255,0.65); }

      .pb-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        font-size: 12px;
        margin: 3px 0;
      }
      .pb-row span:first-child { color: rgba(255,255,255,0.70); }

      .pb-footer {
        padding: 10px;
        display: flex;
        justify-content: flex-end;
      }

      /* Minimized bar */
      .pb-mini {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 10px;
      }
      .pb-mini strong { font-size: 12px; }
      .pb-mini-actions { display: flex; gap: 6px; }

      /* Hide blocks in minimized mode */
      #pb-follow-shell.minimized .pb-follow-body,
      #pb-follow-shell.minimized .pb-footer {
        display: none;
      }

      #pb-follow-shell:not(.minimized) .pb-mini {
        display: none;
      }
    </style>

    <div id="pb-follow-shell" class="minimized">
      <div class="pb-follow-header" id="pb-follow-header">
        <div class="pb-follow-title">
          <strong id="pb-follow-h-title">PhoneBurner Follow</strong>
          <div class="pb-follow-sub" id="pb-follow-h-sub">Waiting…</div>
        </div>
        <div class="pb-follow-actions">
          <button class="pb-btn" id="pb-follow-toggle" title="Minimize / Expand">▾</button>
          <button class="pb-btn" id="pb-follow-stop" title="Stop following">Stop</button>
        </div>
      </div>

      <div class="pb-divider"></div>

      <div class="pb-mini">
        <div class="pb-mini-text">
          <strong>Calls: <span id="pb-mini-total">0</span></strong>
        </div>
        <div class="pb-mini-actions">
          <button class="pb-btn primary" id="pb-mini-expand">Expand</button>
          <button class="pb-btn" id="pb-mini-stop">Stop</button>
        </div>
      </div>

      <div class="pb-follow-body">
        <div class="pb-block">
          <h4>Now calling</h4>
          <div class="pb-line" id="pb-now-name">(unknown)</div>
          <div class="pb-line pb-muted" id="pb-now-phone"></div>
          <div class="pb-line pb-muted" id="pb-now-email"></div>
          <div style="margin-top:8px; display:flex; gap:8px;">
            <button class="pb-btn primary" id="pb-open-crm" style="display:none;">Open in CRM</button>
          </div>
        </div>

        <div class="pb-block">
          <h4>Last call</h4>
          <div class="pb-row"><span>Status</span><span id="pb-last-status">–</span></div>
          <div class="pb-row"><span>Duration</span><span id="pb-last-duration">–</span></div>
        </div>

        <div class="pb-block">
          <h4>Stats</h4>
          <div class="pb-row"><span>Total</span><span id="pb-stat-total">0</span></div>
          <div class="pb-row"><span>Connected</span><span id="pb-stat-connected">0</span></div>
          <div class="pb-row"><span>Appointments</span><span id="pb-stat-appt">0</span></div>
        </div>

        <div class="pb-block">
          <h4>Goals</h4>
          <div class="pb-row"><span id="pb-goal-1-label">Primary</span><span id="pb-goal-1-val">0</span></div>
          <div class="pb-row"><span id="pb-goal-2-label">Secondary</span><span id="pb-goal-2-val">0</span></div>
        </div>
      </div>

      <div class="pb-footer">
        <button class="pb-btn" id="pb-footer-stop">Stop following</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlayEl);

  // wire events once
  const shell = overlayEl.querySelector("#pb-follow-shell");
  const header = overlayEl.querySelector("#pb-follow-header");
  const toggleBtn = overlayEl.querySelector("#pb-follow-toggle");

  const stopBtn = overlayEl.querySelector("#pb-follow-stop");
  const miniStopBtn = overlayEl.querySelector("#pb-mini-stop");
  const miniExpandBtn = overlayEl.querySelector("#pb-mini-expand");
  const footerStopBtn = overlayEl.querySelector("#pb-footer-stop");

  const stopAll = (e) => {
    if (e && typeof e.preventDefault === "function") e.preventDefault();
    overlayTouchInteraction();
    stopFollowingSession();
  };

  const expand = () => {
    overlayTouchInteraction();
    overlaySetMinimized(false, true);
  };
  const minimize = () => {
    overlayTouchInteraction();
    overlaySetMinimized(true, true);
  };
  const toggle = () => {
    overlayTouchInteraction();
    overlaySetMinimized(!overlayPrefs.minimized, true);
  };

  if (toggleBtn) toggleBtn.addEventListener("click", toggle);
  if (stopBtn) stopBtn.addEventListener("click", stopAll);
  if (miniStopBtn) miniStopBtn.addEventListener("click", stopAll);
  if (footerStopBtn) footerStopBtn.addEventListener("click", stopAll);

  if (miniExpandBtn) miniExpandBtn.addEventListener("click", expand);

  // Touch interaction on any click inside widget (prevents auto-collapse fighting user)
  if (header) header.addEventListener("mousedown", overlayTouchInteraction);
  overlayEl.addEventListener("mousedown", overlayTouchInteraction, true);

  // Apply initial mode from prefs
  overlayApplyMode();

  return overlayEl;

}

function overlayApplyMode() {
  if (!overlayEl) return;
  const shell = overlayEl.querySelector("#pb-follow-shell");
  const toggleBtn = overlayEl.querySelector("#pb-follow-toggle");
  if (!shell) return;
  if (overlayPrefs.minimized) shell.classList.add("minimized");
  else shell.classList.remove("minimized");
  if (toggleBtn) toggleBtn.textContent = overlayPrefs.minimized ? "▸" : "▾";
}

function overlaySetMinimized(minimized, persist = true) {
  overlayPrefs.minimized = !!minimized;
  overlayApplyMode();
  if (persist) overlaySavePref(OVERLAY_STORAGE_KEYS.minimized, overlayPrefs.minimized);
}

function overlayClearAutoCollapseTimer() {
  if (overlayAutoCollapseTimer) {
    clearTimeout(overlayAutoCollapseTimer);
    overlayAutoCollapseTimer = null;
  }
}

function overlayScheduleAutoCollapse(reason = "update") {
  overlayLoadPrefsOnce();
  overlayClearAutoCollapseTimer();

  if (!overlayPrefs.autoCollapse) return;

  // If user explicitly expanded, don't collapse immediately on next render
  // Give them time to read.
  const collapseAfterMs = 6000; // feels “animated” but not annoying
  const minIdleMs = 1500; // if user just interacted, don't auto-collapse

  overlayAutoCollapseTimer = setTimeout(() => {
    overlayAutoCollapseTimer = null;
    const idle = overlayNow() - overlayLastInteractionTs;
    if (idle < minIdleMs) return;

    // Only collapse if currently expanded
    if (!overlayPrefs.minimized) {
      overlaySetMinimized(true, true);
    }
  }, collapseAfterMs);
}

function handleSessionUpdate(state) {
  const current = state.current || {};
  const lastCall = state.last_call || {};

  const sessionStats = state.stats || {};
  const dailyStats = state.daily_stats || null;
  const stats = dailyStats || sessionStats;
  const byStatus = stats.by_status || {};

  const jobdivaInfo = extractJobDivaInfoFromUrl(window.location.href);
  if (jobdivaInfo) {
    if (!current.external_id) current.external_id = jobdivaInfo.id;
    if (!current.record_type) current.record_type = jobdivaInfo.type;
  }

  const name = current.name || (current.raw && current.raw.contact && current.raw.contact.name) || "";
  const phone = current.phone || (current.raw && current.raw.contact && current.raw.contact.phone) || "";
  const email = current.email || (current.raw && current.raw.contact && current.raw.contact.email) || "";

  const recordUrl = current.record_url || current.crm_url || null;

  const primaryCount = byStatus[goalConfig.primary] || 0;
  const secondaryCount = byStatus[goalConfig.secondary] || 0;

  // If "now calling" changes, expand briefly to draw attention, then auto-collapse
  const nowKey = [name, phone, email, recordUrl || ""].join("|").toLowerCase();
  if (nowKey && nowKey !== overlayLastNowCallingKey) {
    overlayLastNowCallingKey = nowKey;
    overlayEnsure();
    overlaySetMinimized(false, false);
    overlayScheduleAutoCollapse("now_calling_changed");
  }

  renderOverlay({
    name,
    phone,
    email,
    stats,
    lastCall,
    recordUrl,
    primaryCount,
    secondaryCount,
    goalConfig,
  });

  if (window.top === window && recordUrl) {
    try {
      if (!isSameCrmRecord(window.location.href, recordUrl)) {
        window.location.href = recordUrl;
      }
    } catch (e) {
      console.error("Failed to auto-navigate to CRM record", e);
    }
  }
}

function renderOverlay({
  name,
  phone,
  email,
  stats,
  lastCall,
  recordUrl,
  primaryCount,
  secondaryCount,
  goalConfig,
}) {
  overlayEnsure();

  const totalCalls = stats.total_calls || 0;
  const connected = stats.connected || 0;
  const appointments = stats.appointments || 0;

  const lastStatus = (lastCall && lastCall.status) || "–";
  const lastDuration = lastCall && lastCall.duration != null ? `${lastCall.duration}s` : "–";

  const hSub = overlayEl.querySelector("#pb-follow-h-sub");
  const hTitle = overlayEl.querySelector("#pb-follow-h-title");

  const elNowName = overlayEl.querySelector("#pb-now-name");
  const elNowPhone = overlayEl.querySelector("#pb-now-phone");
  const elNowEmail = overlayEl.querySelector("#pb-now-email");

  const openBtn = overlayEl.querySelector("#pb-open-crm");

  const elLastStatus = overlayEl.querySelector("#pb-last-status");
  const elLastDuration = overlayEl.querySelector("#pb-last-duration");

  const elStatTotal = overlayEl.querySelector("#pb-stat-total");
  const elStatConn = overlayEl.querySelector("#pb-stat-connected");
  const elStatAppt = overlayEl.querySelector("#pb-stat-appt");

  const elMiniTotal = overlayEl.querySelector("#pb-mini-total");

  const g1Label = overlayEl.querySelector("#pb-goal-1-label");
  const g2Label = overlayEl.querySelector("#pb-goal-2-label");
  const g1Val = overlayEl.querySelector("#pb-goal-1-val");
  const g2Val = overlayEl.querySelector("#pb-goal-2-val");

  if (hTitle) hTitle.textContent = "PhoneBurner Follow";
  if (hSub) hSub.textContent = name ? `Now: ${name}` : "Now: (unknown)";

  if (elNowName) elNowName.textContent = name || "(unknown)";
  if (elNowPhone) elNowPhone.textContent = phone || "";
  if (elNowEmail) elNowEmail.textContent = email || "";

  if (elLastStatus) elLastStatus.textContent = lastStatus || "–";
  if (elLastDuration) elLastDuration.textContent = lastDuration;

  if (elStatTotal) elStatTotal.textContent = String(totalCalls);
  if (elStatConn) elStatConn.textContent = String(connected);
  if (elStatAppt) elStatAppt.textContent = String(appointments);
  if (elMiniTotal) elMiniTotal.textContent = String(totalCalls);

  if (g1Label) g1Label.textContent = goalConfig.primary || "Primary";
  if (g2Label) g2Label.textContent = goalConfig.secondary || "Secondary";
  if (g1Val) g1Val.textContent = String(primaryCount || 0);
  if (g2Val) g2Val.textContent = String(secondaryCount || 0);

  if (openBtn) {
    if (recordUrl) {
      openBtn.style.display = "inline-flex";
      openBtn.onclick = () => {
        overlayTouchInteraction();
        window.open(recordUrl, "_blank");
      };
    } else {
      openBtn.style.display = "none";
      openBtn.onclick = null;
    }
  }

  // If expanded and user hasn't interacted, keep auto-collapse scheduled
  if (!overlayPrefs.minimized) {
    overlayScheduleAutoCollapse("render");
  }
}

// ============================================================================
//  🟧 HUBSPOT (Level 3) — Standalone-parity selection harvesting
//  Responds to: HS_GET_SELECTION / HS_GET_SELECTED_IDS
// ============================================================================

function hs_getPageContext() {
  const path = window.location.pathname;
  const parts = path.split("/").filter(Boolean);

  let objectType = "unknown";
  let portalId = null;

  // /contacts/{portalId}/objects/...
  if (parts[0] === "contacts" && parts[1]) {
    portalId = parts[1];
  }

  const objIndex = parts.indexOf("objects");
  if (objIndex !== -1 && parts[objIndex + 1]) {
    const objId = parts[objIndex + 1];
    if (objId === "0-1") objectType = "contact";
    else if (objId === "0-2") objectType = "company";
    else if (objId === "0-3") objectType = "deal";
  }

  const recIndex = parts.indexOf("record");
  if (recIndex !== -1 && parts[recIndex + 1]) {
    const recObjId = parts[recIndex + 1];
    if (recObjId === "0-1") objectType = "contact";
    else if (recObjId === "0-2") objectType = "company";
    else if (recObjId === "0-3") objectType = "deal";
  }

  return {
    objectType,
    portalId,
    url: window.location.href,
    title: document.title || "",
  };
}

function hs_readSelectedCountFromUI() {
  const candidates = [
    '[data-selenium-test*="bulk-actions"]',
    '[data-test-id*="bulk-actions"]',
    'div[role="region"]',
    "header",
  ];
  for (const sel of candidates) {
    const el = document.querySelector(sel);
    if (!el) continue;
    const text = el.textContent || "";
    const m = text.match(/(\d+)\s+selected/i);
    if (m) return parseInt(m[1], 10);
  }
  return null;
}

function hs_findTableScroller() {
  const anyRow = document.querySelector("table tr, [role='row'], [data-row-key]");
  let el = anyRow ? anyRow.parentElement : null;

  while (el) {
    const style = getComputedStyle(el);
    if (/(auto|scroll)/i.test(style.overflowY) && el.scrollHeight > el.clientHeight + 20) {
      return el;
    }
    el = el.parentElement;
  }

  return document.querySelector("div[role='grid']") || document.scrollingElement || document.body;
}

function hs_collectIdsFromDom() {
  const ids = [];

  const checkedBox = document.querySelector("input[type='checkbox']:checked, input[type='radio']:checked");

  let scopeRoot = document;
  if (checkedBox) {
    const row = checkedBox.closest("tr, [role='row'], [data-row-key]");
    if (row) {
      scopeRoot =
        row.closest("table") ||
        row.closest("[role='grid']") ||
        row.closest("[role='treegrid']") ||
        row.parentElement ||
        document;
    }
  }

  const rows = scopeRoot.querySelectorAll("tr, [role='row'], [data-row-key]");

  rows.forEach((tr) => {
    const checked = tr.querySelector("input[type='checkbox']:checked") || tr.getAttribute("aria-selected") === "true";
    if (!checked) return;

    let id = tr.getAttribute("data-row-key") || tr.getAttribute("data-object-id");

    if (!id) {
      const testId = tr.getAttribute("data-test-id") || "";
      const m = testId.match(/^row-(\d+)$/);
      if (m) id = m[1];
    }

    // IMPORTANT: allow short IDs like "2801"
    if (id && /^\d+$/.test(id)) {
      ids.push(id);
      return;
    }

    const a = tr.querySelector(
      'a[href*="/record/0-1/"], a[href^="/contacts/"][href*="/record/0-1/"],' +
        'a[href*="/record/0-2/"], a[href^="/contacts/"][href*="/record/0-2/"],' +
        'a[href*="/record/0-3/"], a[href^="/contacts/"][href*="/record/0-3/"]'
    );

    if (a) {
      const href = a.getAttribute("href") || "";
      let m = href.match(/\/record\/0-1\/(\d+)/);
      if (!m) m = href.match(/\/record\/0-2\/(\d+)/);
      if (!m) m = href.match(/\/record\/0-3\/(\d+)/);
      if (m) ids.push(m[1]);
    }
  });

  return ids;
}

function hs_sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}
function hs_nextFrame() {
  return new Promise((r) => requestAnimationFrame(() => r()));
}

async function hs_collectSelectedIdsDeep({ maxMs = 8000, targetCount = null } = {}) {
  const scroller = hs_findTableScroller();
  const start = Date.now();
  const seen = new Set();

  const harvest = () => {
    hs_collectIdsFromDom().forEach((id) => seen.add(id));
  };

  harvest();

  if (seen.size === 0 && scroller) {
    scroller.scrollTop = 0;
    await hs_nextFrame();
    harvest();
  }

  let stablePasses = 0;

  while (Date.now() - start < maxMs && stablePasses < 3) {
    const before = seen.size;

    if (scroller) {
      for (let i = 0; i < 4; i++) {
        scroller.scrollTop = Math.min(scroller.scrollTop + scroller.clientHeight, scroller.scrollHeight);
        await hs_nextFrame();
        await hs_sleep(120);
        harvest();
        if (targetCount && seen.size >= targetCount) break;
      }
    } else {
      // no scroller found; just wait/rehydrate a couple passes
      await hs_sleep(150);
      harvest();
    }

    stablePasses = seen.size === before ? stablePasses + 1 : 0;
    if (targetCount && seen.size >= targetCount) break;
  }

  // sweep up (optional)
  if (scroller && targetCount && seen.size < targetCount) {
    for (let y = scroller.scrollTop; y > 0; y -= scroller.clientHeight) {
      scroller.scrollTop = Math.max(0, y - scroller.clientHeight);
      await hs_nextFrame();
      await hs_sleep(80);
      harvest();
      if (seen.size >= targetCount) break;
    }
  }

  return Array.from(seen);
}

// One HubSpot listener (standalone parity). Keeps the port open with return true.
chrome.runtime.onMessage.addListener((m, _s, sendResponse) => {
  if (m && (m.type === "HS_GET_SELECTION" || m.type === "HS_GET_SELECTED_IDS")) {
    (async () => {
      try {
        const ctx = CURRENT_CRM_CONTEXT || detectCrmContext();
        if (ctx.crmId !== "hubspot") {
          sendResponse({ error: "Not on a HubSpot page." });
          return;
        }

        const targetCount = hs_readSelectedCountFromUI();
        const ids = await hs_collectSelectedIdsDeep({ maxMs: 8000, targetCount });
        const pageCtx = hs_getPageContext();

        sendResponse({
          ids: [...new Set(ids)],
          objectType: pageCtx.objectType || "unknown",
          portalId: pageCtx.portalId || null,
          url: pageCtx.url,
          title: pageCtx.title,
        });
      } catch (e) {
        sendResponse({ error: e?.message || String(e) });
      }
    })();

    return true; // async
  }

  return false;
});

// ============================================================================
//  🔁 MESSAGE HANDLER (popup/background ↔ content)
// ============================================================================

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  const isTopWindow = window.top === window;

  // ✅ Guard: let the dedicated HubSpot listener handle HS_* messages
  if (msg && (msg.type === "HS_GET_SELECTION" || msg.type === "HS_GET_SELECTED_IDS")) {
    return false; // do not respond here
  }

  if (msg.type === "SCAN_PAGE" && !isTopWindow) {
    sendResponse({ ok: false, error: "ignored_iframe" });
    return true;
  }

  if (msg.type === "SCAN_PAGE") {
    console.log("[PB-UNIFIED] content: SCAN_PAGE received on", window.location.href);

    // 🚫 On HubSpot, do NOT use generic scanning.
    // HubSpot dial sessions must be created via HS_LAUNCH_FROM_SELECTED (selection + server fetch).
    if (CURRENT_CRM_CONTEXT?.crmId === "hubspot") {
      alert(
        "HubSpot detected.\n\n" +
          "Use 'Launch HubSpot Dial Session' to dial selected records.\n" +
          "Generic page scanning is disabled on HubSpot to match standalone behavior."
      );
      sendResponse({ ok: false, error: "hubspot_requires_selection_flow" });
      return true;
    }

    const contacts = scanPageForContacts();
    console.log("[PB-UNIFIED] content: contacts found:", contacts.length);

    if (!contacts.length) {
      const host = window.location.hostname;

      const tablesDebug = Array.from(document.querySelectorAll("table"))
        .slice(0, 5)
        .map((t) => {
          const rows = t.querySelectorAll("tr");
          const firstRow = rows[0] ? rows[0].innerText.slice(0, 200) : "";
          return { rows: rows.length, firstRow };
        });

      const ariaGridsDebug = Array.from(document.querySelectorAll('[role="grid"], [role="treegrid"]'))
        .slice(0, 5)
        .map((g) => {
          const rows = g.querySelectorAll('[role="row"]');
          const headerRow = rows[0];
          const headerText = headerRow ? headerRow.innerText.slice(0, 200) : "";
          return { rows: rows.length, headerText };
        });

      const debugPayload = {
        host,
        url: window.location.href,
        title: document.title || "",
        timestamp: new Date().toISOString(),
        mode: "no_contacts_found",
        tables: tablesDebug,
        ariaGrids: ariaGridsDebug,
      };

      fetch(`${BASE_URL}/scan_debug.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(debugPayload),
      }).catch((err) => console.error("scan_debug failed", err));

      alert("Could not find a contact table on this page.");
      sendResponse({ ok: false, error: "No contacts found" });
      return true;
    }

    const ctx = CURRENT_CRM_CONTEXT || detectCrmContext();

    chrome.runtime.sendMessage(
      {
        type: "SCANNED_CONTACTS",
        contacts,
        context: {
          crm_id: ctx.crmId,
          crm_name: ctx.crmName,
          host: ctx.host,
          path: ctx.path,
          level: ctx.level,
        },
      },
      (resp) => {
        if (resp && resp.ok) {
          console.log("Dial session requested");
        } else {
          console.error("Error creating dial session", resp);
          const msgText = (resp && (resp.error || resp.details)) || "Unknown error creating dial session";
          alert("Error creating dial session:\n" + pbToText(msgText));
        }
      }
    );

    sendResponse({ ok: true });
    return true;
  }

  if (msg.type === "START_FOLLOW_SESSION") {
    const { sessionToken } = msg;
    if (window.top === window) {
      startFollowingSession(sessionToken);
      sendResponse({ ok: true });
      return true;
    }
    sendResponse({ ok: false, error: "ignored_iframe" });
    return true;
  }

  if (msg.type === "STOP_FOLLOW_SESSION") {
    stopFollowingSession();
    sendResponse({ ok: true });
    return true;
  }

  if (msg.type === "PB_GOAL_UPDATED") {
    if (msg.primary && msg.primary.trim()) goalConfig.primary = msg.primary.trim();
    if (msg.secondary && msg.secondary.trim()) goalConfig.secondary = msg.secondary.trim();

    try {
      if (chrome?.storage?.local) {
        chrome.storage.local.set({
          pb_goal_primary: goalConfig.primary,
          pb_goal_secondary: goalConfig.secondary,
        });
      }
    } catch (e) {
      console.error("Error saving goals to local storage", e);
    }

    try {
      if (chrome?.runtime) {
        chrome.runtime.sendMessage({
          type: "SAVE_GOALS",
          goals: { primary: goalConfig.primary, secondary: goalConfig.secondary },
        });
      }
    } catch (e) {
      console.error("Error saving goals to server", e);
    }

    sendResponse({ ok: true });
    return true;
  }

  return false;
});

// ============================================================================
//  🔁 AUTO-RESUME FOLLOWING ON PAGE LOAD (top window only)
// ============================================================================

if (window.top === window) {
  try {
    chrome.runtime.sendMessage({ type: "GET_ACTIVE_SESSION_FOR_TAB" }, (resp) => {
      if (chrome.runtime.lastError) return;
      if (resp && resp.sessionToken) {
        console.log("Resuming follow for session", resp.sessionToken);

        const idNow = extractSalesforceRecordIdSafe(window.location.href);
        if (idNow) {
          currentRecordId = idNow;
          pendingRecordId = idNow;
        }

        startFollowingSession(resp.sessionToken);
      }
    });
  } catch (e) {
    console.error("Error requesting active session for tab", e);
  }
}

console.log("[PB-UNIFIED-CRM] content script initialized");
