# Web Store Listing Details (Chrome & Edge)

> **Purpose:** Single source of truth for the public listing copy AND the compliance/review form responses required by both stores.
>
> - **Chrome Web Store:** https://chromewebstore.google.com/detail/phoneburner-dial-session/hcnjaaplagkloccolpgibokkdcnmhncj
> - **Microsoft Edge Add-ons:** https://microsoftedge.microsoft.com/addons/detail/phoneburner-dial-session-/mdkihhcphnngfcldfbkpjmifnnhinian
>
> Review and update this file whenever bumping the version in `manifest.json` or whenever either store changes its compliance form.
>
> **Short description** lives in `manifest.json` → `description` field (max 132 chars). The full marketing description below is identical for both stores.

---

## Full Description

📞 PhoneBurner Dial Session Companion
Launch PhoneBurner dial sessions directly from your CRM and follow calls in real time — no manual exports, no tab juggling, no hassle.

The PhoneBurner Dial Session Companion bridges your CRM workflow with PhoneBurner's power dialer, making it faster and easier to start dialing and stay focused on the right record during every call.

🚀 Key Features

🔹 Launch Dial Sessions from Your CRM
Three ways to start dialing:

• Select & Launch: Choose records from any CRM list view and launch instantly
• List-Based Launch (HubSpot): Pick a saved HubSpot list from a dropdown and dial up to 500 contacts or companies
• Task-Based Launch: Dial straight from your HubSpot Task Queue or your AgencyZoom task list — turn your task queue into a power-dialing session

✅ Automatically sends contacts to PhoneBurner
✅ Eliminates manual CSV exports and imports
✅ Works with contacts, companies, and deals

🔹 Click-to-Call — Single Calls Without a Full Dial Session (HubSpot)
A small PhoneBurner flame icon appears next to phone numbers on HubSpot contact records, company records, list views, and your tasks list. Click it to place a single call in seconds — perfect for one-off follow-ups when you don't need a whole dial session.

• Every phone field is dialable — Phone Number, Mobile, Home Phone, and any custom phone properties
• Task rows auto-complete — click the flame on a task, disposition the call, and HubSpot marks the task done automatically
• Icon-only design stays out of your way; hover for context
• Toggle it off in Settings if you already use another dialer's click-to-call

🔹 Real-Time "Follow" Widget
Stay in sync with your dialer:

• Displays the current call and live statistics
• Automatically navigates to the active CRM record being called
• Compact, modern design with expand/minimize controls
• Optional auto-collapse behavior (user configurable)

🔹 Advanced HubSpot Support (Level 3)
Full API integration with HubSpot:

• Launch from selected records (contacts, companies, or deals)
• Launch from saved HubSpot lists (up to 500 records per session)
• Launch from the HubSpot Task Queue — dial through the contacts associated with your visible tasks, and have those tasks auto-complete in HubSpot as you finish each call
• Automatically discovers all phone properties (mobile, work, custom fields)
• Set a preferred primary phone field in Settings
• OAuth authentication — secure, no scraping required

🔹 Close CRM Support (Level 3)
Full API integration with Close:

• Launch dial sessions from Close contacts and leads
• Automatic phone and email fetching via the Close API
• Call activity logging — calls are recorded as activities in Close
• Call notes sync — notes entered during calls appear in Close
• OAuth authentication — connect securely with one click

🔹 Apollo.io Support (Level 3)
Full API integration with Apollo:

• Launch dial sessions from Apollo People page — select contacts and dial
• Dial from sequence call tasks — pick a sequence, filter by due tasks, and power-dial
• Filter tasks by due today, overdue, or all open
• Auto-navigate to Apollo contact profiles during calls
• OAuth authentication — connect securely with one click

🔹 Multi-CRM Compatibility

• HubSpot — Advanced Level 3 integration (API-based)
• Close — Advanced Level 3 integration (API-based)
• Apollo.io — Advanced Level 3 integration (API-based)
• AgencyZoom — Optimized Level 2 support (task-list dialing)
• Pipedrive — Optimized Level 2 support
• Salesforce — Optimized Level 2 support
• Zoho CRM, monday.com, and others — Generic Level 1 support

⚙️ How It Works

1. Install the extension
2. Connect your PhoneBurner account using a Personal Access Token
3. Navigate to a CRM list page or open the extension popup
4. Launch a dial session with one click (or select a list)
5. Follow calls live as PhoneBurner dials through your contacts

🔐 Security & Privacy

✅ Your PhoneBurner Personal Access Token is stored server-side with strict owner-only file permissions — your browser keeps only an anonymous lookup key, never the token itself
✅ HubSpot, Close, and Apollo authentication uses industry-standard OAuth
✅ No data is sold or shared with third parties
✅ The extension only reads CRM data when you initiate an action (clicking Launch)
✅ All communication is encrypted over HTTPS

🧾 Requirements

An active PhoneBurner account is required
👉 Get one at: https://phoneburner.biz/

🛠️ Built For Sales Teams
This extension is ideal for:

• Sales reps and account executives
• Recruiters and talent acquisition teams
• SDR/BDR teams
• Call-heavy CRM users
• Teams using HubSpot or Close with large contact lists

📌 Notes

• Some CRMs are supported via optimized scraping when APIs are unavailable
• HubSpot list-based launching requires connecting your HubSpot account via OAuth
• Close integration requires connecting your Close account via OAuth
• Apollo integration requires connecting your Apollo account via OAuth
• PhoneBurner dial sessions support up to 500 contacts
• Features may vary slightly by CRM platform

🆕 What's New in v0.8.2

📋 Click-to-Call on your HubSpot tasks — the flame icon now appears next to phone numbers on your tasks list (both the classic Task Queue and the newer All Tasks table). Click to call a task's contact in one step.

✅ Tasks auto-complete after task-row calls — click the flame on a task, disposition the call, and HubSpot marks the task complete automatically. Same behavior the Task-Based Dial Session flow already had, now on the single-click side too.

🐛 Fixed a HubSpot console-error that some users saw on page load.

---

# Store Review Form Responses (Chrome & Edge)

> **Purpose:** Canonical answers for the compliance/review forms required by the Chrome Web Store and Microsoft Edge Add-ons publishing dashboards. Both stores ask for similar information; the responses below are written to satisfy either reviewer.
>
> Re-review whenever a store changes its form or whenever the extension adds, removes, or changes a permission.
>
> Last verified against: Microsoft Edge Add-ons Privacy form (2026-07).

---

## Single purpose description

> PhoneBurner Dial Session Companion enables PhoneBurner subscribers to start a PhoneBurner power-dialer session directly from supported CRM web pages (HubSpot, Close, Apollo, Salesforce, Pipedrive, AgencyZoom, and generic-level support for Zoho CRM, monday.com, and other Chromium-compatible CRM sites). When the user explicitly clicks "Launch Dial Session," the extension reads the user's selected contacts from the active CRM page and sends them to PhoneBurner's backend. While a dial session is active, the extension displays a real-time "Follow" overlay on the CRM page showing the current call, live session statistics, and auto-navigating the CRM tab to the contact being dialed. Every action is user-initiated — the extension does not run silently or collect data passively. This is the extension's only function.

---

## Permission Justifications

These match the `permissions` and `host_permissions` arrays in `manifest.json`.

### `storage`

> The `storage` permission persists a small set of values in `chrome.storage.local`: (1) a randomly generated client identifier (UUID) that ties this browser install to its server-side token record, (2) the user's preferences such as goal dispositions, HubSpot primary phone field selection, and Follow widget auto-collapse setting, and (3) the most recent dial session token so the Follow widget can reconnect after a page refresh. Sensitive credentials (PhoneBurner Personal Access Token, HubSpot/Close/Apollo OAuth tokens) are NOT stored in browser storage — they live on PhoneBurner's secure backend with strict file permissions, and the extension references them only via the local client identifier.

### `activeTab`

> The `activeTab` permission lets the extension read the URL of the currently focused tab so it can detect whether the user is on a supported CRM (HubSpot, Close, Apollo, Salesforce, Pipedrive, AgencyZoom, and other supported CRM sites) when they open the popup. This determines which "Launch Dial Session" controls appear. Combined with `scripting`, it allows the extension to inject content scripts on the active tab only when the user explicitly invokes the extension — never silently in the background.

### `scripting`

> The `scripting` permission is required to inject the extension's content script into the active CRM tab when the user invokes a "Launch Dial Session" action. The content script reads the user's selected records from the CRM page (name, phone, email, record URL) and reports them back to the extension's background worker so they can be forwarded to PhoneBurner. The same permission is used to inject the "Follow" overlay into the CRM page during an active dial session to display live call state and auto-navigate to the contact being dialed. Injection only happens in response to an explicit user action, never automatically.

### `tabs`

> The `tabs` permission lets the extension associate each active dial session with the specific browser tab the user launched it from. This is needed so that: (a) live updates (next contact being dialed, call result events) are routed to the correct CRM tab; (b) the Follow overlay's auto-navigate feature can update only that tab's URL; (c) as the user moves between CRM list pages and record pages, the popup's button set refreshes to match the current view. The extension does not read tab content through `tabs` — content reading uses `activeTab` + `scripting` only.

### `permissions`

> The `permissions` API is used to request additional host access at runtime, only when the user attempts to launch a dial session on a CRM domain that wasn't pre-approved at install. This implements a least-privilege pattern: instead of demanding broad host access during install, the extension requests permission for the specific CRM's domain at the moment the user invokes it. The user sees the request and can approve or deny per-domain. This is the security model recommended by Microsoft/Google for extensions that interact with a wide range of possible web hosts.

### Host permissions

> The three required hosts are PhoneBurner-owned backend domains used exclusively for the extension's core function:
>
> - `extension.phoneburner.biz` — production backend (authenticates the user's PhoneBurner token, creates dial sessions, streams live call updates via Server-Sent Events, handles OAuth callbacks for HubSpot/Close/Apollo).
> - `extension-dev.phoneburner.biz` — staging backend (same endpoints, used for pre-release testing).
> - `webhooktest.phoneburner.biz` — legacy webhook test backend retained for backward compatibility.
>
> The two `optional_host_permissions` (`https://*/*`, `http://*/*`) are NEVER granted at install. The extension requests them lazily, per-domain, only when a user actively launches a dial session on a CRM domain, via `permissions.request()`. The user sees and approves each CRM hostname individually.

---

## Remote code

**Are you using remote code?** **No.**

> The extension does not use any remote code. All JavaScript and HTML is bundled in the extension package. There are no remote `<script src>` tags, no dynamically loaded ES modules, no `eval()`, no `new Function()`, and no WebAssembly. Communication with PhoneBurner's backend uses the standard `fetch()` API to send and receive JSON only — never executable code. Server-Sent Events (EventSource) is used for real-time call updates and likewise transports structured JSON, not code.

---

## Data Usage Disclosure

What user data does the extension collect, now or in the future? Check the boxes that apply on each store's form.

| Category | Collect? | Why / What |
|---|---|---|
| Personally identifiable information | ✅ Yes | Names, phone numbers, email addresses read from CRM pages on user invocation, sent to PhoneBurner to populate the dial session |
| Authentication information | ✅ Yes | The user enters their PhoneBurner Personal Access Token in the popup; it's transmitted to PhoneBurner's backend (not stored in browser storage). OAuth tokens for HubSpot/Close/Apollo flow through the extension during the auth handshake but are stored server-side. |
| Website content | ✅ Yes | The content script reads selected record data from the CRM page DOM on user invocation |
| Health information | ❌ No | |
| Financial and payment information | ❌ No | |
| Personal communications | ❌ No | Edge's example ("emails, texts, or chat messages") refers to communications between people; call notes a rep types about a call do not fall under this category |
| Location | ❌ No | |
| Web history | ❌ No | |
| User activity (clicks, scroll, keystroke logging, network monitoring) | ❌ No | The extension does not track behavior |

---

## Privacy Policy URL

**Production URL (current submission):** `https://extension.phoneburner.biz/privacy.html`

**Dev URL (staging only):** `https://extension-dev.phoneburner.biz/privacy.html` — used for internal testing of privacy-policy changes before they land on prod.

---

## Compliance Certifications

All three of the standard store certifications are true for this extension and should be checked:

1. ✅ I do not sell or transfer user data to third parties outside the approved use cases.
2. ✅ I do not use or transfer user data for purposes that are unrelated to my extension's single purpose.
3. ✅ I do not use or transfer user data to determine creditworthiness or for lending purposes.

---

## Notes for Certification (Edge Add-ons — REQUIRED EVERY SUBMISSION)

Microsoft Edge's submission form has a **"Notes for certification (less than 2,000 characters)"** field under Properties. It's asking how a reviewer can validate the extension without valid PhoneBurner credentials (paid product, no test creds we can hand out).

**The canonical text lives at [`/EDGE_TESTING_INSTRUCTIONS.txt`](../EDGE_TESTING_INSTRUCTIONS.txt) at the repo root.** Open that file, copy the entire contents, paste into the "Notes for certification" field on every Edge submission. Chrome Web Store does not have an equivalent field — skip for CWS.

Why this note lives here: forgetting it triggers an Edge cert failure and adds 24-48h of back-and-forth. Twice-bitten. If the reviewer response criteria drift, update `EDGE_TESTING_INSTRUCTIONS.txt` — not this section — since the file is the canonical version.

---

## Submission Workflow

When publishing a new version to either store:

1. Update `manifest.json` version + `changelog.js` (see CLAUDE.md → "Pre-PR Checklist (Version & Changelog)").
2. Update the **What's New** section above with a copy of the latest user-facing changelog entry.
3. Re-read the **Single purpose description**, **Permission Justifications**, and **Data Usage Disclosure** sections — if anything has materially changed (new permission, new host, new data category), update the corresponding section here BEFORE submitting to either store.
4. Copy the **Full Description** above into the store's listing copy field.
5. Copy each **Permission Justification** into the matching field on the store's review form.
6. Confirm the **Data Usage Disclosure** checkboxes match the table above.
7. Confirm the **Privacy Policy URL** field still points at the right host.
8. Check all three **Compliance Certifications** boxes.
9. **Edge only** — paste the full contents of [`/EDGE_TESTING_INSTRUCTIONS.txt`](../EDGE_TESTING_INSTRUCTIONS.txt) into the "Notes for certification" field on the Properties tab. See the section above.
