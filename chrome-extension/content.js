// ============================================================================
//  content.js – Unified CRM extension
// ============================================================================

const BASE_URL = "https://extension-dev.phoneburner.biz";

// ---- CRM registry for context-aware behavior ----
const CRM_REGISTRY = [
  {
    id: "hubspot",
    displayName: "HubSpot",
    level: 3, // Level 3 (full integration capable)
    match: (host) => host.includes("app.hubspot.com"),
  },
  {
    id: "salesforce",
    displayName: "Salesforce",
    level: 1, // currently generic mode
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
    level: 2, // custom scraping / optimized list handling
    match: (host) => host.includes("pipedrive.com"),
  },
];

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
  // In case chrome.runtime isn't available (very rare), just ignore.
}

// ============================================================================
//  📡 GLOBAL STATE
// ============================================================================

let sse = null;
let currentSessionToken = null;
let sseReconnectTimer = null;

// Track record identity to avoid double navigations
let currentRecordId = null; // record we are currently on (once loaded)
let pendingRecordId = null; // record we most recently asked the browser to navigate to



// ============================================================================
//  🎯 GOAL CONFIGURATION (Primary / Secondary dispositions)
// ============================================================================

let goalConfig = {
  primary: "Set Appointment",
  secondary: "Follow Up",
};

function loadGoalConfig() {
  try {
    if (!chrome || !chrome.storage || !chrome.storage.local) return;
    chrome.storage.local.get(
      ["pb_goal_primary", "pb_goal_secondary"],
      (res) => {
        if (res && typeof res === "object") {
          if (res.pb_goal_primary && res.pb_goal_primary.trim()) {
            goalConfig.primary = res.pb_goal_primary.trim();
          }
          if (res.pb_goal_secondary && res.pb_goal_secondary.trim()) {
            goalConfig.secondary = res.pb_goal_secondary.trim();
          }
        }
      }
    );
  } catch (e) {
    console.error("Error loading goal config", e);
  }
}

function syncGoalsFromServer() {
  try {
    if (!chrome || !chrome.runtime) return;

    chrome.runtime.sendMessage({ type: "LOAD_SERVER_GOALS" }, (resp) => {
      if (chrome.runtime.lastError) {
        console.warn(
          "LOAD_SERVER_GOALS error:",
          chrome.runtime.lastError.message
        );
        return;
      }
      if (!resp || !resp.ok) return;

      const { primary, secondary } = resp;

      let changed = false;

      if (primary && primary.trim() && primary !== goalConfig.primary) {
        goalConfig.primary = primary.trim();
        changed = true;
      }
      if (
        secondary &&
        secondary.trim() &&
        secondary !== goalConfig.secondary
      ) {
        goalConfig.secondary = secondary.trim();
        changed = true;
      }

      // Cache on this browser so we don't have to hit the server every time
      if (changed && chrome.storage && chrome.storage.local) {
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


// Load once when the content script starts
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
//  🧮 GENERIC SCANNER HELPERS
// ============================================================================

function guessColumnIndex(headers, candidates) {
  const lower = headers.map((h) => h.toLowerCase());
  for (const candidate of candidates) {
    const idx = lower.findIndex((h) => h.includes(candidate));
    if (idx !== -1) return idx;
  }
  return -1;
}

// Treat a row as "selected" if it has a checked checkbox/radio or aria-selected="true"
function isRowSelected(row) {
  const cb = row.querySelector('input[type="checkbox"], input[type="radio"]');
  if (cb && cb.checked) return true;

  const aria = row.getAttribute("aria-selected");
  if (aria && aria.toLowerCase() === "true") return true;

  return false;
}

function isRowSelectedForActiveCrm(row) {
  // Use the already-computed context if we have it
  const ctx = CURRENT_CRM_CONTEXT || detectCrmContext();
  const crmId = ctx && ctx.crmId ? ctx.crmId : "generic";

  if (crmId === "salesforce") {
    // In Salesforce, only treat explicit checkboxes/radios as selection.
    // Ignore aria-selected, because Salesforce uses it for the "current" row.
    const cb = row.querySelector('input[type="checkbox"], input[type="radio"]');
    return !!(cb && cb.checked);
  }

  // For all other CRMs, keep the old behavior
  return isRowSelected(row);
}


// Try to find a "record URL" inside a row (for the CRM detail page)
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
  if (digits.length < 7 || digits.length > 15) return false;
  return true;
}

function extractPhoneFromRowFallback(row, currentPhone) {
  if (currentPhone && currentPhone.trim()) return currentPhone;

  const anchor = row.querySelector(
    'a[data-phone], a[class*="call"], a[class*="phone"]'
  );
  if (anchor) {
    const attrPhone = anchor.getAttribute("data-phone");
    if (attrPhone && attrPhone.trim()) {
      return attrPhone.trim();
    }
    if (anchor.innerText && anchor.innerText.trim()) {
      return anchor.innerText.trim();
    }
  }

  return currentPhone;
}

// ============================================================================
//  🆔 GENERIC ROW → CRM ID HELPERS (for Level 1 CRMs)
//  - These heuristics help us extract stable record IDs from arbitrary tables.
//  - They benefit BnTouch, Pipedrive (as backup), and many other CRMs.
// ============================================================================

// Query-string keys that likely carry a record identifier
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

// Scan for common numeric data-* attributes like data-id, data-record-id, data-pid, etc.
function findNumericDataId(el) {
  if (!el || !el.getAttribute) return null;

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
    if (val && /^\d+$/.test(val)) {
      return val;
    }
  }
  return null;
}

// Extract the trailing numeric portion from an id/name like "check_27662848"
function extractNumericSuffix(str) {
  if (!str) return null;
  const m = str.match(/(\d+)(?:\D*)$/);
  return m ? m[1] : null;
}

// Pull an ID-like value from an href query string, e.g. "?process=27662848"
function extractIdFromHrefQuery(href) {
  if (!href) return null;
  try {
    const url = new URL(href, window.location.origin);
    for (const key of GENERIC_ID_PARAM_KEYS) {
      const val = url.searchParams.get(key);
      if (val && /^\d+$/.test(val)) {
        return val;
      }
    }
  } catch (e) {
    // ignore bad URLs
  }
  return null;
}

// NEW: Pull IDs from path patterns like "/person/10", "/contact/123", "/lead/456"
function extractIdFromHrefPath(href) {
  if (!href) return null;
  try {
    const url = new URL(href, window.location.origin);
    const m = url.pathname.match(
      /\/(person|contact|lead|candidate|record)\/(\d+)/i
    );
    if (m && m[2]) {
      return m[2];
    }
  } catch (e) {
    // ignore
  }
  return null;
}

// Collect possible IDs from a table row using attributes + hrefs
function collectRowCandidateIds(row) {
  const candidates = new Set();
  if (!row) return [];

  const all = row.querySelectorAll("*");

  all.forEach((el) => {
    // 1) data-* attributes
    const dataId = findNumericDataId(el);
    if (dataId) {
      candidates.add(dataId);
    }

    // 2) id/name patterns like "check_27662848" or "row-12345"
    ["id", "name"].forEach((attr) => {
      const val = el[attr];
      if (val) {
        const numeric = extractNumericSuffix(val);
        if (numeric && /^\d+$/.test(numeric)) {
          candidates.add(numeric);
        }
      }
    });

    // 3) href query params like "?process=27662848" (BnTouch, etc.)
    //    + path patterns like "/person/10" (Pipedrive, others)
    if (el.tagName === "A" && el.href) {
      const hrefIdQuery = extractIdFromHrefQuery(el.href);
      if (hrefIdQuery) {
        candidates.add(hrefIdQuery);
      }

      const hrefIdPath = extractIdFromHrefPath(el.href);
      if (hrefIdPath) {
        candidates.add(hrefIdPath);
      }
    }
  });

  return Array.from(candidates);
}

// Pick a "best" ID for this row (for now we just pick the first candidate)
function pickBestRowId(row) {
  const ids = collectRowCandidateIds(row);
  if (!ids.length) return null;

  // In the future we could rank by frequency or prefix, but first hit is
  // already a huge improvement over "host-rowIndex".
  return ids[0];
}

// ============================================================================
//  📋 GENERIC HTML TABLE SCANNER
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

    const headerCells = Array.from(headerRow.children).map((th) =>
      th.innerText.trim()
    );
    if (!headerCells.length) continue;

    let nameIdx = guessColumnIndex(headerCells, [
      "name",
      "contact",
      "person",
      "candidate",
      "full name",
    ]);
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
          if (looksLikePhone(txt)) {
            phoneLikeCounts[i]++;
          }
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

    const hasAnyKeyColumn =
      nameIdx >= 0 || phoneIdx >= 0 || emailIdx >= 0;
    if (!hasAnyKeyColumn) continue;

    let score = 0;
    if (nameIdx >= 0) score += 3;
    if (phoneIdx >= 0) score += 2;
    if (emailIdx >= 0) score += 1;
    score += Math.min(bodyRows.length, 20);

    if (score > bestScore) {
      bestScore = score;
      bestTable = t;
      bestConfig = { headerCells, nameIdx, phoneIdx, emailIdx };
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

    const name =
      nameIdx >= 0 && cells[nameIdx]
        ? cells[nameIdx].innerText.trim()
        : "";

    let phone =
      phoneIdx >= 0 && cells[phoneIdx]
        ? cells[phoneIdx].innerText.trim()
        : "";

    const email =
      emailIdx >= 0 && cells[emailIdx]
        ? cells[emailIdx].innerText.trim()
        : "";

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
      // NEW: hint for backend external_id / external_crm_data
      crm_identifier: crmIdentifier || null,
      row_index: idx,
    });
  });

  return contacts;
}

// ============================================================================
//  📋 GENERIC ARIA GRID SCANNER
// ============================================================================

function scanAriaGridContacts() {
  const grid =
    document.querySelector('[role="grid"]') ||
    document.querySelector('[role="treegrid"]');
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
    const cells = Array.from(
      row.querySelectorAll('[role="gridcell"], [role="columnheader"]')
    );
    if (!cells.length) return;

    const name =
      nameIdx >= 0 && cells[nameIdx] ? cells[nameIdx].innerText.trim() : "";
    const phone =
      phoneIdx >= 0 && cells[phoneIdx] ? cells[phoneIdx].innerText.trim() : "";
    const email =
      emailIdx >= 0 && cells[emailIdx] ? cells[emailIdx].innerText.trim() : "";

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
      // NEW: hint for backend external_id / external_crm_data
      crm_identifier: crmIdentifier || null,
      row_index: idx,
    });
  });

  return contacts;
}

// ============================================================================
//  🧩 CRM-SPECIFIC SCANNERS
// ============================================================================

function scanZohoContacts() {
  return [];
}

function scanHubSpotContacts() {
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
  // Example: grid-cell-column-10-1 → "10" (grid row index, NOT person id)
  const m = dataCy.match(/grid-cell-column-(\d+)-1/);
  return m ? m[1] : null;
}

function findPipedriveEmailCell(rowId) {
  if (!rowId) return null;
  return document.querySelector(
    `[data-test="person.email"][data-cy^="grid-cell-column-${rowId}-"]`
  );
}

function findPipedrivePhoneCell(rowId) {
  if (!rowId) return null;
  return document.querySelector(
    `[data-test="person.phone"][data-cy^="grid-cell-column-${rowId}-"]`
  );
}

// Extract the person ID from urls like "/person/10" or "/person/10?foo=bar"
function extractPipedrivePersonIdFromHref(href) {
  if (!href) return null;
  try {
    const u = new URL(href, window.location.origin);
    const m = u.pathname.match(/\/person\/(\d+)/);
    if (m && m[1]) return m[1];
    // Fallback if they ever switch to ?id=10
    const idParam = u.searchParams.get("id");
    if (idParam) return idParam;
  } catch (e) {
    // ignore
  }
  return null;
}

function scanPipedriveContacts(maxContacts = 500) {
  const host = window.location.hostname || "";
  const path = window.location.pathname || "";
  if (!host.includes("pipedrive.com") || !path.includes("/persons")) {
    return [];
  }

  const contacts = [];
  const seenKeys = new Set();

  // Each "row" is really a group of cells, but the name cell is our anchor
  const nameCells = document.querySelectorAll('[data-test="person.name"]');
  console.log("[PB-CRM] Pipedrive name cells:", nameCells.length);

  nameCells.forEach((nameCell) => {
    if (contacts.length >= maxContacts) return;

    const dataCy = nameCell.getAttribute("data-cy") || "";
    const rowId = getPipedriveRowIdFromDataCy(dataCy);
    if (!rowId) return;

    // ----- Person link + ID -----
    const personLink = nameCell.querySelector('a[href*="/person/"]');
    if (!personLink) return;

    const rawHref = personLink.getAttribute("href") || "";
    let personId = extractPipedrivePersonIdFromHref(rawHref);
    let recordUrl = null;

    try {
      // Build absolute URL for the detail page
      recordUrl = new URL(rawHref, window.location.origin).href;
    } catch (e) {
      // If URL construction fails we still keep personId if we have it
      recordUrl = null;
    }

    const name = (personLink.textContent || "").replace(/\s+/g, " ").trim();

    // ----- Email -----
    let email = "";
    const emailCell = findPipedriveEmailCell(rowId);
    if (emailCell) {
      const emailLink = emailCell.querySelector('a[href^="mailto:"]');
      if (emailLink) {
        email = (emailLink.textContent || "").replace(/\s+/g, " ").trim();

        // Fallback: if we somehow didn't get personId yet, Pipedrive also
        // exposes ?related_person_id=NN on the mailto link
        if (!personId) {
          const mailHref = emailLink.getAttribute("href") || "";
          const m = mailHref.match(/related_person_id=(\d+)/);
          if (m && m[1]) {
            personId = m[1];
          }
        }
      }
    }

    // ----- Phone -----
    let phone = "";
    const phoneCell = findPipedrivePhoneCell(rowId);
    if (phoneCell) {
      const phoneLink =
        phoneCell.querySelector('a[href^="callto:"], a[href^="tel:"]') ||
        phoneCell.querySelector("a[href]");
      if (phoneLink) {
        phone = (phoneLink.textContent || "").replace(/\s+/g, " ").trim();
      }
    }

    // If we still have no phone and no email, skip (nothing dialable)
    if (!phone && !email) return;

    // Ensure we don't push duplicates
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

    // **Important**: link the real Pipedrive person id
    if (personId) {
      contact.crm_identifier = personId; // e.g. "10" for /person/10
    }

    contacts.push(contact);
  });

  console.log("[PB-CRM] Pipedrive contacts extracted:", contacts.length);
  return contacts.slice(0, maxContacts);
}

// Dispatcher
function scanPageForContacts() {
  const { crmId } = detectCrmContext();

  let contacts = [];

  if (crmId === "zoho") {
    contacts = scanZohoContacts();
  } else if (crmId === "hubspot") {
    contacts = scanHubSpotContacts();
  } else if (crmId === "monday") {
    contacts = scanMondayContacts();
  } else if (crmId === "pipedrive") {
    contacts = scanPipedriveContacts();
  }

  if (!contacts || !contacts.length) {
    contacts = scanHtmlTableContacts();
    if (!contacts.length) {
      contacts = scanAriaGridContacts();
    }
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

    // Only do SF logic if it's a Lightning URL
    if (!u.hostname.includes("lightning.force.com")) return null;

    const parts = u.pathname.split("/").filter(Boolean);
    const rIdx = parts.indexOf("r");
    if (rIdx === -1) return null;

    // Look for the first thing that looks like an SF ID after "r"
    for (let i = rIdx + 1; i < parts.length; i++) {
      if (looksLikeSfId(parts[i])) {
        return parts[i];
      }
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
    if (cid) {
      return { crm: "jobdiva", type: "contact", id: cid };
    }
    const candidateId = u.searchParams.get("candidateid");
    if (candidateId) {
      return { crm: "jobdiva", type: "candidate", id: candidateId };
    }
    return null;
  } catch (e) {
    return null;
  }
}


function isSameCrmRecord(currentUrl, targetUrl) {
  if (!targetUrl) return false;

  // First try record IDs (handles /lightning/r/ID vs /lightning/r/Contact/ID/view)
  const curId = extractSalesforceRecordIdSafe(currentUrl);
  const tgtId = extractSalesforceRecordIdSafe(targetUrl);
  if (curId && tgtId) {
    return curId === tgtId;
  }

  // Fallback: simple origin+pathname compare (for non-SF CRMs)
  try {
    const cur = new URL(currentUrl, window.location.origin);
    const tgt = new URL(targetUrl, window.location.origin);
    const curKey = cur.origin + cur.pathname;
    const tgtKey = tgt.origin + tgt.pathname;
    return curKey === tgtKey;
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

    if (sse === es) {
      sse = null;
    }

    if (!currentSessionToken || currentSessionToken !== sessionToken) {
      return;
    }

    if (!sseReconnectTimer) {
      sseReconnectTimer = setTimeout(() => {
        sseReconnectTimer = null;
        if (currentSessionToken === sessionToken) {
          console.log("Reconnecting SSE for session", sessionToken);
          openSseConnection(sessionToken);
        }
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
    chrome.runtime.sendMessage({ type: "STOP_FOLLOW_SESSION" }, () => {
      if (chrome.runtime.lastError) {
        console.warn(
          "STOP_FOLLOW_SESSION error:",
          chrome.runtime.lastError.message
        );
      }
    });
  } catch (e) {
    console.error("Error sending STOP_FOLLOW_SESSION", e);
  }
}

// ============================================================================
//  🧊 OVERLAY WIDGET + SESSION UPDATE HANDLING
// ============================================================================

let overlayEl = null;

function handleSessionUpdate(state) {
  console.log("Session update:", state);

  const current = state.current || {};
  const lastCall = state.last_call || {};

  const sessionStats = state.stats || {};
  const dailyStats = state.daily_stats || null;
  const stats = dailyStats || sessionStats;
  const byStatus = stats.by_status || {};

  // JobDiva URL hints (optional)
  const jobdivaInfo = extractJobDivaInfoFromUrl(window.location.href);
  if (jobdivaInfo) {
    if (!current.external_id) {
      current.external_id = jobdivaInfo.id;
    }
    if (!current.record_type) {
      current.record_type = jobdivaInfo.type;
    }
  }

  const name =
    current.name ||
    (current.raw && current.raw.contact && current.raw.contact.name) ||
    "";
  const phone =
    current.phone ||
    (current.raw && current.raw.contact && current.raw.contact.phone) ||
    "";
  const email =
    current.email ||
    (current.raw && current.raw.contact && current.raw.contact.email) ||
    "";

  let recordUrl = current.record_url || current.crm_url || null;


  const primaryCount = byStatus[goalConfig.primary] || 0;
  const secondaryCount = byStatus[goalConfig.secondary] || 0;

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

  // Auto-follow: for non-SF CRMs we compare URL; for SF we compare record IDs
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
  if (!overlayEl) {
    overlayEl = document.createElement("div");
    overlayEl.style.position = "fixed";
    overlayEl.style.right = "10px";
    overlayEl.style.bottom = "10px";
    overlayEl.style.zIndex = "999999";
    overlayEl.style.background = "white";
    overlayEl.style.border = "1px solid #ccc";
    overlayEl.style.borderRadius = "6px";
    overlayEl.style.padding = "8px";
    overlayEl.style.fontSize = "12px";
    overlayEl.style.boxShadow = "0 2px 6px rgba(0,0,0,0.2)";
    overlayEl.style.maxWidth = "260px";
    overlayEl.style.fontFamily = "system-ui, sans-serif";
    document.body.appendChild(overlayEl);
  }

  const totalCalls = stats.total_calls || 0;
  const connected = stats.connected || 0;
  const appointments = stats.appointments || 0;

  const lastStatus = (lastCall && lastCall.status) || "";
  const lastDuration = (lastCall && lastCall.duration) || null;

  overlayEl.innerHTML = `
    <div><strong>Now calling</strong></div>
    <div>${name || "(unknown)"}<\/div>
    <div>${phone || ""}<\/div>
    <div>${email || ""}<\/div>
    ${
      recordUrl
        ? `<div style="margin-top:4px;">
             <button id="pb-open-record-btn"
                     style="font-size:11px;padding:2px 6px;cursor:pointer;">
               Open in CRM
             <\/button>
           <\/div>`
        : ""
    }
    <hr>
    <div><strong>Last call<\/strong><\/div>
    <div>Status: ${lastStatus || "–"}<\/div>
    <div>${lastDuration != null ? `Duration: ${lastDuration}s` : ""}<\/div>
    <hr>
    <div><strong>Stats<\/strong><\/div>
    <div>Total calls: ${totalCalls}<\/div>
    <div>Connected: ${connected}<\/div>
    <div>Appointments: ${appointments}<\/div>
    <div style="margin-top:4px;"><strong>Goals<\/strong><\/div>
    <div>${goalConfig.primary}: ${primaryCount}<\/div>
    <div>${goalConfig.secondary}: ${secondaryCount}<\/div>
    <div style="margin-top:4px; text-align:right;">
      <a href="#" id="pb-stop-follow-link" style="font-size:11px;">Stop following<\/a>
    <\/div>
  `;

  if (recordUrl) {
    const btn = overlayEl.querySelector("#pb-open-record-btn");
    if (btn) {
      btn.onclick = () => {
        window.open(recordUrl, "_blank");
      };
    }
  }

  const stopLink = overlayEl.querySelector("#pb-stop-follow-link");
  if (stopLink) {
    stopLink.onclick = (e) => {
      e.preventDefault();
      stopFollowingSession();
    };
  }
}

// ============================================================================
//  🔁 MESSAGE HANDLER (popup/background ↔ content)
// ============================================================================

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  // HubSpot Level 3 selection
  if (msg && msg.type === "HS_GET_SELECTION") {
    const ctx = detectCrmContext();
    if (ctx.crmId !== "hubspot") {
      sendResponse({ ok: false, error: "Not on a HubSpot page." });
      return true;
    }

    (async () => {
      try {
        const selection = await getHubSpotSelectionAsync();
        sendResponse(selection);
      } catch (e) {
        console.error("getHubSpotSelectionAsync error", e);
        sendResponse({ ok: false, error: "Error reading HubSpot selection." });
      }
    })();

    return true;
  }

  const isTopWindow = window.top === window;

  if (msg.type === "SCAN_PAGE" && !isTopWindow) {
    sendResponse({ ok: false, error: "ignored_iframe" });
    return true;
  }

  if (msg.type === "SCAN_PAGE") {
    console.log(
      "[PB-UNIFIED] content: SCAN_PAGE received on",
      window.location.href
    );

    const contacts = scanPageForContacts();
    console.log("[PB-UNIFIED] content: contacts found:", contacts.length);

    if (!contacts.length) {
      const host = window.location.hostname;

      const tablesDebug = Array.from(document.querySelectorAll("table"))
        .slice(0, 5)
        .map((t) => {
          const rows = t.querySelectorAll("tr");
          const firstRow = rows[0] ? rows[0].innerText.slice(0, 200) : "";
          return {
            rows: rows.length,
            firstRow,
          };
        });

      const ariaGridsDebug = Array.from(
        document.querySelectorAll('[role="grid"], [role="treegrid"]')
      )
        .slice(0, 5)
        .map((g) => {
          const rows = g.querySelectorAll('[role="row"]');
          const headerRow = rows[0];
          const headerText = headerRow ? headerRow.innerText.slice(0, 200) : "";
          return {
            rows: rows.length,
            headerText,
          };
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
      }).catch((err) => {
        console.error("scan_debug failed", err);
      });

      alert("Could not find a contact table on this page.");
      sendResponse({ ok: false, error: "No contacts found" });
      return true;
    }

  const ctx = detectCrmContext();

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
      const msgText =
        (resp && (resp.error || resp.details)) ||
        "Unknown error creating dial session";
      alert("Error creating dial session: " + msgText);
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
    if (msg.primary && msg.primary.trim()) {
      goalConfig.primary = msg.primary.trim();
    }
    if (msg.secondary && msg.secondary.trim()) {
      goalConfig.secondary = msg.secondary.trim();
    }

    // Persist in this browser so it survives reloads
    try {
      if (chrome && chrome.storage && chrome.storage.local) {
        chrome.storage.local.set({
          pb_goal_primary: goalConfig.primary,
          pb_goal_secondary: goalConfig.secondary,
        });
      }
    } catch (e) {
      console.error("Error saving goals to local storage", e);
    }

    // Persist on the server so goals follow the user between browsers
    try {
      if (chrome && chrome.runtime) {
        chrome.runtime.sendMessage({
          type: "SAVE_GOALS",
          goals: {
            primary: goalConfig.primary,
            secondary: goalConfig.secondary,
          },
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
    chrome.runtime.sendMessage(
      { type: "GET_ACTIVE_SESSION_FOR_TAB" },
      (resp) => {
        if (chrome.runtime.lastError) {
          return;
        }
        if (resp && resp.sessionToken) {
          console.log("Resuming follow for session", resp.sessionToken);

          const idNow = extractSalesforceRecordIdSafe(window.location.href);
          if (idNow) {
            currentRecordId = idNow;
            pendingRecordId = idNow;
          }

          startFollowingSession(resp.sessionToken);
        }
      }
    );
  } catch (e) {
    console.error("Error requesting active session for tab", e);
  }
}

console.log("[PB-UNIFIED-CRM] content script initialized");
