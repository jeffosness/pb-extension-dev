# PhoneBurner CRM Extension — AI Assistant & Developer Guide

**Purpose:** Define non-negotiable guardrails, architectural patterns, and security requirements for contributors and AI assistants working in this repository.

**Goal:** Ship safely, avoid duplication, preserve consistent patterns across CRM providers, and maintain the security posture established through recent hardening work.

---

## 📋 Table of Contents

1. [Golden Rules (Read First)](#golden-rules-read-first)
2. [Architecture Overview](#architecture-overview)
3. [Security First: Non-Negotiable Requirements](#security-first-non-negotiable-requirements)
4. [CRM Provider Isolation Model](#crm-provider-isolation-model)
5. [Critical Utilities & Patterns](#critical-utilities--patterns)
6. [Authentication & Token Management](#authentication--token-management)
7. [Session Management & Real-Time Streaming](#session-management--real-time-streaming)
8. [Mandatory "Think-Check-Code" Workflow](#mandatory-think-check-code-workflow)
9. [Testing & Debugging](#testing--debugging)
10. ["Do Not Break" List (Stability Contracts)](#do-not-break-list-stability-contracts)
11. [Adding New CRM Providers](#adding-new-crm-providers)
12. [Deployment Checklist](#deployment-checklist)

---

## Golden Rules (Read First)

### 🚨 Critical Rules

1. **NEVER log or expose sensitive data**
   - Tokens, PATs, passwords, emails, phone numbers must be redacted
   - Use `redact_pii_recursive()` before ANY logging
   - Session tokens in logs must be hashed via `substr(hash('sha256', $token), 0, 12)`

2. **NEVER put tokens or credentials in URLs**
   - Use temporary single-use codes via `temp_code_store()` / `temp_code_retrieve_and_delete()`
   - Codes expire after 5 minutes and can only be used once
   - Only exception: webhooks from PhoneBurner (trusted backend)

3. **NEVER store tokens inside the web root**
   - All tokens go in `TOKENS_DIR` (configured in `config.php`, typically `/var/lib/pb-extension-dev/tokens`)
   - Use `atomic_write_json()` with 0600 permissions
   - Directories must be 0700 (owner-only)

4. **ALWAYS use existing helpers — do not reinvent**
   - `safe_file_path()` for ALL file operations
   - `api_ok()` / `api_error()` for ALL API responses
   - `rate_limit_or_fail()` for ALL public endpoints
   - `get_client_id_or_fail()` for ALL authenticated requests

5. **ALWAYS validate and sanitize input**
   - Use `preg_replace('/[^a-zA-Z0-9_-]/', '', $input)` for IDs
   - Use `safe_file_path()` for any path construction
   - Check types with `is_array()`, `is_string()`, etc.
   - Use `json_input()` instead of `file_get_contents('php://input')`

6. **If you touch auth, CORS, webhooks, or SSE — verify end-to-end**
   - Extension → server → CRM → PhoneBurner → webhooks → SSE → extension
   - Test the complete flow, not just your change

### 🎯 Architecture Rules

7. **Keep provider logic isolated**
   - HubSpot (L3) lives in `/api/crm/hubspot/`
   - Generic (L1/L2) lives in `/api/crm/generic/`
   - Provider-specific code stays in provider directories
   - Shared code goes in `utils.php` or `bootstrap.php`

8. **Minimal diff mentality**
   - Small PRs — one behavior change per PR when possible
   - Prefer refactoring existing code over creating new files
   - If you change a shared utility, update ALL call sites

9. **Backward compatibility is required**
   - Server endpoints must work with published extension versions
   - If you must break compatibility, version the endpoint and migrate

---

## Architecture Overview

### Components

```
┌─────────────────────────────────────────────────────────────┐
│ CHROME EXTENSION (MV3)                                      │
│  ├─ manifest.json          Service worker config            │
│  ├─ background.js          Message router, API client       │
│  ├─ popup.js               UI logic (PAT, OAuth, tabs)      │
│  └─ content.js             CRM scraping, SSE follow-me      │
└─────────────────────────────────────────────────────────────┘
                              ↓ HTTPS (fetch + credentials)
┌─────────────────────────────────────────────────────────────┐
│ PHP BACKEND API                                             │
│  ├─ bootstrap.php          CORS, security headers, logging  │
│  ├─ utils.php              Token mgmt, PII redaction, safe  │
│  │                          file ops, rate limiting          │
│  ├─ /api/core/             PAT, settings, state, stats      │
│  ├─ /api/crm/generic/      L1/L2 providers (scan-based)     │
│  ├─ /api/crm/hubspot/      L3 (full OAuth + API)            │
│  ├─ /webhooks/             PhoneBurner callbacks            │
│  └─ sse.php                Real-time SSE stream             │
└─────────────────────────────────────────────────────────────┘
                              ↓ REST API
┌─────────────────────────────────────────────────────────────┐
│ EXTERNAL APIS                                               │
│  ├─ PhoneBurner API        /rest/1/dialsession, /members/me │
│  └─ CRM APIs               HubSpot v3, Salesforce, etc.     │
└─────────────────────────────────────────────────────────────┘
```

### Three-Level CRM Integration Model

| Level  | Method                | CRMs                         | Capabilities                                 |
| ------ | --------------------- | ---------------------------- | -------------------------------------------- |
| **L1** | Generic HTML scraping | Salesforce, Zoho, Monday.com | Extract from HTML tables/ARIA grids          |
| **L2** | CRM-specific scraping | Pipedrive                    | Custom DOM selectors per CRM                 |
| **L3** | Full API integration  | HubSpot                      | OAuth + server-side API calls + associations |

**Rule:** Never mix levels. L1/L2 use `/api/crm/generic/`, L3 gets its own provider directory.

---

## Security First: Non-Negotiable Requirements

### Recent Security Fixes (DO NOT REGRESS)

✅ **CORS Origin Whitelist** — Only whitelisted origins can make credentialed requests
✅ **Tokens Outside Webroot** — Stored in `/var/lib/` with 0600 permissions
✅ **Temporary Session Codes** — Single-use 5-minute codes instead of tokens in URLs
✅ **PII Redaction** — Recursive pattern-based redaction before logging
✅ **Path Traversal Protection** — `safe_file_path()` with `realpath()` validation
✅ **Rate Limiting** — Per-client rolling-window rate limits

### Security Checklist for Every Change

Before committing, verify:

- [ ] No tokens/secrets in git (check with `git diff`)
- [ ] No PII in logs (use `redact_pii_recursive()`)
- [ ] All file paths use `safe_file_path()`
- [ ] All API endpoints include `rate_limit_or_fail()`
- [ ] All token storage uses `atomic_write_json()` with 0600
- [ ] No credentials in URLs (use temp codes)
- [ ] CORS origins still whitelisted (no origin reflection)
- [ ] Input validation on all user-controlled data

### Critical Security Utilities (ALWAYS USE)

```php
// 1. Safe file operations
$path = safe_file_path($baseDir, $userInput);
if (!$path) api_error('Invalid path', 'bad_request', 400);

// 2. Secure token storage
atomic_write_json(pb_token_path($client_id), $tokenData);
// → Creates with 0600 permissions, atomic rename

// 3. PII redaction before logging
$fields = redact_pii_recursive($fields);
api_log('event_name', $fields);

// 4. Temporary codes (NOT raw tokens)
$code = temp_code_store($session_token, 300); // 5-min TTL
$launch_url = $base_url . '?code=' . urlencode($code);

// 5. Rate limiting
rate_limit_or_fail($client_id, 60); // 60 req/min

// 6. Input sanitization
$client_id = get_client_id_or_fail($data); // Validates + sanitizes
```

### Known Remaining Security Issues

**MEDIUM RISK (fix before production):**

1. Session state file permissions are 0777 — should be 0700/0600
2. Webhooks lack origin validation — add HMAC signature or origin check
3. Webhook session tokens in URLs — consider webhook signature verification
4. Metrics directories exposed under web root — `metrics/` and `metrics/sse_presence/` contain operational data (SSE usage logs, presence files) that are publicly accessible if directory listing is enabled. Move to `/var/lib/pb-extension-dev/metrics/` or add Apache deny rules.

**LOW RISK (harden when time permits):**

1. Date validation allows future dates
2. No cleanup of stale presence files
3. No CSP on extension popup
4. Webhook handlers log full payloads with PII — `log_msg('call_done: ' . $raw)` and `log_msg('contact_displayed: ' . $raw)` in webhook handlers log complete payloads containing names, phone numbers, emails, and CRM IDs. Use selective logging or debug flag.

---

## CRM Provider Isolation Model

### Directory Structure

```
server/public/api/crm/
├── generic/
│   └── dialsession_from_scan.php    # L1/L2: Accepts scraped contacts
│
└── hubspot/                         # L3: Full API integration
    ├── oauth_hs_start.php           # OAuth flow initiation
    ├── oauth_hs_finish.php          # OAuth callback handler
    ├── oauth_disconnect.php         # Disconnect
    ├── pb_dialsession_selection.php # Dial from selected records
    └── state.php                    # Connection status
```

### Provider Isolation Rules

1. **Each L3 provider gets its own directory:** `/api/crm/{provider}/`
2. **Provider-specific code stays in provider directory** — no cross-provider coupling
3. **Shared logic goes in `utils.php`** — normalize to common format before calling PhoneBurner
4. **L1/L2 providers share `/api/crm/generic/`** — detection happens in `content.js`

### Provider Adapter Contract

Every L3 provider must implement:

```php
// 1. OAuth flow
oauth_{provider}_start.php    // Return auth URL
oauth_{provider}_finish.php   // Exchange code for tokens
oauth_disconnect.php          // Clear tokens

// 2. Token refresh
{provider}_refresh_access_token_or_fail($client_id)
// → Returns fresh access token or calls api_error()

// 3. Dial session creation
pb_dialsession_selection.php
// → Accept: { object_ids, object_type, portal_id, ... }
// → Return: { session_token, temp_code, launch_url, ... }

// 4. Connection state
state.php
// → Return: { connected: bool, profile: {...} }
```

---

## Critical Utilities & Patterns

### bootstrap.php (API Framework)

**Include at top of EVERY API endpoint:**

```php
<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../utils.php';
require_once __DIR__ . '/bootstrap.php';
```

**Key Features:**

- CORS whitelist (configurable via `PB_CORS_ORIGINS`)
- Security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy)
- Automatic PII redaction in logging
- Structured JSON responses

**Response Helpers:**

```php
api_ok($data, $status = 200);           // { "ok": true, "data": {...} }
api_ok_flat($data, $status = 200);      // { "ok": true, ...flatKeys }
api_error($msg, $code, $status, $extra);// { "ok": false, "error": {...} }
```

**Logging:**

```php
api_log('event_name', [
  'client_id_hash' => substr(hash('sha256', $client_id), 0, 12),
  'duration_ms' => $elapsed,
  // ... redacted automatically
]);
```

### utils.php (Security & Token Management)

**Critical Functions:**

| Function                               | Purpose                    | Security Level |
| -------------------------------------- | -------------------------- | -------------- |
| `safe_file_path($base, $relative)`     | Prevent path traversal     | **CRITICAL**   |
| `atomic_write_json($path, $data)`      | Secure token storage       | **CRITICAL**   |
| `temp_code_store($token, $ttl)`        | Generate single-use codes  | **CRITICAL**   |
| `temp_code_retrieve_and_delete($code)` | Exchange code for token    | **CRITICAL**   |
| `redact_pii_recursive($data)`          | Scrub sensitive data       | **CRITICAL**   |
| `rate_limit_or_fail($id, $max)`        | Enforce rate limits        | **HIGH**       |
| `get_client_id_or_fail($data)`         | Validate client ID         | **HIGH**       |
| `load_pb_token($client_id)`            | Load PAT (migrates legacy) | **HIGH**       |
| `load_hs_token($client_id)`            | Load HubSpot tokens        | **HIGH**       |

**Token Migration Logic:**

```php
// Automatically migrates tokens from old location (inside webroot)
// to new location (outside webroot) on first load
migrate_token_file_if_needed($legacyPath, $newPath);
```

---

## Authentication & Token Management

### PhoneBurner PAT Flow

```
User pastes PAT in popup
  ↓
popup.js → sendMessage({ type: "SAVE_PAT", pat })
  ↓
background.js → POST /api/core/oauth_pb_save.php
  ↓
Server validates via PhoneBurner /members/me
  ↓
Save token: /var/lib/.../tokens/pb/{client_id}.json (0600)
Save profile: .../user_settings/{member_user_id}.json
  ↓
Return profile to extension
```

**Implementation:**

```php
// In oauth_pb_save.php
$pat = $data['pat'] ?? null;
$memberInfo = pb_validate_pat($pat); // Calls PB API
atomic_write_json(pb_token_path($client_id), [
  'pat' => $pat,
  'member_user_id' => $memberInfo['member_user_id'],
  'created_at' => date('c'),
]);
```

### HubSpot OAuth Flow (L3)

```
1. popup.js → POST /api/crm/hubspot/oauth_hs_start.php
2. Server returns auth_url with state=client_id
3. User approves at HubSpot
4. HubSpot redirects to oauth_hs_finish.php?code=...&state=...
5. Server exchanges code for refresh_token + access_token
6. Save tokens with expiry: /var/lib/.../tokens/hubspot/{client_id}.json
7. Redirect back to extension UI
```

**Token Refresh:**

```php
// Automatically refresh expired tokens
$hsToken = load_hs_token($client_id);
if (time() > $hsToken['expires_at']) {
  $hsToken = hs_refresh_access_token_or_fail($client_id);
}
```

### Session Token Security

**NEVER expose session tokens directly in URLs.**

**Correct Pattern:**

```php
// 1. Create session token
$session_token = bin2hex(random_bytes(16)); // 32-char hex

// 2. Generate temporary code (single-use, 5-min TTL)
$temp_code = temp_code_store($session_token, 300);

// 3. Use code in URL, NOT token
$launch_url = $dialsession_url . '?code=' . urlencode($temp_code);

// 4. In SSE endpoint (sse.php):
$code = $_GET['code'] ?? '';
$session_token = temp_code_retrieve_and_delete($code); // One-time use
if (!$session_token) {
  exit('Code expired or invalid');
}
```

---

## Session Management & Real-Time Streaming

### Session State File Structure

Location: `server/public/sessions/{session_token}.json`

```json
{
  "session_token": "abc123...",
  "dialsession_id": "ds-123456",
  "client_id": "uuid-...",
  "created_at": "2026-01-23T10:00:00Z",

  "current": {
    "received_at": "2026-01-23T10:05:00Z",
    "name": "John Doe",
    "phone": "555-1234567",
    "email": "john@example.com",
    "record_url": "https://app.hubspot.com/...",
    "crm_name": "hubspot"
  },

  "stats": {
    "total_calls": 47,
    "connected": 31,
    "appointments": 8
  },

  "contacts_map": {
    "{contactId}": {
      /* contact details */
    }
  }
}
```

**IMPORTANT:** Session files currently created with insecure permissions (MEDIUM RISK). When creating/updating session files, use:

```php
// TODO: Fix session file permissions (see SECURITY_REVIEW.md)
save_session_state($session_token, $state);
// Should use atomic_write_json() with 0600 permissions
```

### SSE Stream (sse.php)

**Purpose:** Real-time follow-me updates from PhoneBurner webhooks to extension overlay.

**Connection Flow:**

```
Browser: new EventSource('/sse.php?code=temp_code')
  ↓
Server: Exchange code for session_token (one-time)
  ↓
Server: Watch session file with filemtime() polling
  ↓
On file change: Send "update" event with session state
  ↓
Browser: content.js receives update → render overlay → navigate CRM
```

**Implementation Notes:**

- SSE endpoint opts out of JSON response via `PB_BOOTSTRAP_NO_JSON`
- Uses `text/event-stream` content type
- Emits `event: update` with JSON payload
- Heartbeat every 30 seconds to keep connection alive
- Cleanup presence file on disconnect via `register_shutdown_function()`

### Webhook Handlers

**contact_displayed.php** — PhoneBurner sends when now calling a contact

```php
// PhoneBurner calls: /webhooks/contact_displayed.php?s=session_token
// Payload: { external_crm_data: { crm_identifier: "123" } }
// Action: Lookup contact in contacts_map → update "current"
```

**call_done.php** — PhoneBurner sends when call finishes

```php
// Payload: { status: "Set Appointment", duration: 245, connected: "1" }
// Action: Update stats, last_call, daily_stats/{member_user_id}.json
```

**Security Note:** Webhooks currently accept session_token in URL (trusted backend). Consider adding HMAC signature validation for defense-in-depth.

---

## Mandatory "Think-Check-Code" Workflow

### Before Writing Code

1. **Search for existing implementation**

   ```bash
   # Find similar endpoints
   grep -r "function_name" server/public/

   # Find similar patterns
   grep -r "pattern" chrome-extension/
   ```

2. **Check for shared utilities**
   - Look in `utils.php` for token/file/validation helpers
   - Look in `bootstrap.php` for API response patterns
   - Look in `content.js` for CRM detection patterns

3. **Trace call sites if changing shared code**

   ```bash
   # Find all usages
   grep -r "safe_file_path" server/public/
   ```

4. **Plan minimal diff**
   - Can you extend existing code instead of adding new files?
   - Can you refactor to avoid duplication?

### After Writing Code

1. **Run security checks**

   ```bash
   # Check for exposed secrets
   git diff | grep -iE "(password|token|secret|key)"

   # Check for unsafe file operations
   git diff | grep -E "(file_put_contents|fopen|mkdir)" | grep -v "safe_file_path"

   # Check for missing PII redaction
   git diff | grep "api_log" | grep -v "redact_pii"
   ```

2. **Test end-to-end**
   - Load unpacked extension in Chrome
   - Authenticate (PAT + HubSpot OAuth if L3)
   - Test dial session creation
   - Verify SSE connection + webhooks
   - Check overlay display + CRM navigation
   - Review server logs for errors/PII

3. **Verify no regressions**
   - Test both L1/L2 (generic scan) and L3 (HubSpot) flows
   - Test existing CRMs (BnTouch, Pipedrive, HubSpot)
   - Verify existing sessions still work

---

## Testing & Debugging

### Local Development Setup

```bash
# 1. Start PHP dev server
cd server/public
php -S 127.0.0.1:8000

# 2. Copy config
cp config.sample.php config.php
# Edit config.php with your credentials

# 3. Create secure token directory
mkdir -p /var/lib/pb-extension-dev/tokens/{pb,hubspot}
chmod 0700 /var/lib/pb-extension-dev/tokens
chmod 0700 /var/lib/pb-extension-dev/tokens/{pb,hubspot}

# 4. Load extension in Chrome
# chrome://extensions → Developer Mode → Load Unpacked
# Select chrome-extension/ directory

# 5. Update BASE_URL in extension
# Edit chrome-extension/background.js and popup.js
# Set BASE_URL to http://127.0.0.1:8000
```

### Debug Endpoints

```bash
# Health check
curl http://127.0.0.1:8000/health.php

# Version info
curl http://127.0.0.1:8000/version.php

# Test API endpoint
curl -X POST http://127.0.0.1:8000/api/core/state.php \
  -H "Content-Type: application/json" \
  -d '{"client_id":"test-client"}'
```

### Browser Console Debugging

```javascript
// Check CRM context
chrome.tabs.query({ active: true }, (tabs) => {
  chrome.tabs.sendMessage(tabs[0].id, { type: "GET_CONTEXT" }, console.log);
});

// Get session status
chrome.runtime.sendMessage({ type: "GET_ACTIVE_SESSION_FOR_TAB" }, console.log);

// Get stored client ID
chrome.storage.local.get(["pb_unified_client_id"], console.log);
```

### Server Log Monitoring

```bash
# Watch API logs
tail -f /opt/pb-extension-dev/var/log/app.log | jq

# Watch SSE usage logs
tail -f server/public/metrics/sse_usage-$(date +%Y-%m-%d).log | jq

# Check session file
cat server/public/sessions/{session_token}.json | jq
```

### Common Issues & Solutions

| Issue                  | Diagnosis                           | Solution                                      |
| ---------------------- | ----------------------------------- | --------------------------------------------- |
| "PAT invalid"          | PAT expired or wrong format         | Re-save PAT in popup                          |
| "No dialable contacts" | Missing phone/email in scraped data | Check content.js scanner output               |
| SSE not connecting     | CORS or permissions issue           | Check browser console + Network tab           |
| Webhooks not firing    | PhoneBurner config issue            | Verify webhook URLs in PB admin               |
| Session file not found | Session token mismatch              | Check logs for session creation error         |
| CORS error             | Origin not whitelisted              | Add origin to `PB_CORS_ORIGINS` in config.php |

---

## "Do Not Break" List (Stability Contracts)

### API Response Formats (Extension Depends On These)

```javascript
// State endpoint
{ ok: true, pb_ready: bool, hs_ready: bool, profile: {...} }

// Dial session creation
{ ok: true, session_token: "...", temp_code: "...", launch_url: "..." }

// OAuth start
{ ok: true, auth_url: "..." }

// SSE events
event: update
data: { current: {...}, stats: {...}, last_call: {...} }
```

**Rule:** If you change response format, update extension code first, then deploy in lockstep.

### Message Types (Extension Internal)

Chrome extension message types (handled in `background.js`):

- `GET_CONTEXT` — Get current CRM context
- `SCAN_AND_LAUNCH` — Scan page + create dial session (L1/L2)
- `HS_LAUNCH_FROM_SELECTED` — Create dial from selected records (L3)
- `SCANNED_CONTACTS` — Content script returns contacts
- `GET_STATE` — Get connection status (PAT + HubSpot)
- `SAVE_PAT` — Save PhoneBurner PAT
- `CLEAR_PAT` — Clear PAT
- `START_FOLLOW_SESSION` — Register follow-me session
- `STOP_FOLLOW_SESSION` — Unregister follow-me

**Rule:** Changing message types requires updating all handlers.

### Session State Schema

Session files must always have:

```json
{
  "session_token": "string",
  "dialsession_id": "string",
  "client_id": "string",
  "created_at": "ISO8601",
  "current": {
    /* current contact */
  },
  "stats": {
    /* aggregate stats */
  },
  "contacts_map": {
    /* id -> contact */
  }
}
```

**Rule:** You can add fields, but never remove or rename required fields.

### Webhook Payload Parsing

PhoneBurner sends:

```json
// contact_displayed
{
  "external_crm_data": {
    "crm_identifier": "contactId",
    "crm_name": "hubspot"
  }
}

// call_done
{
  "status": "Set Appointment",
  "duration": 245,
  "connected": "1"
}
```

**Rule:** Changing webhook parsing breaks active sessions. Test thoroughly.

### Token File Format

All token files must include:

```json
{
  "created_at": "ISO8601"
  // ... provider-specific fields
}
```

**Rule:** Changing format requires migration logic in `load_*_token()` functions.

---

## Adding New CRM Providers

### Adding L1/L2 Provider (HTML Scraping)

**Step 1:** Add to CRM registry in `content.js`

```javascript
const CRM_REGISTRY = [
  {
    id: "mynewcrm",
    displayName: "My New CRM",
    level: 2,
    match: (host) => host.includes("mycrm.example.com"),
  },
  // ...
];
```

**Step 2:** Implement scanner function

```javascript
function scanMyNewCrmContacts() {
  // Find table/list in DOM
  const rows = document.querySelectorAll(".crm-table tr");

  return Array.from(rows)
    .map((row) => ({
      name: row.querySelector(".name").textContent,
      phone: row.querySelector(".phone").textContent,
      email: row.querySelector(".email").textContent,
      record_url: row.querySelector("a").href,
      crm_identifier: extractIdFromUrl(row.querySelector("a").href),
    }))
    .filter((c) => c.phone || c.email); // Must have contact method
}
```

**Step 3:** Add to dispatcher

```javascript
function scanPageForContacts() {
  // ...
  if (crmId === "mynewcrm") {
    contacts = scanMyNewCrmContacts();
  }
  // ...
}
```

**Step 4:** Test via generic endpoint

- Use `/api/crm/generic/dialsession_from_scan.php`
- No server changes needed (generic endpoint handles all L1/L2)

### Adding L3 Provider (Full API Integration)

**Step 1:** Create provider directory

```bash
mkdir -p server/public/api/crm/mynewcrm
```

**Step 2:** Implement OAuth flow

```php
// oauth_mynewcrm_start.php
$auth_url = "https://mynewcrm.com/oauth/authorize?" . http_build_query([
  'client_id' => cfg('MYNEWCRM_CLIENT_ID'),
  'redirect_uri' => cfg('BASE_URL') . '/api/crm/mynewcrm/oauth_mynewcrm_finish.php',
  'scope' => 'contacts.read',
  'state' => $client_id,
]);
api_ok_flat(['auth_url' => $auth_url]);

// oauth_mynewcrm_finish.php
$code = $_GET['code'] ?? null;
$token_response = http_post('https://mynewcrm.com/oauth/token', [
  'grant_type' => 'authorization_code',
  'code' => $code,
  'client_id' => cfg('MYNEWCRM_CLIENT_ID'),
  'client_secret' => cfg('MYNEWCRM_CLIENT_SECRET'),
]);
atomic_write_json(mynewcrm_token_path($client_id), $token_response);
```

**Step 3:** Implement token refresh

```php
function mynewcrm_refresh_access_token_or_fail($client_id) {
  $token = load_mynewcrm_token($client_id);
  // Refresh logic...
  atomic_write_json(mynewcrm_token_path($client_id), $refreshed);
  return $refreshed['access_token'];
}
```

**Step 4:** Implement dial session creation

```php
// pb_dialsession_selection.php
$access_token = mynewcrm_refresh_access_token_or_fail($client_id);

// Fetch contacts from CRM API
$contacts = mynewcrm_fetch_contacts($object_ids, $access_token);

// Normalize to common format
$normalized = array_map(function($c) {
  return [
    'name' => $c['full_name'],
    'phone' => $c['phone_number'],
    'email' => $c['email_address'],
    'crm_name' => 'mynewcrm',
    'record_url' => $c['url'],
    'crm_identifier' => $c['id'],
  ];
}, $contacts);

// Use shared dial session creation logic
$pb_response = pb_create_dialsession($pat, $normalized, $contactsMap);
// ... return with temp_code
```

**Step 5:** Update extension detection

```javascript
// In background.js → detectCrmFromUrl()
if (host.includes("mynewcrm.example.com")) {
  return { crmId: "mynewcrm", crmName: "My New CRM", level: 3 };
}
```

---

## Deployment Checklist

### Pre-Deployment Security Review

- [ ] No hardcoded secrets in code (use `config.php`)
- [ ] Token storage outside webroot (`/var/lib/...`)
- [ ] CORS whitelist configured (no origin reflection)
- [ ] All endpoints use rate limiting
- [ ] PII redaction enabled in logging
- [ ] Session files use secure permissions (0600) — **TODO: currently 0777**
- [ ] Webhooks have origin validation — **TODO: currently missing**
- [ ] All file operations use `safe_file_path()`

### Server Setup

```bash
# 1. Create secure token directory
sudo mkdir -p /var/lib/pb-extension-dev/tokens/{pb,hubspot}
sudo chown www-data:www-data /var/lib/pb-extension-dev/tokens
sudo chmod 0700 /var/lib/pb-extension-dev/tokens
sudo chmod 0700 /var/lib/pb-extension-dev/tokens/{pb,hubspot}

# 2. Create log directory
sudo mkdir -p /opt/pb-extension-dev/var/log
sudo chown www-data:www-data /opt/pb-extension-dev/var/log
sudo chmod 0775 /opt/pb-extension-dev/var/log

# 3. Copy config
sudo cp /opt/pb-extension-dev/public/config.sample.php \
        /opt/pb-extension-dev/public/config.php
sudo chmod 0600 /opt/pb-extension-dev/public/config.php

# 4. Configure Apache/nginx
# Document root: /opt/pb-extension-dev/public
# Ensure .htaccess or nginx config blocks access to:
#   - config.php (via RewriteRule or location block)
#   - sessions/ directory
#   - cache/ directory
```

### Configuration (config.php)

```php
return [
  'BASE_URL' => 'https://extension-dev.phoneburner.biz',
  'TOKENS_DIR' => '/var/lib/pb-extension-dev/tokens',
  'LOG_FILE' => '/opt/pb-extension-dev/var/log/app.log',

  // PhoneBurner
  'PB_API_BASE' => 'https://www.phoneburner.com/rest/1',
  'PB_WEBHOOK_SECRET' => env('PB_WEBHOOK_SECRET'), // Set in environment

  // HubSpot OAuth
  'HS_CLIENT_ID' => env('HS_CLIENT_ID'),
  'HS_CLIENT_SECRET' => env('HS_CLIENT_SECRET'),
  'HS_SCOPES' => 'crm.objects.contacts.read crm.lists.read crm.objects.deals.read crm.objects.companies.read',

  // CORS (production)
  'PB_CORS_ORIGINS' => [
    'https://extension.phoneburner.biz',
    'https://extension-dev.phoneburner.biz',
  ],
];
```

### Extension Manifest Updates

```json
{
  "version": "0.3.0",
  "host_permissions": ["https://extension-dev.phoneburner.biz/*"]
}
```

Update `BASE_URL` in:

- `chrome-extension/background.js`
- `chrome-extension/popup.js`

### PhoneBurner Webhook Configuration

Configure webhooks in PhoneBurner admin:

- `api_contact_displayed` → `https://extension-dev.phoneburner.biz/webhooks/contact_displayed.php`
- `api_calldone` → `https://extension-dev.phoneburner.biz/webhooks/call_done.php`

### HubSpot OAuth App Configuration

1. Create app at [HubSpot Developer Portal](https://developers.hubspot.com/)
2. Set Redirect URI: `https://extension-dev.phoneburner.biz/api/crm/hubspot/oauth_hs_finish.php`
3. Request scopes: `crm.objects.contacts.read crm.lists.read crm.objects.deals.read crm.objects.companies.read`
4. Copy Client ID + Secret to `config.php`

### Monitoring & Maintenance

**Log Rotation:**

```bash
# /etc/logrotate.d/pb-extension
/opt/pb-extension-dev/var/log/*.log {
    daily
    rotate 90
    compress
    missingok
    notifempty
    create 0664 www-data www-data
}
```

**Stale File Cleanup (cron):**

```bash
# Cleanup old presence files (daily at 3am)
0 3 * * * find /opt/pb-extension-dev/public/metrics/sse_presence -type f -mtime +1 -delete

# Cleanup old rate limit cache (hourly)
0 * * * * find /opt/pb-extension-dev/public/cache/rl_*.txt -type f -mmin +60 -delete

# Cleanup expired temp codes (every 15 min)
*/15 * * * * find /opt/pb-extension-dev/public/cache/temp_code_*.json -type f -mmin +10 -delete
```

---

## Publishing Notes (Chrome Web Store)

### Manifest Requirements

- Description max 132 characters
- Permissions must be justified in review response
- Host permissions must be minimal

### Privacy Policy Requirements

Keep these answers in sync:

1. `chrome-extension/privacy.html`
2. Chrome Web Store privacy declaration
3. In-extension onboarding copy
4. Actual runtime behavior

**Current Privacy Stance:**

- ✅ Collect: CRM contact data (name, phone, email) for dial session creation
- ✅ Store: PhoneBurner PAT, HubSpot OAuth tokens (local only, not transmitted to third parties)
- ❌ Sell or share: No data sold or shared with third parties
- ✅ Data retention: Session data deleted when session ends; logs rotated after 90 days
- ✅ Data transmission: Only to PhoneBurner API and CRM APIs (user-initiated)

### Review Response Template

**Q: Why do you need broad host permissions?**
A: The extension operates on various CRM websites (Salesforce, HubSpot, Zoho, Pipedrive, Monday.com). Permissions are optional and only requested when the user initiates a dial session on a detected CRM page.

**Q: What personal data do you collect?**
A: Contact information (name, phone, email) from CRM lists to create dial sessions. Data is only collected when the user clicks "Launch Dial Session" and is immediately transmitted to PhoneBurner's API. No data is stored permanently or shared with third parties.

**Q: Do you sell or share user data?**
A: No. All data is used exclusively for dial session creation via the PhoneBurner API.

---

## Quick Reference Card

### Most-Used Functions

```php
// Security
safe_file_path($base, $relative)
atomic_write_json($path, $data)
redact_pii_recursive($data)
rate_limit_or_fail($client_id, $maxPerMin)

// Token Management
load_pb_token($client_id)
load_hs_token($client_id)
hs_refresh_access_token_or_fail($client_id)

// Session Management
temp_code_store($session_token, $ttl)
temp_code_retrieve_and_delete($code)
save_session_state($session_token, $state)
load_session_state($session_token)

// API Responses
api_ok($data)
api_ok_flat($data)
api_error($msg, $code, $status)
api_log($event, $fields)

// Input Validation
get_client_id_or_fail($data)
json_input()
```

### Most-Used Extension APIs

```javascript
// Storage
chrome.storage.local.get(['pb_unified_client_id'], callback)
chrome.storage.local.set({ key: value })

// Messaging
chrome.runtime.sendMessage({ type: "...", ... }, callback)
chrome.tabs.sendMessage(tabId, { type: "..." }, callback)

// Scripting
chrome.scripting.executeScript({
  target: { tabId, frameIds: [0] },
  files: ['content.js']
})

// Permissions
chrome.permissions.request({ origins: [...] })
```

### File Paths

```
Server:
  /var/lib/pb-extension-dev/tokens/pb/{client_id}.json
  /var/lib/pb-extension-dev/tokens/hubspot/{client_id}.json
  /opt/pb-extension-dev/public/sessions/{session_token}.json
  /opt/pb-extension-dev/public/user_settings/{member_user_id}.json
  /opt/pb-extension-dev/var/log/app.log

Extension:
  chrome.storage.local['pb_unified_client_id']
  chrome.storage.local['pb_current_session']
```

---

## Questions or Clarifications?

If anything in this guide is unclear or you need expanded rules for:

- Naming conventions
- Linting setup
- Test commands
- CI/CD pipeline
- Additional CRM providers
- Performance optimization

...update this file or create an issue in the repo.

---

**Last Updated:** 2026-01-23
**Version:** 1.0
**Security Status:** See [SECURITY_REVIEW.md](SECURITY_REVIEW.md) for current risk assessment
