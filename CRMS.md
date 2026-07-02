# PhoneBurner CRM Extension — CRM Provider Guide

**What this doc is:** the how-to reference for adding new CRM providers to the extension, and the per-provider gotchas we've learned by adding the ones we already have.

**What this doc is NOT:** a rulebook of invariants. The must-follow contributor rules (never mix L1/L2/L3 in the same provider, disambiguate by `crm_name` not by prefixing IDs, `call_done.php` is a pure dispatcher, etc.) live in [CLAUDE.md](CLAUDE.md). Read this doc when you're actually adding or modifying a provider.

**Companion docs:**
- **[CLAUDE.md](CLAUDE.md)** — invariants and rules
- **[ARCHITECTURE.md](ARCHITECTURE.md)** — the three-level model, provider adapter contract, session lifecycle
- **[SECURITY.md](SECURITY.md)** — token storage, threat model
- **[SERVER_SETUP.md](SERVER_SETUP.md)** — OAuth app registration walkthroughs

---

## Adding a new CRM provider

### Which level should I pick?

| Level | When to use | What's involved | Reference add |
|-------|-------------|-----------------|---------------|
| **L1** | The CRM's list view renders as a plain HTML table or ARIA grid, and the generic scanners in `content.js` (`scanHtmlTableContacts` / `scanAriaGridContacts`) can already extract name/phone/email from it without help. | Register in `crm_config.js` with `level: 1`. That's it — the generic scanners take over. | Zoho, monday.com |
| **L2** | The DOM is custom enough that the generic scanners miss data, but there's no public API worth wiring up (or we're doing a fast v1 before OAuth partnership work). | Register with `level: 2` + write a per-CRM scanner in `content.js` + add it to the dispatcher. Still uses the shared L1/L2 server endpoint (`dialsession_from_scan.php`) — no server-side code. | AgencyZoom, Pipedrive, Salesforce |
| **L3** | The CRM has a real API we can OAuth into and we want the richer feature set (server-side record fetching, call logging, task queues, saved lists). | Everything above + a full provider directory under `/api/crm/{provider}/` + OAuth app registration + tokens + call logger + popup UI card. Substantially more work. | HubSpot, Close, Apollo |

**Start small.** Ship L2 first if you're not sure the L3 investment is worth it — upgrading later is straightforward (Close went L2 → L3, Salesforce is still L2 and may follow). AgencyZoom went from zero to a shipped L2 integration in under an hour by following this exact playbook.

---

### Adding L1 Provider (generic scanning only)

Fastest possible add. If the CRM's list view is a standard `<table>` or ARIA grid with obvious phone/name cells, the generic scanners already handle it.

**Step 1:** Register in `crm_config.js` with `level: 1`:

```javascript
{
  id: "mynewcrm",
  displayName: "My New CRM",
  level: 1,
  hostMatch: "mynewcrm.com",       // String, or array for multiple hosts
}
```

**Step 2:** Reload the extension, open a list page on that CRM, click **Scan & Launch**. If it picks up contacts, you're done with the code — jump to the [customer-facing surfaces checklist](#customer-facing-surfaces-shared-checklist).

**Step 3:** If the generic scan finds nothing useful, upgrade to L2 (below).

---

### Adding L2 Provider (custom DOM scanner)

**Step 1:** Register in `crm_config.js` with `level: 2`:

```javascript
{
  id: "mynewcrm",
  displayName: "My New CRM",
  level: 2,
  hostMatch: "mynewcrm.com",
}
```

**Step 2:** Implement scanner function in `content.js`

```javascript
function scanMyNewCrmContacts(maxContacts) {
  maxContacts = maxContacts || 500;
  var rows = document.querySelectorAll("tr[data-index]");  // Use stable selectors
  if (!rows.length) return [];

  // ... extract name, phone, email, record_url, crm_identifier from each row
  // Return array of contact objects (see existing scanners for pattern)
}
```

**Step 3:** Add to dispatcher in `scanPageForContacts()`

```javascript
} else if (crmId === "mynewcrm") {
  contacts = scanMyNewCrmContacts();
}
```

**Step 4:** Test incrementally — reload the extension after EACH file change.

- The L2 flow uses `/api/crm/generic/dialsession_from_scan.php` on the server — no server-side changes needed.
- If your scanner returns nothing, the generic fallbacks (`scanHtmlTableContacts`, `scanAriaGridContacts`) run automatically after it. Check the extension console for what they found — sometimes the generic scan is close but needs a small tweak from your scanner.

**L2 gotcha to know about:** if the CRM's record URL puts the ID in a query string (`/lead/index?id=123`) rather than the path (`/lead/123`), follow-me navigation still works — `isSameCrmRecord()` in `content.js` compares both origin+path AND query string. This was fixed in v0.8.0 after AgencyZoom's launch (PR #143); if you see follow-me not navigating between contacts, verify that the `record_url` your scanner emits matches what the URL bar actually shows.

**Step 5:** Update customer-facing surfaces (see the [shared checklist](#customer-facing-surfaces-shared-checklist) below).

---

<a id="customer-facing-surfaces-shared-checklist"></a>
### Customer-facing surfaces (shared checklist for every new CRM — L1, L2, or L3)

**Skipping any of these is the #1 missed step.** The code works, but customers can't discover the integration. AgencyZoom shipped in PR #141 without any of these; PR #152 caught the gap after the fact. Issue [#151](https://github.com/jeffosness/pb-extension-dev/issues/151) tracks a future CI check to enforce this automatically.

- **`server/public/index.html`** (marketing site at `https://extension.phoneburner.biz`) — add a `<article class="crm-detail">` block for the new CRM inside the Integration Depth section, add an `<a class="crm-pill">` in the hero, add a `.crm-pill[data-crm="..."] .crm-pill__dot` color rule, update meta descriptions that name-drop CRMs. Fastest way: grep for an existing slug (e.g., `agencyzoom`) to find all four touchpoints and copy the pattern.
- **`chrome-extension/STORE_LISTING.md`** — add the new CRM to the Multi-CRM Compatibility list at the appropriate level.
- **`KB_EXTENSION_TROUBLESHOOTING.md`** — add a numbered section covering: what the extension detects (host + page pattern), how the customer launches, dedup or per-row selection semantics, task-completion behavior (auto vs manual), common issues + resolutions. Also add the CRM name to the Symptom Index at the top.
- **`chrome-extension/changelog.js`** — a short line in the next version entry announcing support.
- **`chrome-extension/manifest.json`** — bump the version. Also review the description (max 132 chars) and add the new CRM if it belongs in the headline list.

---

### Content script safety rules (applies to L1/L2/L3 whenever you touch content.js)

1. **Test one file at a time** — Edit `crm_config.js` first, reload, verify popup loads. Then edit `content.js`, reload, verify again. A broken content script silently kills the entire extension (popup hangs on "Loading...") with no console errors.
2. **Avoid advanced CSS selectors** in `querySelector` — No case-insensitive `i` flag (`[attr*="val" i]`), no `:has()`, no CSS4 features. Stick to basic attribute selectors (`^=`, `*=`, `=`).
3. **Avoid unicode escapes in regex** — Use literal characters or simpler patterns instead of `–` etc.
4. **Use stable DOM selectors** — Avoid hashed/minified class names (e.g., `_hcsbn_134`). Prefer `[data-*]` attributes, `[aria-label]`, `[role]`, semantic HTML tags, and `[href*="pattern"]`.
5. **`node -c content.js`** validates JS syntax but NOT CSS selector validity — bad selectors only fail at runtime in the browser.

**Close.com specifics** (DOM selectors, API endpoints, data model quirks) — see the code for the canonical reference: [`content.js`](chrome-extension/content.js) `scanCloseContacts()` for row/ID extraction, [`close_helpers.php`](server/public/api/crm/close/close_helpers.php) for the API surface. Notable data-model gotcha: Close nests Contacts inside Leads; `name` is a single field (split on first space); `phones` and `emails` are arrays of `{phone|email, type}`.

---

### Adding L3 Provider (Full API Integration)

**Reference implementations:** HubSpot (`/api/crm/hubspot/`), Close (`/api/crm/close/`), Apollo (`/api/crm/apollo/`).

**Rough order of operations:**

- **Phase 0** — Register an OAuth app with the CRM provider so you have Client ID + Client Secret + a callback URL to hand back.
- **Phase 1** — Server-side scaffolding (`utils.php` token functions, provider directory, OAuth endpoints, `state.php`, `pb_dialsession_selection.php`).
- **Phase 2** — Extension-side wiring (`crm_config.js`, `content.js`, `background.js`, `popup.js`, `popup.html`).
- **Phase 3** — Call logging back into the CRM (can be deferred — Close and Apollo both shipped Phase 1+2 first and added Phase 3 later).
- **Phase 4** — Customer-facing surfaces + version bump, using the [shared checklist above](#customer-facing-surfaces-shared-checklist).

Close from cold-start to a shipped L3 integration was one substantial multi-file PR — see [PR #70](https://github.com/jeffosness/pb-extension-dev/pull/70) for the initial file set as a reference (16 files changed).

#### Phase 0: OAuth App Registration (do this first)

Before writing any code, register your OAuth app on the provider's developer portal:

- **Client ID + Client Secret** — you'll paste these into `config.php` in Phase 1.
- **Redirect URI** — must be the exact URL you'll expose from `oauth_{provider}_finish.php`. On dev that's `https://extension-dev.phoneburner.biz/api/crm/{provider}/oauth_{provider}_finish.php`; on prod, swap the host. **The redirect URI must match EXACTLY between your app config and `oauth_{provider}_start.php`** — mismatch causes an opaque OAuth error at the finish step. You'll typically register both dev and prod redirect URIs on the same app.
- **Scopes** — the minimum you need to (a) verify the connection in `state.php` (typically a `me` or `whoami` scope), (b) read contact/lead records, and (c) write call activities/notes for Phase 3. Grep an existing provider's OAuth start for the exact scope strings.

#### Phase 1: Server-Side Foundation

**1a. Token management in `utils.php`** — Add 4 functions following the exact pattern:
```php
function {provider}_token_path(string $client_id): string    // ensure_dir_secure() + path
function save_{provider}_tokens($client_id, array $tokens)   // atomic_write_json + saved_at
function load_{provider}_tokens($client_id)                  // returns array or null
function clear_{provider}_tokens($client_id)                 // unlink file
```

**1b. Create provider directory** with these files:
```
server/public/api/crm/{provider}/
├── {provider}_helpers.php           # API calls, token refresh, contact fetching
├── oauth_{provider}_start.php       # Build auth URL, return via api_ok_flat()
├── oauth_{provider}_finish.php      # Exchange code, save tokens, return HTML page
├── oauth_disconnect.php             # Clear tokens
├── state.php                        # Return {provider}_ready flag
└── pb_dialsession_selection.php     # Fetch contacts, create PB session
```

**1c. Critical patterns for OAuth finish page:**
- Define `PB_BOOTSTRAP_NO_JSON` BEFORE `require_once bootstrap.php`
- Return HTML (not JSON) — it's a browser redirect target
- Use `state` parameter to carry `client_id` through OAuth
- Set security headers: `Cache-Control: no-store`, `X-Frame-Options: DENY`
- Subtract 60s from `expires_at` to refresh tokens early

**1d. Add config keys** to `config.sample.php` and the server's `config.php`:
```php
'{PROVIDER}_CLIENT_ID' => '...',
'{PROVIDER}_CLIENT_SECRET' => '...',
```

**1e. Create tokens directory on server:**
```bash
sudo mkdir -p /var/lib/pb-extension-dev/tokens/{provider}
sudo chown www-data:www-data /var/lib/pb-extension-dev/tokens/{provider}
sudo chmod 0700 /var/lib/pb-extension-dev/tokens/{provider}
```

#### Phase 2: Extension-Side

**2a. `crm_config.js`** — Set `level: 3` (or upgrade from 2→3)

**2b. `content.js`** — Two changes:
- Add `|| crmId === "{provider}"` to the L3 guard in `scanPageForContacts()`
- Add `{PROVIDER}_GET_SELECTED_IDS` message handler to extract record IDs from DOM

**2c. `background.js`** — Add `{PROVIDER}_LAUNCH_FROM_SELECTED` handler:
- Find active CRM tab
- Send `{PROVIDER}_GET_SELECTED_IDS` to content script
- **Track usage** — call `core/track_crm_usage.php` before the dial session API call (fire-and-forget, wrapped in `try/catch`). Include: `crm_id`, `host` + `path` (from `deriveHostPathFromTabUrl()`), `level: 3`, `object_type`, `selected_count`, `launch_source` (`selection` / `list` / `tasks` / `record`). Optional: `portal_id` if applicable, `event_type: "click_to_call"` when logging click-to-call rather than a dial session (defaults to dial-session tracking when omitted). This is how the dashboard tracks CRM usage — without it the provider won't appear in metrics.
- POST IDs to server's `pb_dialsession_selection.php`
- Register follow session + open PB dial window

**2d. `popup.js`** — Add (function naming uses the provider's CamelCase brand form, e.g., `HubSpot`, `Close`, `Apollo`):
- `{PROVIDER}_STATE` global object
- `is{Provider}L3(ctx)` detection function (e.g. `isHubSpotL3`, `isCloseL3`)
- `check{Provider}ConnectionState()` — calls state.php
- `start{Provider}OAuth()` / `disconnect{Provider}()` — OAuth flow
- `launch{Provider}DialSession()` — sends launch message
- `refresh{Provider}DialUi()` — updates button state
- Update `applyContextVisibility()` to show/hide CRM cards
- Add event listeners in DOMContentLoaded

**2e. `popup.html`** — Add dial card and settings card (both `class="hidden"` by default)

#### Phase 3: Call Logging

**Architecture:** `call_done.php` is a universal webhook handler — it MUST NOT contain provider-specific logic. Each L3 provider owns its call logging in a dedicated file:

```
server/public/api/crm/{provider_dir}/{prefix}_call_logger.php
```

(HubSpot's file is `hs_call_logger.php` — file prefix follows the directory's `hs_helpers.php` convention. Close/Apollo use the directory name unchanged: `close_call_logger.php`, `apollo_call_logger.php`.)

The dispatcher in `call_done.php` is a simple switch on `$state['crm_name']` and dispatches to the provider-named log function:
```php
$crmName = $state['crm_name'] ?? '';
if ($crmName === 'close') {
    require_once __DIR__ . '/../api/crm/close/close_call_logger.php';
    close_log_call($state, $payload, $lastCall, $status);
}
if ($crmName === 'hubspot') {
    require_once __DIR__ . '/../api/crm/hubspot/hs_call_logger.php';
    hubspot_log_call($state, $payload, $lastCall, $status);  // NOTE: hubspot_log_call, not hs_log_call
}
```

**Call logger function contract:** Each provider implements a function keyed on the crm_name (not the file prefix):
```php
function {crm_name}_log_call(array $state, array $payload, array $lastCall, string $status): void
// e.g. hubspot_log_call, close_log_call, apollo_log_call
```

Parameters available:
- `$state` — session state with `client_id`, `contacts_map`, `crm_name`
- `$payload` — raw PB webhook payload (contains `contact.external_id`, `call_notes`, `recording_url_public`, `connected`)
- `$lastCall` — parsed call data (`status`, `duration`, `call_id`)
- `$status` — PB disposition text (e.g., "No Answer", "Appointment")

**Critical rules for call logger files:**

1. **Self-contained curl** — use direct curl, not `{provider}_helpers.php`. The webhook doesn't include `bootstrap.php`, so `api_error()` is unavailable. Exception: `cfg()`, `log_msg()`, `load_{provider}_tokens()`, `save_{provider}_tokens()` ARE available via `utils.php`.

2. **Identify the called contact via this three-tier lookup** (PB's `call_done` payload does NOT include `external_crm_data`, unlike `contact_displayed` — so the iteration pattern alone is not enough):

   1. **Iterate any `external_crm` / `external_crm_data` in the payload** (forward-compat — if PB ever starts including it in `call_done`).
   2. **Try `payload.contact.external_id`** as a candidate. Works ONLY if your provider's session-creation code explicitly set `external_id` on each PB contact (e.g., Apollo, Close). Don't add this for HubSpot — see warning below.
   3. **Canonical fallback: fetch the PB contact by `user_id`.** Call `pb_api_call($pat, 'GET', '/contacts/' . $payload['contact']['user_id'])`. The response carries `external_crm_data` on the contact record — **nested under `response.contacts.contacts[0]`, not at the top level**. Walk that, find the `crm_id` that's a key in `state.contacts_map`.

   **Do NOT use `$state['current']`** — the `contact_displayed` webhook for the NEXT contact fires BEFORE `call_done`, so `current` points to the wrong person.

   **For HubSpot specifically: do NOT add `external_id` to PB contacts at session creation as a shortcut.** Many customers run the HubSpot Data Sync app + PB's native HubSpot activity logger + our extension in parallel; setting `external_id` ourselves disturbs PB dedup/merge and risks duplicates and conflicts with those integrations. The user_id fallback recovers the same identity from PB's contact record without touching the contact-creation path.

   See [hs_call_logger.php](server/public/api/crm/hubspot/hs_call_logger.php) for the reference implementation (PR #105). The PB-lookup fallback in [close_call_logger.php](server/public/api/crm/close/close_call_logger.php) was the original pattern but accesses `external_crm_data` at the top level — that's a latent bug; port the nested-shape walk back if Close ever falls through to it.

3. **Handle token refresh inline** for long dial sessions (>1 hour).

4. **Include `call_notes`** from the payload — user-entered notes during the call.

5. **Include `recording_url_public`** from the payload — use the CRM's native recording field if available (e.g., Close has a dedicated `recording_url` field). Upgrade HTTP→HTTPS if needed. **TODO:** Add per-user setting to toggle recording links.

6. **Map PB statuses to CRM outcomes/dispositions dynamically** — use the actual PB status text, match existing CRM outcomes case-insensitively, create if not found. Cache outcome IDs per org to avoid API calls on every webhook.

7. **Never block the webhook** — wrap in try/catch in call_done.php. Always return 200 OK to PhoneBurner.

**CRM-specific notes:**

- **Close HTML:** No `<br>` tags. Always use `<body><p>...</p></body>`.
- **Close dispositions:** Ignored on external calls (`call_method: "external"` always stores `answered`). Use custom Outcomes instead — they work correctly on external calls.
- **Close recording_url:** Must be HTTPS. Only include for answered calls (Close may override disposition if recording present).

#### Phase 4: Customer-Facing Surfaces + Ship

Same [shared checklist](#customer-facing-surfaces-shared-checklist) as L1/L2: marketing site, STORE_LISTING, KB, changelog, manifest version bump.

Also, one L3-specific opportunity: if this add is the **second** consumer of a helper that was previously provider-specific, refactor it out to shared code. Close's initial add moved `pb_call_dialsession()` from `hs_helpers.php` to `utils.php` because it stopped being HubSpot-specific once Close needed it too. Doing this at add-time is cheaper than doing it as tech-debt cleanup later. Common candidates: dial-session payload construction, PB API helpers, token-refresh retry patterns.

#### Lessons Learned from Close L3 Implementation

| Lesson | Details |
|--------|---------|
| **Leads vs Contacts pages** | Close's Leads page links don't include contactId in the URL. Must extract lead IDs and resolve to contact IDs via API (`GET /contact/?lead_id=lead_xxx`). |
| **`pb_call_dialsession()` is shared** | Moved from hs_helpers.php to utils.php. All L3 providers use it. |
| **Content script safety** | Test ONE file at a time. A broken content.js silently kills the entire extension with no console errors. |
| **`hsPost()` is misnamed** | It's a generic server POST helper used by both HubSpot and Close. Consider renaming to `apiPost()` in future. |
| **Rate limit all endpoints** | Every new PHP endpoint needs `rate_limit_or_fail()`. |
| **OAuth app setup** | Redirect URI must match EXACTLY between start.php and the OAuth app config. |
| **Follow-me works automatically** | If `contacts_map` has correct `record_url` values, SSE follow-me navigation works without additional code. |
| **Recording links** | PB `call_done` payload includes `recording_url_public` — include as clickable link in CRM call activities. Plan for a user setting to toggle this per CRM. |
| **Close HTML is strict** | No `<br>`, no multiple root elements without `<body>`. Always use `<body><p>...</p></body>`. Test HTML in the API before assuming standard HTML works. |
| **Close ignores disposition on external calls** | API-created calls (`call_method: "external"`) always show `disposition: answered` regardless of what you send. PUT updates are also ignored. PB call status is preserved in `note_html` as the workaround. |
| **`track_crm_usage.php` in every launch handler** | Every L3 launch handler in `background.js` must call `core/track_crm_usage.php` (fire-and-forget, `try/catch`) before the dial session API call. Without this, the CRM won't appear in the usage dashboard. Close and Apollo missed this initially. |
| **PB's `external_id` is NOT your CRM's ID** | The `payload.contact.external_id` field in PB's `call_done` and `contact_displayed` webhooks is PhoneBurner's OWN internal contact identifier — typically a Salesforce-style ID (e.g., `00Q8c000010sMczEAE`) inherited from PB's long-standing Salesforce sync. PB does NOT round-trip the `crm_id` value you stored in `external_crm_data` via that field. Always use the `external_crm_data` iteration pattern in `contact_displayed.php` (`extract_contact_lookup_key()`) to find the right `contacts_map` entry. HubSpot Task Queue auto-complete bit on this — copied Apollo's simpler `payload.contact.external_id` pattern, which only works because Apollo explicitly sets `external_id` on each PB contact at session creation. |
| **Apollo call logging untested in production** | Apollo's call logger was built but never validated end-to-end because Apollo's OAuth had auth issues that blocked sessions from reaching call_done. Customers ended up needing to generate Apollo API tokens manually. Apollo's logger uses `payload.contact.external_id` (the brittle path) — if/when Apollo auth is fully resolved, the call logger may exhibit the same lookup bug HubSpot just hit. Defensive: port the `external_crm_data` lookup pattern into Apollo's logger too. |

---

## HubSpot-specific reference

<a id="object-types--url-patterns"></a>
### Object Types & URL Patterns

**URL Pattern Detection:**

| Object Type | List URL Pattern | Record URL Pattern | Object Type ID |
|-------------|------------------|--------------------|----------------|
| Contact | `/objects/0-1/` | `/record/0-1/{contactId}` | `0-1` |
| Company | `/objects/0-2/` | `/record/0-2/{companyId}` | `0-2` |
| Deal | `/objects/0-3/` | `/record/0-3/{dealId}` | `0-3` |

The full record URL is `https://app.hubspot.com/contacts/{portalId}/record/0-X/{recordId}` (or `app.{region}.hubspot.com/...` for regional subdomains — the extension supports these via the `hs_host` param, validated against `app(\.[a-z0-9-]+)?\.hubspot\.com` in `pb_dialsession_selection.php`). URL detection lives in `detectCrmFromUrl()` in `background.js` — grep there for the canonical regexes.

**Field Name Differences:**

```javascript
// Contacts
{
  first_name: "John",
  last_name: "Doe",
  email: "john@example.com",
  phone: "555-1234"
}

// Companies
{
  name: "Acme Corp",        // NOT first_name/last_name!
  domain: "acme.com",
  phone: "555-5678",
  city: "San Francisco",
  state: "CA"
}

// Deals
{
  dealname: "Q1 Enterprise Deal",  // NOT name!
  amount: "50000",
  dealstage: "closedwon"
}
```

**Implementation Pattern:**

```php
// In pb_dialsession_selection.php normalization loop
if ($callTarget === 'companies') {
  // Company-specific field mapping
  $name = trim((string)($c['name'] ?? ''));
  $first = $name;  // Use full name as first_name
  $last = '';      // Companies have no last name
  $email = '';     // Optional for companies

  $recordUrl = "https://app.hubspot.com/contacts/{$portalId}/record/0-2/{$companyId}";
} else {
  // Contact-specific field mapping
  $first = trim((string)($c['first_name'] ?? ''));
  $last = trim((string)($c['last_name'] ?? ''));
  $email = trim((string)($c['email'] ?? ''));

  $recordUrl = "https://app.hubspot.com/contacts/{$portalId}/record/0-1/{$contactId}";
}
```

---

<a id="crm-id-uniqueness-across-object-types"></a>
### CRM ID Uniqueness Across Object Types

**Rule (stability contract):** Disambiguate by `crm_name`, not by prefixing the ID. See CLAUDE.md's session-management invariants for the one-line rule; this section is the full context on why and how it evolved.

PhoneBurner's HubSpot integration uses the `crm_name` field in `external_crm_data` to determine the object type when matching records, so a contact and a company that share the same numeric HubSpot ID are kept as separate PhoneBurner records via distinct `crm_name` values.

```php
// In pb_dialsession_selection.php
$externalCrmData[] = [
  'crm_id'   => $hsId,
  'crm_name' => ($callTarget === 'companies') ? 'hubspotcompany' : 'hubspot',
];

// contacts_map key MUST match the crm_id sent to PB so webhook lookups resolve
$externalId = $hsId;

$contacts_map[$externalId] = [
  'crm_identifier' => $externalId,  // matches incoming webhook crm_id
  'record_url'     => $recordUrl,
  // ...
];
```

**Rule for HubSpot object types — one entry per PB record:**

Each PB contact's `external_crm_data` array MUST contain exactly ONE entry: the identity of the entity being dialed. Do NOT add parent company/deal breadcrumbs as additional entries.

| Object being dialed | `crm_id` | `crm_name` |
|--------------------|----------|------------|
| Contact | raw HubSpot ID (`"12345"`) | `"hubspot"` |
| Company (dialed directly) | raw HubSpot ID (`"12345"`) | `"hubspotcompany"` |
| Deal (dialed directly, e.g., click-to-call) | raw HubSpot ID (`"12345"`) | `"hubspotdeal"` |

Note: dial sessions launched *from* a deal or company list resolve to the contacts associated with those parents. Those associated-contact records use `crm_name: "hubspot"` (they're contacts, not the parent entity). Only when you dial the parent entity directly — click-to-call on a deal's phone field, or a company's own phone — do you use `hubspotcompany` / `hubspotdeal`. See `ctcCrmName()` in `background.js` for the click-to-call mapping and `pb_dialsession_selection.php` for the dial-session mapping.

**Why no breadcrumbs:** PB matches records on `(crm_id, crm_name)` against ANY entry in `external_crm_data`, not just the first one. If a contact's PB record carries a `{crm_id: companyId, crm_name: "hubspotcompany"}` breadcrumb pointing to its parent company, then dialing that company directly later would cause PB to match-and-update that contact record — overwriting the contact's name and phone with the company's. Even renaming the breadcrumb to a distinct `crm_name` (e.g., `hubspotrelatedcompany`) avoids the immediate overwrite but still creates a long-term dedup landmine if naming conventions ever shift or if PB later adds matching logic that looks at breadcrumb names. Safest is to keep the array to one entry per PB record and rely on the launch context (which HubSpot object list / sequence the dial came from) for any "related-to-what" reporting we need.

**Historical notes:**
- Prior to 2026-05, company `crm_id` values were prefixed with `"HS Company "` as a defensive measure. PB's HubSpot integration was confirmed by John Congdon (PB team) to disambiguate via `crm_name`, so the prefix was removed.
- After removing the prefix, the same overwrite bug surfaced via the related-company breadcrumb that was attached to contacts dialed from a company list view. We first tried renaming that breadcrumb to `hubspotrelatedcompany` (PR #85), then decided to remove the breadcrumb entirely to eliminate the dedup risk class altogether.

**Critical:** The `contacts_map` key MUST match the `crm_id` sent to PhoneBurner, otherwise webhook matching will fail.

---

### List-Based Dial Sessions (v0.4.0)

Allows users to launch dial sessions from saved HubSpot lists (up to 500 contacts/companies).

**Architecture:**

```
Extension Popup → HS_FETCH_LISTS → hs_lists.php
                                   ↓
                          POST /crm/v3/lists/search (contacts + companies)
                                   ↓
                          Returns top 10 recent lists
                                   ↓
User selects list → HS_LAUNCH_FROM_LIST → pb_dialsession_from_list.php
                                          ↓
                          GET /crm/v3/lists/{listId}/memberships (paginated)
                                          ↓
                          Fetch full records (contacts or companies)
                                          ↓
                          Create PhoneBurner dial session
```

**Key Implementation Details:**

| Endpoint | Purpose | HubSpot API Used |
|----------|---------|------------------|
| `hs_lists.php` | Fetch user's lists | `POST /crm/v3/lists/search` |
| `pb_dialsession_from_list.php` | Create dial session from list | `GET /crm/v3/lists/{listId}/memberships` |

**Important Notes:**

1. **List Size Not Returned**: HubSpot's `/crm/v3/lists/search` endpoint does NOT include member count in the response. The `size` field is typically 0 or missing. Handle this gracefully in the UI:
   ```javascript
   // Only show count if we have it
   if (list.size > 0) {
     opt.textContent = `${list.name} (${list.size} contacts)`;
   } else {
     opt.textContent = `${list.name} (contacts list)`;
   }
   ```

2. **500 Contact Limit**: PhoneBurner dial sessions support max 500 contacts. When fetching list memberships, stop at 500 and include `truncated: true` in response.

3. **Pagination Required**: List memberships are paginated (100 per page). Use `after` cursor for pagination:
   ```php
   while (count($memberIds) < 500) {
     $url = "https://api.hubapi.com/crm/v3/lists/{$listId}/memberships?limit=100";
     if ($after) $url .= "&after=" . rawurlencode($after);
     // ... fetch, check for paging.next.after
   }
   ```

4. **Object Type Matters**: Lists can be for contacts (`0-1`) or companies (`0-2`). Use different normalization:
   - **Contact lists**: Send `object_type: "contacts"`, use contact fields
   - **Company lists**: Send `object_type: "companies"`, use company normalization with `crm_name: "hubspotcompany"` (no ID prefix — disambiguated by `crm_name`)

**Files Involved:**
- `server/public/api/crm/hubspot/hs_lists.php` - Fetches lists
- `server/public/api/crm/hubspot/pb_dialsession_from_list.php` - Creates dial session from list
- `server/public/api/crm/hubspot/hs_helpers.php` - Shared HubSpot API functions
- `chrome-extension/popup.html` - List dropdown UI
- `chrome-extension/popup.js` - List fetching and selection logic
- `chrome-extension/background.js` - `HS_FETCH_LISTS` and `HS_LAUNCH_FROM_LIST` handlers

---

## Cross-cutting patterns learned from adding CRMs

### Testing with Real CRM Data

**Lesson Learned:** Plans often assume ideal data (e.g., "companies have phone numbers"), but production CRMs have messy, incomplete data.

**Testing Checklist:**

- [ ] **Missing required fields** — Test with records missing phone, email, name
- [ ] **Empty strings vs null** — Test with `""` vs `null` vs missing keys
- [ ] **Whitespace** — Test with `"   "` (spaces only)
- [ ] **Special characters** — Test with Unicode, emojis, HTML entities
- [ ] **Edge counts** — Test with 0, 1, 100+ records selected
- [ ] **Mixed validity** — Select 10 records, half valid, half invalid
- [ ] **API rate limits** — Test with large selections to trigger rate limiting
- [ ] **API metadata vs reality** — API may return `size: 0` for lists that actually have members (HubSpot lesson)

**Validation Pattern:**

```php
// Good: Trim before checking
$phone = trim((string)($c['phone'] ?? ''));
if ($phone === '') {
  $skipped++;
  continue;
}

// Also good: Allow phone OR email
if ($phone === '' && $email === '') {
  $skipped++;
  continue;
}
```

**Error Message Pattern:**

```php
// Always report what was skipped and why
if (empty($pbContacts)) {
  api_error('No dialable contacts after normalization', 'bad_request', 400, [
    'skipped' => $skipped,
    'total' => count($hsContacts),
    'reason' => 'missing phone numbers',
  ]);
}
```

---

### Chrome Dropdown Styling Limitations

**Lesson learned during the HubSpot list dropdown work (v0.4.0):** Chrome has **very limited CSS support** for `<select>` and `<option>` elements. Any future CRM feature that uses a `<select>` in the popup will hit this.

**What DOESN'T Work:**
- ❌ CSS gradients on `<option>` elements
- ❌ `!important` flags (mostly ignored)
- ❌ Complex background properties
- ❌ `appearance: none` on select (can break option rendering)
- ❌ CSS variables in option styling (inconsistent)

**What WORKS:**
- ✅ Solid `background-color` on options
- ✅ Direct hex/rgba color values (not CSS variables)
- ✅ `font-weight` for emphasis
- ✅ Simple `:checked`, `:hover` pseudo-classes
- ✅ `title` attribute for tooltips

**Working Pattern (v0.4.0):**

```css
/* Simple, reliable dropdown styling */
select option {
  background-color: #121b2e;  /* Direct hex, not var(--card) */
  color: rgba(255, 255, 255, 0.92);
  padding: 8px;
}
select option:first-child {
  color: rgba(255, 255, 255, 0.55);  /* Muted placeholder */
}
select option:checked {
  background-color: #3e6ff0;  /* Solid color, not gradient */
  color: white;
  font-weight: 600;
}
```

**Truncate Long Names:**

```javascript
// Prevent dropdown overflow with long list names
let displayName = list.name;
if (displayName.length > 35) {
  displayName = displayName.substring(0, 32) + '...';
}
opt.textContent = displayName;
opt.title = list.name;  // Full name on hover
```

**Rule**: When styling dropdowns, use the simplest CSS possible with solid colors and direct values. Test in Chrome immediately.

---

### Shared Helpers Pattern

**Problem**: When multiple endpoints in one provider directory need the same logic, duplicating code leads to bugs and maintenance issues.

**Solution**: Extract shared functions to a `{provider}_helpers.php` file inside the provider directory.

**Example: HubSpot Helper Functions (v0.4.0)**

Before refactoring, `pb_dialsession_selection.php` contained 500+ lines of inline functions for:
- HubSpot API calls
- Token refresh logic
- Phone property discovery
- Contact/company fetching
- PhoneBurner dial session creation

When adding list-based dial sessions, we needed the same logic. Instead of duplicating 500 lines, we:

1. **Created `hs_helpers.php`** with HubSpot-specific shared functions:
   ```php
   // hs_helpers.php
   function hs_refresh_access_token_or_fail(string $client_id, array $hsTokens): array { ... }
   function hs_api_get_json($access_token, $url) { ... }
   function hs_api_post_json($access_token, $url, $body) { ... }
   function hs_discover_phone_properties($access_token, $objectType, $hubId) { ... }
   function hs_fetch_contacts_by_ids($access_token, $contactIds, $phoneProps) { ... }
   function hs_fetch_companies_by_ids($access_token, $companyIds, $phoneProps) { ... }
   ```
   Cross-provider helpers like `pb_call_dialsession()` live in `utils.php` (moved there from hs_helpers.php once Close became the second L3 consumer — see the Lessons Learned table above).

2. **Updated existing endpoint**:
   ```php
   // pb_dialsession_selection.php (before: 750 lines, after: 250 lines)
   require_once __DIR__ . '/hs_helpers.php';
   // ... now uses extracted functions
   ```

3. **New endpoint reuses helpers**:
   ```php
   // pb_dialsession_from_list.php
   require_once __DIR__ . '/hs_helpers.php';
   $hsRecords = hs_fetch_contacts_with_refresh_retry(...);
   $pbResp = pb_call_dialsession($pat, $payload);
   ```

**Benefits:**
- ✅ Zero logic duplication
- ✅ Single source of truth for HubSpot API interactions
- ✅ Easier to maintain (bug fixes apply to both endpoints)
- ✅ Reduced file size (500 lines → shared library)

**Rule**: If you're about to copy/paste more than 50 lines of code, stop and extract to a shared helper file instead.

**Pattern for Future Providers:**
```
server/public/api/crm/{provider}/
  ├── {provider}_helpers.php      # Shared API logic
  ├── oauth_{provider}_start.php  # Uses helpers
  ├── oauth_{provider}_finish.php # Uses helpers
  └── pb_dialsession_*.php        # Uses helpers
```
