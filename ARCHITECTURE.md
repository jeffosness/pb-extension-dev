# PhoneBurner CRM Extension — Architecture

**What this doc is:** a shape-of-the-system reference — components, data flow, provider contract, session lifecycle. Read it when you're new to the codebase or when you need to trace how a request moves through the stack.

**What this doc is NOT:** a rulebook. Contributor invariants (must-use helpers, security checklist, stability contracts, PR / release process) live in [CLAUDE.md](CLAUDE.md).

---

## Components

```
┌──────────────────────────────────────────────────────────────────┐
│ CHROME EXTENSION (MV3)                                           │
│  ├─ manifest.json           Extension manifest, MV3 permissions  │
│  ├─ background.js           Service worker: message router,      │
│  │                           API client, env toggle, CTC gating  │
│  ├─ content.js              Injected into CRM tabs: page         │
│  │                           scraping, SSE follow-me overlay,    │
│  │                           click-to-call pill rendering        │
│  ├─ popup.html + popup.js   Toolbar popup UI (PAT, OAuth,        │
│  │                           dial cards, settings tabs)          │
│  ├─ crm_config.js           Shared CRM registry (id, level,      │
│  │                           hostMatch) — used by both scripts   │
│  ├─ softphone_config.js     Softphone slugs + HMAC settings for  │
│  │                           click-to-call (dev/prod pair)       │
│  ├─ changelog.js            Version-gated welcome/changelog data │
│  └─ icons/                  Toolbar + store icons                │
└──────────────────────────────────────────────────────────────────┘
                              ↓ HTTPS (fetch + credentials)
┌──────────────────────────────────────────────────────────────────┐
│ PHP BACKEND (served from server/public/)                         │
│  ├─ utils.php               Token mgmt, safe file ops, rate     │
│  │                           limiting, temp codes,               │
│  │                           pb_call_dialsession(), log_msg()    │
│  ├─ api/core/                                                    │
│  │  ├─ bootstrap.php         CORS, security headers, api_log,    │
│  │  │                         redact_pii_recursive,              │
│  │  │                         api_ok / api_error                 │
│  │  ├─ oauth_pb_save.php     Save + validate PhoneBurner PAT     │
│  │  ├─ state.php             Connection readiness flags          │
│  │  ├─ user_settings_*.php   Per-user profile settings           │
│  │  ├─ track_crm_usage.php   Dashboard telemetry endpoint        │
│  │  ├─ refresh_sse_code.php  Mint fresh temp codes for SSE       │
│  │  ├─ softphone_auth_code.php  Single-use code → client_id      │
│  │  │                            for click-to-call launch        │
│  │  └─ *_stats.php           Metrics endpoints for dashboards    │
│  ├─ api/crm/generic/                                             │
│  │  └─ dialsession_from_scan.php  L1/L2 scraped-contact intake   │
│  ├─ api/crm/{provider}/       L3 providers (OAuth + full API):   │
│  │                             hubspot/, close/, apollo/         │
│  ├─ webhooks/                 PhoneBurner callbacks:             │
│  │                             contact_displayed.php,            │
│  │                             call_done.php,                    │
│  │                             softphone_call_done.php           │
│  ├─ sse.php                   Real-time follow-me event stream   │
│  ├─ softphone.php +           Embedded generic-softphone host    │
│  │  softphone_host.js          for click-to-call                 │
│  ├─ kb.php                    KB rendered from repo markdown     │
│  ├─ index.html, privacy.html  Marketing site + privacy policy    │
│  ├─ health.php, version.php   Liveness + build info              │
│  ├─ config.php                Secrets, env-specific config       │
│  └─ (state dirs, git-ignored) sessions/, tokens/, cache/,        │
│                                metrics/, daily_stats/,           │
│                                user_settings/                    │
└──────────────────────────────────────────────────────────────────┘
                              ↓ REST APIs
┌──────────────────────────────────────────────────────────────────┐
│ EXTERNAL APIS                                                    │
│  ├─ PhoneBurner API         /rest/1/dialsession, /members/me,    │
│  │                           /contacts, /webhooks                │
│  └─ CRM APIs                HubSpot, Close, Apollo, ...          │
└──────────────────────────────────────────────────────────────────┘
```

The extension is stateless from the backend's perspective — a `client_id` (UUID stored in `chrome.storage.local`) is the only identity tying a browser to its saved tokens.

**The runtime env toggle** — `background.js` reads `DEFAULT_ENV` and a per-user Settings toggle to decide whether to hit the dev backend (`extension-dev.phoneburner.biz`) or prod (`extension.phoneburner.biz`). One extension binary, two backends. See CLAUDE.md's "Feature Gating for Soak-Testing" section for the pattern.

---

## Three-Level CRM Integration Model

| Level  | Method                | Capabilities                                 |
| ------ | --------------------- | -------------------------------------------- |
| **L1** | Generic HTML scraping | Extract from HTML tables / ARIA grids        |
| **L2** | CRM-specific scraping | Custom DOM selectors per CRM                 |
| **L3** | Full API integration  | OAuth + server-side API calls + call logging |

Which CRMs sit at which level lives in [`chrome-extension/crm_config.js`](chrome-extension/crm_config.js) — the single source of truth.

**Why three levels:**

- **L1** is a fast path for CRMs where a generic table-scraper "just works." No CRM-specific code needed; `content.js` finds rows, extracts name/phone/email, ships them to `/api/crm/generic/dialsession_from_scan.php`.
- **L2** is when a CRM's DOM is too custom for the generic scanner (e.g., ARIA grids, nested widgets, non-obvious phone-number layouts). We add a per-CRM scanner in `content.js` but still ship through the generic server endpoint — no server-side changes.
- **L3** is when the CRM has a real API. We take on OAuth, server-side record fetching, and call logging back to the CRM. Users get a much richer experience but the integration is heavier: OAuth app registration, token refresh, per-provider `/api/crm/{provider}/` directory.

**Rule:** Never mix levels. Once a CRM has an L3 provider directory, don't fall back to L1/L2 scraping — it creates two ways to launch a dial session for the same CRM and both diverge over time.

---

## L3 Provider Directory Layout

```
server/public/api/crm/
├── generic/
│   └── dialsession_from_scan.php     # L1/L2 scraped-contact intake
│
├── hubspot/                          # L3
│   ├── oauth_hs_start.php            # OAuth start
│   ├── oauth_hs_finish.php           # OAuth callback (HTML response)
│   ├── oauth_disconnect.php          # Clear tokens
│   ├── state.php                     # Connection status
│   ├── hs_helpers.php                # Shared API client, refresh, etc.
│   ├── hs_call_logger.php            # Writes call activity back to HubSpot
│   ├── hs_lists.php                  # Fetch user's saved lists
│   ├── hs_phone_properties.php       # Discover custom phone fields
│   ├── pb_dialsession_selection.php  # Dial from selected records
│   ├── pb_dialsession_from_list.php  # Dial from a saved list
│   └── pb_dialsession_from_tasks.php # Dial from a task queue
│
├── close/                            # L3 (contacts + leads model)
└── apollo/                           # L3 (sequence-task dialing)
```

The minimum contract every L3 provider must implement is the adapter contract below. HubSpot has grown extra endpoints (lists, tasks, phone-property discovery) as its L3 depth expanded — those are provider-specific extensions, not required. New provider directories should mirror the minimum shape and add their own extensions as needed.

---

## L3 Provider Adapter Contract

Every L3 provider must implement:

```php
// 1. OAuth flow
oauth_{provider}_start.php    // Return auth URL
oauth_{provider}_finish.php   // Exchange code for tokens
oauth_disconnect.php          // Clear tokens

// 2. Token refresh (in {provider}_helpers.php)
{provider}_refresh_access_token_or_fail($client_id)
// → Returns fresh access token or calls api_error()

// 3. Dial session creation
pb_dialsession_selection.php
// → Accept: { object_ids, object_type, portal_id, ... }
// → Return: { session_token, temp_code, launch_url, ... }

// 4. Connection state
state.php
// → Return: { connected: bool, profile: {...} }

// 5. Call logging (in {provider}_call_logger.php)
function {provider}_log_call(array $state, array $payload, array $lastCall, string $status): void
// → Called by webhooks/call_done.php dispatcher when crm_name matches
```

The universal `call_done.php` webhook is a pure dispatcher — it MUST NOT contain provider-specific logic. Each provider owns its own `{provider}_call_logger.php`. See CLAUDE.md's "Adding New CRM Providers → Phase 3: Call Logging" for the rules that apply inside these files (self-contained curl, three-tier contact lookup, disposition mapping, etc.).

---

## Authentication Flows

### PhoneBurner PAT (all users)

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

### L3 OAuth (per-provider, same shape)

```
1. popup.js → POST /api/crm/{provider}/oauth_{provider}_start.php
2. Server returns auth_url with state=client_id
3. User approves at provider
4. Provider redirects to oauth_{provider}_finish.php?code=...&state=...
5. Server exchanges code for refresh_token + access_token
6. Save tokens with expiry: /var/lib/.../tokens/{provider}/{client_id}.json
7. Redirect back to extension UI (HTML page, not JSON)
```

Access tokens are refreshed lazily. `load_{provider}_tokens()` returns the stored token record; callers explicitly check `{provider}_token_is_expired()` and invoke `{provider}_refresh_access_token_or_fail()` when needed. `expires_at` is stamped with a 60-second safety margin at save time (`time() + expires_in - 60`), so a "not expired" reading is always safely non-expired for at least a minute.

---

## Session Lifecycle & Real-Time Streaming

### Session state file

Location: `server/public/sessions/{session_token}.json`

```json
{
  "session_token": "abc123...",
  "dialsession_id": "ds-123456",
  "client_id": "uuid-...",
  "crm_name": "hubspot",
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
    "{crm_id}": {
      "crm_identifier": "{crm_id}",
      "record_url": "https://...",
      "name": "...",
      "phone": "..."
    }
  }
}
```

`contacts_map` keys MUST match the `crm_id` values we sent to PhoneBurner in `external_crm_data`. If they diverge, webhook lookups fail silently and the follow-me overlay stops navigating.

### SSE stream

The follow-me overlay in the CRM tab subscribes to real-time updates via `sse.php`:

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

- SSE uses `text/event-stream` and opts out of the JSON bootstrap wrapper via `PB_BOOTSTRAP_NO_JSON`.
- Heartbeat every 30 seconds so intermediary proxies don't kill idle connections.
- `register_shutdown_function()` cleans up the presence file on disconnect.
- Temp codes are single-use; the extension refreshes by requesting a new code through `background.js` before reconnecting (content scripts can't call the API directly due to CORS on CRM origins).

### Webhook handlers

PhoneBurner calls back to the extension server as calls happen:

| Endpoint | Fired when | Action |
|---|---|---|
| `webhooks/contact_displayed.php` | PB is about to dial the next contact | Look up the contact in `contacts_map`, update `current` in the session file |
| `webhooks/call_done.php` | A call finishes (any disposition) | Update `stats`, update `last_call`, dispatch to `{provider}_call_logger.php` if the session has a `crm_name` |

Both webhooks currently identify the session via `?s=session_token` in the URL. This is trusted because the traffic comes from PhoneBurner's backend, but HMAC signing is a defense-in-depth improvement tracked as future work. The `softphone_call_done.php` webhook (see Click-to-Call below) already implements the HMAC-signed pattern.

---

## Click-to-Call (v0.8.0)

Parallel to the dial-session flow, not part of it. A user clicks a small "flame" pill next to a phone number on a supported CRM record page and a single call is placed via an embedded PhoneBurner generic-softphone in a Chrome popup window — no dial session, no session-state file, no SSE.

```
CRM record page
  ↓ content.js injects pill next to each phone number
  ↓ user clicks →  CLICK_TO_CALL message
  ↓
background.js
  ↓ POST /api/core/softphone_auth_code.php  → single-use code (5-min TTL)
  ↓ opens BASE_URL/softphone.php?code=...&number=...&runtime=... in popup
  ↓
softphone.php
  ↓ exchanges code → client_id → PAT (server-side)
  ↓ embeds PB softphone iframe with ?token=<PAT> in its src
  ↓ softphone_host.js drives dial via postMessage
  ↓
PhoneBurner platform places call
  ↓
POST /webhooks/softphone_call_done.php
  ↓ X-PB-Signature: sha256=<hex hmac_sha256(rawBody, SOFTPHONE_HMAC_SECRET)>
  ↓ v1: verify signature, parse, log non-PII disposition, return 200
```

The v1 webhook handler intentionally stays minimal — it verifies the signature, extracts identity/disposition fields, and logs them non-PII. Forwarding the disposition into the CRM (via each provider's call logger) and relaying to a side-panel UI are follow-up phases; the file header calls this out explicitly.

Security properties that differ from the dial-session flow:

- **Bearer token confinement.** The PAT never leaves the softphone.php document — it's placed directly into the iframe `src` attribute, and the iframe's origin (PB's platform) reads it and drops it before making onward API calls. The extension never sees it, and it isn't in postMessage payloads or the top-window URL.
- **Webhook auth is HMAC, not session-token.** Because there is no session, the webhook can't identify itself with `?s=session_token`. `SOFTPHONE_HMAC_SECRET` (per-environment) is the trust boundary. This pattern is what SECURITY.md points at for retrofitting the dial-session webhooks.
- **Softphone runtime hosting.** The softphone runtime itself is served from PhoneBurner's platform (via a registered slug in `softphone_config.js`) — `softphone.php` on our backend is just the host page that mints the iframe. Dev and prod each register a separate slug.

Per-CRM click-to-call activation is gated in `background.js` via `ctcShouldShowPills()` — an AND of the feature gate `clickToCallEnabled()` and the user-level "Show click-to-call buttons" preference. Both must be true for pills to render.

---

## Extension Context Flow

Understanding how CRM context propagates through the extension is the single biggest gotcha for new contributors — it's the source of most "buttons disappearing" and "wrong buttons for object type" bugs.

```
URL Detection (background.js)
  ↓ detectCrmFromUrl() → { crmId, level, objectType }
  ↓
Content Script Scan (content.js)
  ↓ Scrapes page → { selectedIds, portalId, title }
  ↓
Background Script Cache (background.js)
  ↓ Merges URL + content sources → tabContexts[tabId]
  ↓
Popup UI (popup.js)
  ↓ GET_CONTEXT message → ACTIVE_CTX
  ↓
Button Visibility Logic
  ↓ Shows/hides buttons based on objectType
```

**Key invariant:** URL-based context is authoritative for `objectType`. Content scripts may not have full context, especially on initial page load. When caching context, always merge — never let the content-script payload overwrite `objectType`. The specific rules (never inline `if (CURRENT_ENV === "dev")`, always merge context) live in CLAUDE.md.

---

## Cross-References

- **[CLAUDE.md](CLAUDE.md)** — invariants, security rules, adding-new-CRM checklist, PR/release process
- **[SECURITY.md](SECURITY.md)** — threat model, Security Impact CI triggers
- **[SERVER_SETUP.md](SERVER_SETUP.md)** — provisioning, config.php, webhook + OAuth app registration
- **[PROJECT_MAP.md](PROJECT_MAP.md)** — auto-generated dependency map (functions, endpoints, callers)
- **[KB_EXTENSION_TROUBLESHOOTING.md](KB_EXTENSION_TROUBLESHOOTING.md)** — customer-facing behavior and known issues
