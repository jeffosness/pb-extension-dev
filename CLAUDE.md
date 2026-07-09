# PhoneBurner CRM Extension — AI Assistant & Developer Guide

**Purpose:** Define non-negotiable guardrails, architectural patterns, and security requirements for contributors and AI assistants working in this repository.

**Goal:** Ship safely, avoid duplication, preserve consistent patterns across CRM providers, and maintain the security posture established through recent hardening work.

---

## Companion documents

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — shape of the system: components, three-level CRM model, provider adapter contract, auth flows, session/SSE lifecycle. Read this before you can't picture where a change lands.
- **[CRMS.md](CRMS.md)** — how-to for adding new CRM providers (L1/L2/L3 walkthroughs), plus HubSpot-specific reference (object types, list-based dial sessions, ID-uniqueness narrative) and patterns learned from adding CRMs (testing checklist, dropdown-styling gotcha, shared-helpers refactor pattern).
- **[SHARED_CODE.md](SHARED_CODE.md)** — blast-radius reference: which files and functions are most-depended-on, what breaks if you change them, and how to change them safely. Consult before touching `utils.php`, `bootstrap.php`, or any function called from 15+ sites.
- **[TESTING.md](TESTING.md)** — automated tests: what's covered, how to run locally, how to add tests. CI enforces green tests + a `## Test Impact` declaration on PRs that touch security-critical files.
- **[SECURITY.md](SECURITY.md)** — security model, what we protect against, what we explicitly DON'T, and the files that trigger the **Security Impact CI check**. Read this before touching `utils.php` token functions, OAuth endpoints, call loggers, webhooks, or `sse.php`.
- **[SERVER_SETUP.md](SERVER_SETUP.md)** — end-to-end provisioning runbook for standing up the backend on a fresh host.
- **[LESSONS.md](LESSONS.md)** — append-only log of production incidents and the process changes they drove. Skim before designing a new gate or CI check — chances are a past incident already argues for or against it.
- **[KB_EXTENSION_TROUBLESHOOTING.md](KB_EXTENSION_TROUBLESHOOTING.md)** — customer-facing knowledge base (also surfaced at `https://extension.phoneburner.biz/kb.php`).

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
   - Each L3 provider lives in `/api/crm/{provider}/` (e.g., `/api/crm/hubspot/`)
   - Generic (L1/L2) lives in `/api/crm/generic/`
   - Provider-specific code stays in provider directories
   - Shared code goes in `utils.php`; provider-specific shared code goes in `{provider}_helpers.php`

8. **Minimal diff mentality**
   - Small PRs — one behavior change per PR when possible
   - Prefer refactoring existing code over creating new files
   - If you change a shared utility, update ALL call sites

9. **Always use feature branches for new work**
   - Create `feature/*` branches off `main` for all new features and non-trivial changes
   - Only bug fixes, docs, and config changes go directly to `main`
   - Merge via PR (`gh pr create` + `gh pr merge`) for clean history
   - Never push features directly to `main`

10. **Backward compatibility is required**
   - Server endpoints must work with published extension versions
   - If you must break compatibility, version the endpoint and migrate

---

## Architecture Overview

Component diagram, three-level CRM taxonomy, and full data-flow lives in **[ARCHITECTURE.md](ARCHITECTURE.md)**. Read it if you can't picture where a change lands.

**One invariant to keep here:** Never mix levels. L1/L2 use `/api/crm/generic/`, L3 gets its own provider directory. Once a CRM has an L3 provider directory, don't fall back to L1/L2 scraping for it.

---

## Security First: Non-Negotiable Requirements

See **[SECURITY.md](SECURITY.md)** for the full security model, threat surfaces, and file-level Security Impact CI triggers.

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
- [ ] **If you added a new call site that reads tokens** (`load_pb_token()`, `load_hs_tokens()`, `load_close_tokens()`, `load_apollo_tokens()`), add the endpoint's basename to the matching `$token_read_whitelist` array in `server/public/metrics/crm_usage_dashboard.php`. Missing this fires a false-positive dashboard anomaly the morning after prod deploy — see LESSONS.md 2026-07-09 for the repeat we don't want to hit a third time.
- [ ] **If you touched `utils.php` (or any function tested in `tests/`), PHPUnit is green.** Run `composer test` locally. CI blocks red PRs and requires a `## Test Impact` declaration for changes to security-critical files — see [TESTING.md](TESTING.md).

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

---

## CRM Provider Isolation

Directory layout and the full L3 adapter contract live in **[ARCHITECTURE.md](ARCHITECTURE.md)**. The rules that MUST hold when adding or modifying a provider:

1. **Each L3 provider gets its own directory:** `/api/crm/{provider}/`
2. **Provider-specific code stays in provider directory** — no cross-provider coupling
3. **Shared logic goes in `utils.php`** — normalize to common format before calling PhoneBurner
4. **L1/L2 providers share `/api/crm/generic/`** — detection happens in `content.js`
5. **`webhooks/call_done.php` MUST NOT contain provider-specific logic** — dispatch to `{provider}_call_logger.php`

---

## Critical Utilities & Patterns

### bootstrap.php (API Framework)

Lives at `server/public/api/core/bootstrap.php` and is required by every API endpoint. It sets up CORS, security headers, request-scoped logging, the `api_ok*`/`api_error`/`api_log` helpers, and loads `config.php` for you.

**Include at top of every API endpoint** — the relative path depends on where the endpoint lives:

```php
// server/public/api/core/{anything}.php  — bootstrap is a sibling
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../utils.php';

// server/public/api/crm/{provider}/{anything}.php  — up two, then into core
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../../utils.php';
```

Grep an existing endpoint at your target directory depth and mirror its include paths — don't guess.

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
| `load_hs_tokens($client_id)`           | Load HubSpot OAuth tokens  | **HIGH**       |
| `load_close_tokens($client_id)`        | Load Close OAuth tokens    | **HIGH**       |
| `load_apollo_tokens($client_id)`       | Load Apollo OAuth tokens   | **HIGH**       |
| `pb_call_dialsession($pat, $payload)`  | Create PB dial session     | **HIGH**       |

**Token Migration Logic:**

```php
// Automatically migrates tokens from old location (inside webroot)
// to new location (outside webroot) on first load
migrate_token_file_if_needed($legacyPath, $newPath);
```

---

## Authentication & Token Management

PAT flow, per-provider OAuth flow, and lazy-refresh behavior are diagrammed in **[ARCHITECTURE.md](ARCHITECTURE.md)**. The rules that MUST hold in code:

### Session Token Security

**NEVER expose session tokens directly in URLs.** Wrap them in a single-use, 5-minute temp code:

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

Exception: webhooks from PhoneBurner use `?s=session_token` because the backend is trusted and webhooks fire multiple times over the lifetime of a session (temp codes are single-use).

### Token Storage

PATs and OAuth tokens live outside the webroot in `TOKENS_DIR` (see `config.php`) with 0600 file / 0700 dir permissions. Always write via `atomic_write_json(pb_token_path($client_id), ...)` — never `file_put_contents`.

---

## Session Management & Real-Time Streaming

Session state schema, SSE connection lifecycle, and webhook handler responsibilities are all in **[ARCHITECTURE.md](ARCHITECTURE.md)**. The invariants that MUST hold when touching this code:

- **`contacts_map` keys MUST match the `crm_id` values sent to PhoneBurner in `external_crm_data`.** If they diverge, webhook lookups fail silently and the follow-me overlay stops navigating. See CLAUDE.md's "CRM ID Uniqueness Across Object Types" section for the disambiguation rules.
- **`webhooks/call_done.php` is a pure dispatcher.** No provider-specific logic in the file itself — dispatch to `{provider}_call_logger.php` based on `state.crm_name`.
- **Session files are group-readable (0660), not owner-only (0600).** Mismatch with the token-file standard. Tracked in SECURITY.md. New code that writes session state should use `atomic_write_json()` (which enforces 0600) instead of the legacy `save_session_state()`.
- **Webhook payload shape is a stability contract.** Extension code depends on the field names PhoneBurner sends; changing how we parse them breaks active sessions across all deployed extensions. Test the full webhook path end-to-end before merging.

---

## Chrome Extension Development Patterns

### Context Management & State Flow

Context flow diagram (URL detection → content script scan → background cache → popup) is in **[ARCHITECTURE.md](ARCHITECTURE.md)**. The invariants that MUST hold when touching context code:

1. **URL-based context is authoritative for `objectType`.** Content scripts may not have full context, especially on initial page load. Always detect from URL patterns (e.g., `/objects/0-2/` = companies).

2. **Context caching must merge, not replace:**
   ```javascript
   // ❌ WRONG: Replaces context, loses objectType
   tabContexts[tabId] = contentScriptContext;

   // ✅ CORRECT: Merges, preserves objectType from URL
   const urlContext = detectCrmFromUrl(tab.url);
   tabContexts[tabId] = {
     ...contentScriptContext,
     objectType: urlContext.objectType  // Always use URL-detected type
   };
   ```

3. **Adding new context fields? Update ALL sources:** URL detection in `background.js:detectCrmFromUrl()`, content script message handlers, `tabContexts` cache, popup `ACTIVE_CTX`. Miss one and the field silently defaults to `undefined` in whichever path skipped it.

### HubSpot Object Types & URL Patterns

The URL detection table, field-name differences between contacts/companies/deals, and the object-type normalization pattern in `pb_dialsession_selection.php` are all in **[CRMS.md](CRMS.md#object-types--url-patterns)**. Read there before touching HubSpot object-type code.

### Error Response Handling

**Problem:** Server APIs return structured error objects, but JavaScript tries to display them as strings, resulting in `"[object Object]"` messages.

**Server Response Format:**

```javascript
// Success
{ ok: true, data: {...} }

// Error
{
  ok: false,
  error: {
    code: "bad_request",
    message: "No dialable contacts after normalization",
    skipped: 2,           // Optional: additional details
    hs_contacts: 2        // Optional: context
  }
}
```

**Extension Pattern (popup.js):**

```javascript
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
    // Add contextual details if present
    if (resp.error.skipped > 0) {
      msg += ` (${resp.error.skipped} records skipped)`;
    }
    return msg;
  }

  return fallback;
}

// Usage
const resp = await sendToBackground(message);
if (!resp || !resp.ok) {
  const errorMsg = getErrorMessage(resp, "Operation failed");
  await showAlert(errorMsg);
  return;
}
```

**Rule:** ALWAYS use `getErrorMessage()` when displaying API errors. Never use `resp.error` directly in UI.

### Dial Session Launch Loading State

**Rule:** ALL dial session launch functions MUST show a loading animation while the session is being built. This applies to every CRM provider and every launch method (selection, list, sequence tasks, etc.).

**Required pattern:**

```javascript
// 1. Disable button + show loading state BEFORE the API call
if (btn) btn.disabled = true;
if (status) {
  status.textContent = "Building dial session from selected contacts…";
  status.classList.add("loading");    // triggers CSS animation
}

// 2. Make the API call
const resp = await sendToBackground({ type: "LAUNCH_MESSAGE" });

// 3. Remove loading animation AFTER response (before checking result)
if (status) status.classList.remove("loading");

// 4. Handle error — show message in status (don't clear to empty)
if (!resp || !resp.ok) {
  const errorMsg = getErrorMessage(resp, "Failed to launch dial session.");
  if (status) status.textContent = errorMsg;
  if (btn) btn.disabled = false;
  await showAlert(errorMsg);
  return;
}

// 5. Success
if (status) status.textContent = "Dial session launched!";
window.close();
```

**Key points:**
- `classList.add("loading")` adds a CSS pulse/spinner animation
- Contextual message should describe what's happening ("Building dial session from 3 contacts…")
- On error, show the error in the status element (don't clear to empty string)
- `classList.remove("loading")` MUST be called before any return path

### CRM ID Uniqueness Across Object Types

Rule: **disambiguate by `crm_name`, not by prefixing the ID.** PhoneBurner matches `(crm_id, crm_name)` pairs, so a contact and a company with the same numeric HubSpot ID stay as separate PB records via distinct `crm_name` values (`hubspot` / `hubspotcompany` / `hubspotdeal`).

Full narrative, historical context (the removed `"HS Company "` prefix, the breadcrumb overwrite bug), and the "one entry per PB record" rule are in **[CRMS.md](CRMS.md#crm-id-uniqueness-across-object-types)**.

**Also critical:** the `contacts_map` key MUST match the `crm_id` sent to PhoneBurner, otherwise webhook matching fails silently.

### Debugging Extension Context Issues

**Common Symptoms:**

- Buttons show initially but disappear after navigation
- Wrong buttons appear for object type (e.g., single button on company pages)
- Context shows `undefined` or stale values

**Debug Steps:**

1. **Check URL detection:**
   ```javascript
   // In background.js console
   detectCrmFromUrl("https://app.hubspot.com/contacts/123/objects/0-2/456")
   // Should return: { crmId: "hubspot", level: 3, objectType: "company" }
   ```

2. **Check cached context:**
   ```javascript
   // In background.js console
   console.log(tabContexts);
   // Should show current tab's context with objectType
   ```

3. **Check popup context:**
   ```javascript
   // In popup.js console (right-click the popup → Inspect)
   console.log(ACTIVE_CTX);
   // Should match background context
   ```

4. **Force refresh:**
   ```javascript
   // In popup.js console
   chrome.runtime.sendMessage({ type: "GET_CONTEXT" }, (resp) => {
     console.log("Fresh context:", resp.context);
   });
   ```

**Fix Pattern:**

If context is stale, always re-detect from current URL:

```javascript
// In background.js GET_CONTEXT handler
const urlContext = detectCrmFromUrl(tab?.url || "");

// Merge cached context with fresh URL detection
const mergedContext = {
  ...tabContexts[tabId],
  objectType: urlContext.objectType,  // Always fresh from URL
  crmId: urlContext.crmId,
  level: urlContext.level
};

sendResponse({ context: mergedContext });
```

### Feature Gating for Soak-Testing New Work

**The pattern:** When shipping a new feature that we want to test in the real extension-plus-server flow — but not yet expose to customers — gate it on `CURRENT_ENV === "dev"`. The customer default is `CURRENT_ENV === "prod"`, so gated features are invisible to them until we're ready.

**Why this exists:** Chrome Web Store distributes ONE binary to everyone. There's no beta channel, no per-user targeting, no way to ship code to some customers and not others. But every version bump requires a full CWS release. So the only lever we have between "in development" and "customers see it" is what the code CHOOSES to do based on runtime state.

**How to use it:**

```javascript
// background.js — one named check per feature so future contributors can grep for the gate
function myNewFeatureEnabled() {
  return CURRENT_ENV === "dev";
}

// Anywhere the feature activates
if (!myNewFeatureEnabled()) return;   // no-op for customers
// ... rest of feature code
```

**Development flow:**

1. Add the gate with `return CURRENT_ENV === "dev";` at the top of a new feature.
2. Land the feature on `main` — it auto-deploys to dev backend, is live for anyone toggled to dev in Settings → Developer Options.
3. Soak-test locally: unpacked extension + dev toggle. Everything works end-to-end because dev backend has all the code too.
4. When it's ready for customers, change the gate to `return true;`. Bump manifest, add changelog, ship.

**Live example:** [click-to-call in v0.8.0](https://github.com/jeffosness/pb-extension-dev/pull/146). The gate lived at `background.js:clickToCallEnabled()` for about a week while we tested the softphone popup, mic permission flow, PhoneBurner webhook signing, and per-CRM finder DOMs. Real bugs we caught during that soak period would have shipped to every customer otherwise. When we flipped the gate to `return true` in PR #146, the whole tree of work was already exercised.

**Important rules:**

- **Every gated feature keeps its named function.** Don't inline `if (CURRENT_ENV === "dev")` scattered across the code — always route through a single `xxxEnabled()` function so future contributors can find every gate point with one grep.
- **Comment WHY the gate exists** (e.g., "TO LAUNCH: change this to return true once softphone.php is deployed to prod"). Prevents future contributors from thinking the gate is old scaffolding they should remove.
- **Flip the gate BEFORE bumping the manifest.** If manifest goes from 0.8.1 to 0.9.0 with a "click-to-call now available!" changelog entry, but the gate still returns `CURRENT_ENV === "dev"`, customers will see the changelog and no feature. Support tickets follow. This class of mistake is worth a future CI check ([#144](https://github.com/jeffosness/pb-extension-dev/issues/144) tracks a related one for DEFAULT_ENV consistency; a similar check could catch open gates in production releases).
- **User-level toggles are a separate concern.** If a launched feature needs an off-switch for individual customers (e.g., click-to-call's "Show click-to-call buttons" checkbox in Settings), that's a per-user `chrome.storage.local` flag, layered ON TOP of the env gate — not a replacement for it. See `ctcShouldShowPills()` in background.js for the pattern (both gates checked together).

**What NOT to do:** Don't build a full "beta program" with customer opt-in flags to soak-test features on willing customers. We considered it (2026-07-02). The extra CWS release cadence + toggle infrastructure isn't worth it while dev-toggle-plus-internal-testing keeps catching real bugs. If a specific future feature genuinely can't be validated without diverse customer CRM configurations we don't have access to, we'll revisit. Until then, the env-gate pattern above IS our soak strategy.

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
   - Test both L1/L2 (generic scan) and L3 (OAuth API) flows
   - Test a representative CRM at each level — for L2, pick one from `crm_config.js` with `level: 2` (currently Salesforce, Pipedrive, AgencyZoom). For L3, at minimum test HubSpot + Close since they're the highest-usage L3 providers.
   - Verify existing sessions still work

---

## Testing & Debugging

### Automated tests

**Rule: if you touched `utils.php` or a function in [SHARED_CODE.md](SHARED_CODE.md), run `composer test` locally before pushing, and add tests for any new shared function in the same PR.** CI (`.github/workflows/tests.yml`) blocks red PRs, and (`.github/workflows/test-impact-check.yml`) blocks PRs that touched security-critical/tested files without declaring a `## Test Impact` in the PR body.

See **[TESTING.md](TESTING.md)** for what's covered today, how to run locally, how to add new tests, and the follow-up test targets (redact_pii_recursive, rate_limit_or_fail, get_client_id_or_fail).

### Local Development Setup

```bash
# 1. Start PHP dev server
cd server/public
php -S 127.0.0.1:8000

# 2. Copy config
cp config.sample.php config.php
# Edit config.php with your credentials

# 3. Create secure token directories (one per L3 provider you're testing)
mkdir -p /var/lib/pb-extension-dev/tokens/{pb,hubspot,close,apollo}
chmod 0700 /var/lib/pb-extension-dev/tokens
chmod 0700 /var/lib/pb-extension-dev/tokens/{pb,hubspot,close,apollo}

# 4. Load extension in Chrome
# chrome://extensions → Developer Mode → Load Unpacked
# Select chrome-extension/ directory

# 5. Point extension at your local server
# Open the popup → Settings → Developer Options → toggle to "dev"
# The dev env resolves to the URL set in background.js (currently
# https://extension-dev.phoneburner.biz). To point at a fully local
# stack, temporarily edit BASE_URLS.dev in background.js.
```

### Debug Endpoints

```bash
# Health check (includes version + env)
curl http://127.0.0.1:8000/health.php

# Version info (JSON — note the ".json.php" — version.php itself is a
# PHP-return array meant to be require()'d, not HTTP-fetched directly)
curl http://127.0.0.1:8000/version.json.php

# Test API endpoint
curl -X POST http://127.0.0.1:8000/api/core/state.php \
  -H "Content-Type: application/json" \
  -d '{"client_id":"test-client"}'
```

### Browser Console Debugging

Run these from the popup's DevTools (right-click the popup → Inspect — F12 doesn't work on Chrome extension popups) or from the background service worker's DevTools (chrome://extensions → Inspect views: service worker). `chrome.runtime.sendMessage` targets background handlers; the popup and background share the extension origin.

```javascript
// Check CRM context (asks background, which merges cached tab context + URL detection)
chrome.runtime.sendMessage({ type: "GET_CONTEXT" }, console.log);

// Get session status for the active tab
chrome.runtime.sendMessage({ type: "GET_ACTIVE_SESSION_FOR_TAB" }, console.log);

// Get stored client ID
chrome.storage.local.get(["pb_unified_client_id"], console.log);

// Emergency reset (clears all extension state — tokens still live server-side)
chrome.runtime.sendMessage({ type: "FORCE_RESET_ALL_STATE" }, console.log);
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

Message types flowing between `background.js`, `popup.js`, and `content.js` (e.g., `GET_CONTEXT`, `SAVE_PAT`, `HS_LAUNCH_FROM_SELECTED`, `CLICK_TO_CALL`, `REFRESH_SSE_CODE`, ...) are a stability contract — the full set drifts with every feature, so grep `msg.type ===` in [`chrome-extension/background.js`](chrome-extension/background.js) for the canonical list.

**Rule:** Changing a message type name — or the shape of its payload — requires updating every sender and every handler in lockstep. Renaming without a compatibility shim will break in-flight dial sessions on customers who haven't reloaded the extension.

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

The full how-to lives in **[CRMS.md](CRMS.md)** — level-picker (L1/L2/L3), step-by-step walkthroughs for each, shared customer-facing checklist, L3 phase 0-4, and the Lessons Learned table from the Close L3 implementation.

The **invariants** that MUST hold when adding a provider (kept here because they are rules, not walkthroughs):

- **Never mix levels.** Once a CRM has an L3 provider directory, do not fall back to L1/L2 scraping for it.
- **Provider isolation.** Provider-specific code stays in `/api/crm/{provider}/`. Cross-provider helpers go in `utils.php`. Provider-specific shared code goes in `{provider}_helpers.php`.
- **`webhooks/call_done.php` is a pure dispatcher.** No provider-specific logic — dispatch to `{provider}_call_logger.php` based on `state.crm_name`.
- **Disambiguate by `crm_name`, not by prefixing the ID.** PhoneBurner matches `(crm_id, crm_name)` pairs, so a contact and a company with the same numeric HubSpot ID stay as separate PB records via distinct `crm_name` values (`hubspot` / `hubspotcompany` / `hubspotdeal`). The full history + rationale is in CRMS.md.
- **`contacts_map` key MUST match `crm_id`.** If they diverge, webhook lookups fail silently and the follow-me overlay stops navigating.

---

## Deployment Checklist

### Risk-tier gates (automatic)

Every PR gets classified by CI into `risk:tier-0`, `risk:tier-1`, or `risk:tier-2` based on the files it touches. See [`.github/workflows/risk-tier-check.yml`](.github/workflows/risk-tier-check.yml) for the exact file patterns, and the [PR template](.github/pull_request_template.md) for what each tier requires.

- **Tier 0** (docs, dashboard, tests, tooling, marketing, changelog): ships freely. Only the KB Impact check applies.
- **Tier 1** (extension code, popup UI, most api/ endpoints, softphone.php): requires a filled-in "Adversarial Review" section in the PR body. CI enforces this.
- **Tier 2** (security-critical: `utils.php`, `bootstrap.php`, webhooks, `sse.php`, `config.sample.php`, OAuth endpoints, `*_call_logger.php`, `SECURITY.md`): requires Adversarial Review and a Security Impact declaration (see SECURITY.md). The 4-hour cool-off is enforced at **production deploy time** (`prod-*` tag push), NOT at PR merge. Merging to main auto-deploys to dev for soak-testing — that's when the cool-off clock starts. Cut the prod tag once the freshest Tier-2 commit has been on dev for 4 hours. Emergency override: cut the prod tag with a `-hotfix`, `-urgent`, or `-rollback` suffix (e.g. `prod-v0.8.2-hotfix`).

**Post-Deploy Verification** is required in the PR body for anything Tier 1+ that changes production behavior — write the specific checks you'll perform within 24h of the prod tag. Not CI-enforced, but a written-down habit so nothing is skipped and future contributors know what "confirming a deploy worked" looks like.

**LESSONS.md** is our incident log. When something ships wrong (a payload-shape guess, a false-positive anomaly, a missed customer-facing surface), add an entry there so the failure mode isn't trapped in one person's head.

### Pre-PR Checklist (KB Impact — required on every PR)

**Every** PR must declare its KB impact. CI (`.github/workflows/kb-impact-check.yml`) enforces this against the `## KB Impact` section of the PR body — PRs cannot merge without a checked box.

Before opening any PR, ask: **would a customer or support rep notice this change?**

- **No customer-visible change** → check the "No customer-visible change" box. Examples: refactors, build/CI tweaks, internal docs, server log format changes, etc.
- **Customer-visible change** → update `KB_EXTENSION_TROUBLESHOOTING.md` in the same PR (preferred), OR open a follow-up issue/PR and link it in the checkbox.

Customer-visible doesn't just mean UI:
- New CRM provider, new launch path, new setting, renamed button, new error message.
- Behavior changes in dial session creation, call logging, OAuth flows, follow-me.
- **Server-only changes that affect what shows up in the customer's CRM or PhoneBurner records** — e.g., PR #86 silently changed `external_crm_data` and would have warranted a KB note even though no extension code changed.
- New permissions, new domains, changes to data handling or privacy.

If unsure, treat it as customer-visible.

**The KB renders publicly at:**
- `https://extension.phoneburner.biz/kb.php` — HTML (humans)
- `https://extension.phoneburner.biz/kb.php?format=md` — raw markdown (PB's AI agent)

Both read directly from `KB_EXTENSION_TROUBLESHOOTING.md` in the repo, so merging to main auto-publishes via the existing deploy pipeline.

### Pre-PR Checklist (Version & Changelog)

When creating a PR that includes **user-facing changes** (new features, UI changes, new settings):

- [ ] Bump version in `chrome-extension/manifest.json` (semver: major.minor.patch)
- [ ] Add entry to `chrome-extension/changelog.js` under `PB_CHANGELOG` keyed by the new version
- [ ] Changelog items should be short, user-friendly descriptions (not technical details)
- [ ] Only add changelog entries for features users interact with — skip for bug fixes, refactors, or internal changes
- [ ] `PB_WELCOME` in `changelog.js` only needs updating if the onboarding flow or value proposition changes
- [ ] Review `manifest.json` description (max 132 chars) — update if supported CRMs or headline features changed
- [ ] Review `chrome-extension/STORE_LISTING.md` — update if the new feature warrants a listing change (new CRM, major capability, security model change). Not every release needs a listing update, but always consider it.

**How it works:** On popup open, `checkChangelog()` compares the current manifest version against `pb_last_seen_version` in `chrome.storage.local`. First install shows the welcome modal. Version upgrades show the latest changelog entry. Same version = no modal.

**Web Store zip archive:** After bumping the version, create a zip of the `chrome-extension/` directory and save it to the webstore resources folder as `version X.Y.Z.zip`. Easiest: run `scripts/build-webstore-zip.ps1` — it reads the version from `manifest.json`, stages only the runtime files, and writes to the default output folder. On Jeff's primary machine that's `D:\Camtasia Studio\Phone Burner\webstore resources\`. Pass `-OutputDir <path>` to override. **When adding a new top-level file under `chrome-extension/`, also add it to `$includeNames` in the script** — the safety-net warning fires if you forget, and PR #149 fixed the parse bug that used to hide that warning.

### Pre-Deployment Security Review

- [ ] No hardcoded secrets in code (use `config.php`)
- [ ] Token storage outside webroot (`/var/lib/...`)
- [ ] CORS whitelist configured (no origin reflection)
- [ ] All endpoints use rate limiting
- [ ] PII redaction enabled in logging
- [ ] Session files use owner-only permissions (0600) — **TODO: currently 0660 (group-readable)**
- [ ] Webhooks have origin validation — **PARTIAL: `softphone_call_done.php` HMAC-signed; `call_done.php` + `contact_displayed.php` still trust `?s=session_token`**
- [ ] All file operations use `safe_file_path()`

### Server Setup

See **[SERVER_SETUP.md](SERVER_SETUP.md)** — end-to-end provisioning runbook (token directories, log dir, Apache/nginx config, systemd, config.php bootstrap, cron cleanup, monitoring, webhook + OAuth app registration).

### Server Config File Management

**`config.php` is protected from accidental edits** on the production server using Linux's immutable file attribute (`chattr +i`). This prevents anyone — including root — from modifying or deleting the file until the flag is removed.

**To edit `config.php` on the server:**

```bash
# 1. Unlock the file (remove immutable flag)
sudo chattr -i /opt/pb-extension-dev/server/public/config.php

# 2. Edit the file
sudo nano /opt/pb-extension-dev/server/public/config.php

# 3. Lock it again (restore immutable flag)
sudo chattr +i /opt/pb-extension-dev/server/public/config.php
```

**To check if the file is locked:**

```bash
lsattr /opt/pb-extension-dev/server/public/config.php
# If locked, you'll see 'i' in the attribute list (e.g., ----i-----------)
# If unlocked, dashes only (e.g., -------------------)
```

**DEBUG_MODE:** To enable debug endpoints (`scan_debug.php`, `scan_debug_view.php`, `_debug_get.php`), unlock config.php, set `'DEBUG_MODE' => true`, then lock it again when done. These endpoints return 404 when DEBUG_MODE is false or absent.

### Configuration, Webhooks, OAuth Apps, Cron, Log Rotation

All operational configuration lives in **[SERVER_SETUP.md](SERVER_SETUP.md)** — including the `config.php` shape (see also `server/public/config.sample.php`), PhoneBurner webhook registration, HubSpot/Close/Apollo OAuth app setup, logrotate config, and stale-file cleanup cron entries.

Extension `BASE_URL` is no longer hardcoded — the extension toggles between dev and prod at runtime via `DEFAULT_ENV` in `chrome-extension/background.js` (see the **Feature Gating for Soak-Testing** section above for the runtime env toggle pattern).

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
- ✅ Store server-side: PhoneBurner PAT + OAuth tokens for every connected L3 CRM (currently HubSpot, Close, Apollo — see `server/public/tokens/` for the live set). Tokens stored outside webroot with 0600 permissions; not transmitted to third parties.
- ❌ Sell or share: No data sold or shared with third parties
- ✅ Data retention: Session data deleted when session ends; logs rotated after 90 days
- ✅ Data transmission: Only to PhoneBurner API and CRM APIs (user-initiated)

### Review Response Template

**Q: Why do you need broad host permissions?**
A: The extension operates on a variety of CRM websites — the current supported list is defined in `chrome-extension/crm_config.js` (HubSpot, Salesforce, Zoho, monday.com, Pipedrive, Close, Apollo, AgencyZoom at time of writing). Host permissions are requested per-CRM only when the user activates the integration; they are not granted at install time.

**Q: What personal data do you collect?**
A: Contact information (name, phone, email) from CRM lists to create dial sessions. Data is only collected when the user clicks "Launch Dial Session" and is immediately transmitted to PhoneBurner's API. No data is stored permanently or shared with third parties.

**Q: Do you sell or share user data?**
A: No. All data is used exclusively for dial session creation via the PhoneBurner API.

---

## When this guide is missing something

If a rule you need isn't captured here — new CRM pattern, unfamiliar edge case, ambiguous policy — either **update this file in the same PR** (preferred) or open an issue so it doesn't get lost. The goal is that a new contributor (human or AI) can trust this file to reflect current invariants without cross-referencing outdated tribal knowledge.

Companion docs cover the depth: **[SECURITY.md](SECURITY.md)** for the threat model, **[SERVER_SETUP.md](SERVER_SETUP.md)** for provisioning, **[SHARED_CODE.md](SHARED_CODE.md)** for blast-radius before touching shared utilities, **[TESTING.md](TESTING.md)** for automated tests, **[KB_EXTENSION_TROUBLESHOOTING.md](KB_EXTENSION_TROUBLESHOOTING.md)** for customer-facing behavior.
