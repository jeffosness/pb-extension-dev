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

Dial faster. Stay in your CRM.

Turn your CRM into a power-dialing machine. Install the free extension, pick your contacts, and reach up to 4x more people per hour — 60 to 80 dials an hour instead of a handful — without exporting a CSV or leaving your CRM tab.

Trusted by 3,000+ sales teams. Built by PhoneBurner — 4.7 on G2, with badges for Best ROI, Easiest Setup, and Momentum Leader.

✅ Reach up to 4x more people per hour
✅ Automatic call logging on supported CRMs — calls and notes write back to the record, so there's no after-call data entry
✅ Follow every call live — your CRM auto-navigates to the record being dialed, so you're always looking at the right person

🚀 What You Can Do

🔹 Launch a dial session in one click
Pick contacts from any list, saved segment, or task queue and start dialing instantly — no CSV exports, no imports. Works with contacts, companies, and deals.

• Select records on any CRM list view and launch
• Pick a saved HubSpot list and dial up to 500 contacts or companies at once
• Dial straight from your HubSpot Task Queue or AgencyZoom task list — turn your to-do list into a power session

🔹 Click-to-call for one-off calls
A PhoneBurner flame icon sits next to phone numbers on your records, lists, and tasks. Click it to place — and log — a single call in seconds, perfect for quick follow-ups when you don't need a full session.

• Every phone field is dialable — mobile, work, home, and custom properties
• On HubSpot, calling a task row auto-completes the task; on Forth, click-to-call logs the call and your note to the contact
• Icon-only and out of your way — toggle it off anytime in Settings

🔹 Never lose your place
The real-time Follow widget rides along on your CRM tab, showing the current call and your live session stats, and auto-navigates to whoever's being dialed — so you're always on the right record.

⚙️ Works Where You Already Work

PhoneBurner connects deepest through full API integrations with HubSpot, Close, Apollo.io, and Forth CRM — with automatic call logging on Close and Forth. AgencyZoom, Pipedrive, and Salesforce are supported through optimized page reading, and Zoho CRM, monday.com, and other CRM sites work through generic list scanning.

The essentials by CRM:

🔹 HubSpot — Launch from selected contacts, companies, or deals; from a saved list (up to 500 records); or from the Task Queue, where tasks auto-complete as you finish each call. Discovers every phone property and lets you set a preferred number. Secure OAuth, no scraping.

🔹 Close — Launch from contacts or leads. Calls log as Close activities and your call notes sync to the lead, with custom outcomes matched automatically. One-click OAuth.

🔹 Apollo.io — Launch from the People page, or straight from sequence call tasks (filter by due today, overdue, or all open), and auto-navigate to each profile as you dial. One-click OAuth.

🔹 Forth CRM — Launch from a contact list or a single record, with the cell number dialed first. Calls and notes log to the contact's Call History, attributed to the rep who made the call, and click-to-call sits on every number. Connect with an API key from your Forth admin.

⚙️ How It Works

1. Install the free extension
2. Connect your PhoneBurner account (Personal Access Token) — plus your CRM, for the deeper integrations
3. Open a CRM list or record
4. Launch a dial session, or click a number to place a single call
5. Dial through your list while the Follow widget keeps your CRM in sync

🔐 Security & Privacy

✅ Your PhoneBurner token is stored server-side with strict owner-only permissions — your browser keeps only an anonymous lookup key, never the token itself
✅ CRM connections use industry-standard OAuth (HubSpot, Close, Apollo) or a secure API key (Forth)
✅ The extension only reads CRM data when you start an action — never silently in the background
✅ Nothing is sold or shared with third parties, and all traffic is encrypted over HTTPS

🧾 Requirements

An active PhoneBurner account is required. Start a free trial at https://phoneburner.biz/

🛠️ Built For Sales Teams
Made for SDR and BDR teams, account executives, recruiters, and anyone making high volumes of calls out of HubSpot, Close, Apollo, Forth, or another CRM.

📌 Good To Know

• The deeper integrations ask you to connect that CRM once — via OAuth (HubSpot, Close, Apollo) or an API Key ID + Secret from your admin (Forth)
• Other CRMs are supported through optimized page reading when an API isn't available
• Dial sessions support up to 500 contacts
• Features vary slightly by CRM

🆕 What's New in v0.8.7

🎉 Forth CRM is now supported (full API integration) — connect once with your Forth API key, then launch PhoneBurner dial sessions straight from a Forth contact list or a single contact record.

📝 Calls and notes log back into Forth automatically — recorded in the contact's Call History with disposition, duration, and recording link, and any notes you type are saved on the contact.

📞 Click-to-call in Forth — a PhoneBurner button next to every phone number places (and logs) a single call in one click.

🔄 Follow-me keeps your Forth screen in sync as you dial, and roams freely between a contact's sub-tabs without pulling you back.

---

# Store Review Form Responses (Chrome & Edge)

> **Purpose:** Canonical answers for the compliance/review forms required by the Chrome Web Store and Microsoft Edge Add-ons publishing dashboards. Both stores ask for similar information; the responses below are written to satisfy either reviewer.
>
> Re-review whenever a store changes its form or whenever the extension adds, removes, or changes a permission.
>
> Last verified against: Microsoft Edge Add-ons Privacy form (2026-07).

---

## Single purpose description

> PhoneBurner Dial Session Companion enables PhoneBurner subscribers to start a PhoneBurner power-dialer session directly from supported CRM web pages (HubSpot, Close, Apollo, Forth, Salesforce, Pipedrive, AgencyZoom, and generic-level support for Zoho CRM, monday.com, and other Chromium-compatible CRM sites). When the user explicitly clicks "Launch Dial Session," the extension reads the user's selected contacts from the active CRM page and sends them to PhoneBurner's backend. While a dial session is active, the extension displays a real-time "Follow" overlay on the CRM page showing the current call, live session statistics, and auto-navigating the CRM tab to the contact being dialed. Every action is user-initiated — the extension does not run silently or collect data passively. This is the extension's only function.

---

## Permission Justifications

These match the `permissions` and `host_permissions` arrays in `manifest.json`.

### `storage`

> The `storage` permission persists a small set of values in `chrome.storage.local`: (1) a randomly generated client identifier (UUID) that ties this browser install to its server-side token record, (2) the user's preferences such as goal dispositions, HubSpot primary phone field selection, and Follow widget auto-collapse setting, and (3) the most recent dial session token so the Follow widget can reconnect after a page refresh. Sensitive credentials (PhoneBurner Personal Access Token, HubSpot/Close/Apollo OAuth tokens) are NOT stored in browser storage — they live on PhoneBurner's secure backend with strict file permissions, and the extension references them only via the local client identifier.

### `activeTab`

> The `activeTab` permission lets the extension read the URL of the currently focused tab so it can detect whether the user is on a supported CRM (HubSpot, Close, Apollo, Forth, Salesforce, Pipedrive, AgencyZoom, and other supported CRM sites) when they open the popup. This determines which "Launch Dial Session" controls appear. Combined with `scripting`, it allows the extension to inject content scripts on the active tab only when the user explicitly invokes the extension — never silently in the background.

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
