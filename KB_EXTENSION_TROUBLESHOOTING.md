# PhoneBurner Dial Session Companion — Troubleshooting Guide

**Audience:** PhoneBurner customers using the Chrome extension, PhoneBurner support reps, and AI support agents.

**Product:** PhoneBurner Dial Session Companion (Chrome extension)
**Current version:** check the store listing for the latest published version — the extension auto-updates by default in Chrome and Edge, so most customers will be on the latest within a day of any release. Some features described here may be unavailable in older installed versions.
**Install:** [Chrome Web Store](https://chromewebstore.google.com/detail/phoneburner-dial-session/hcnjaaplagkloccolpgibokkdcnmhncj) · [Microsoft Edge Add-ons](https://microsoftedge.microsoft.com/addons/detail/phoneburner-dial-session-/mdkihhcphnngfcldfbkpjmifnnhinian)

---

## How to use this article

- **For humans:** Find your symptom in the "Symptom Index" below, jump to that section, follow the steps in order.
- **For AI agents:** Each issue is structured as `Symptom → Likely Cause → Resolution Steps → Escalation Criteria`. Match the user's reported symptom against the Symptom Index keywords. If multiple sections could apply, ask the clarifying question listed under that symptom before recommending fixes.

---

## What the Extension Does (1-line summary)

It lets a PhoneBurner customer start a PhoneBurner dial session directly from a CRM (HubSpot, Close, Apollo, Salesforce, Pipedrive) without exporting CSVs, and shows a "Follow" widget on the CRM page that tracks the live call and auto-navigates to the active contact's record.

**Requires:** An active PhoneBurner account. Sign up at https://phoneburner.biz/

---

## Supported CRMs and Integration Levels

The extension supports two integration levels. The level determines which features are available.

| CRM | Level | Auth Method | Launch Methods |
|-----|-------|-------------|----------------|
| HubSpot | 3 (Full API) | OAuth | Selection, Saved Lists, Task Queue, Single Record |
| Close | 3 (Full API) | OAuth | Selection (Contacts and Leads) |
| Apollo.io | 3 (Full API) | OAuth | People Selection, Sequence Call Tasks |
| Salesforce | 2 (Optimized) | None (scrapes page) | Selected rows only (must check at least one) |
| Pipedrive | 2 (Optimized) | None (scrapes page) | All visible person rows on the Persons list |

**Other CRMs (e.g., Zoho, monday.com):** Not supported by this extension. PhoneBurner has separate integrations for those — point customers to https://www.phoneburner.com/integrations or their PhoneBurner admin's integrations page.

**Key differences:**
- **Level 3** integrations use OAuth and fetch data via the CRM's API. They support call logging back to the CRM (Close, Apollo). They are the most reliable.
- **Level 2** reads contact info from the page DOM. Customers must be on a list view with visible contacts. Quality depends on the CRM's page layout.

---

## Symptom Index (Fast Lookup)

| If the customer says... | Jump to |
|-------------------------|---------|
| "Extension won't install" / "Can't find it in Chrome Store" | [Installation](#1-installation) |
| "I don't have a PhoneBurner account" / "How do I sign up" | [No PhoneBurner account](#2-no-phoneburner-account) |
| "What is a PAT" / "Where do I find my Personal Access Token" | [Finding the PAT](#3-finding-your-pat) |
| "PAT invalid" / "PAT not saving" / "Connection failed" | [PAT errors](#4-pat-errors) |
| "CRM not detected" / "Says Loading…" / Shows wrong CRM | [CRM not detected](#5-crm-not-detected) |
| "No launch button" / "Button missing" / Buttons disappear after navigating | [Missing launch buttons](#6-missing-launch-buttons) |
| "No dialable contacts" / "0 contacts found" / "Records skipped" | [No dialable contacts](#7-no-dialable-contacts) |
| "Permission popup hangs" / Browser frozen on permission request | [Stuck permission dialog](#8-stuck-permission-dialog) |
| "Follow widget not showing" / "No overlay" / "Widget gone" | [Follow widget missing](#9-follow-widget-missing) |
| "Follow widget stuck" / "Not updating" / "Wrong contact shown" | [Follow widget not updating](#10-follow-widget-not-updating) |
| "HubSpot OAuth fails" / "Can't connect HubSpot" | [HubSpot OAuth issues](#11-hubspot-oauth-issues) |
| "Admin needs to grant App Marketplace access" / "can't install HubSpot app" / "no permission" | [HubSpot OAuth issues](#11-hubspot-oauth-issues) |
| "Close OAuth fails" / "Can't connect Close" | [Close OAuth issues](#12-close-oauth-issues) |
| "Apollo OAuth fails" / "Can't connect Apollo" | [Apollo OAuth issues](#13-apollo-oauth-issues) |
| "HubSpot lists not loading" / Lists missing / Wrong count | [HubSpot list problems](#14-hubspot-list-problems) |
| "Task Queue button missing" / "Can't dial from HubSpot tasks page" | [HubSpot Task Queue dialing](#22-hubspot-task-queue-dialing) |
| "Reconnect HubSpot prompt" / "Tasks not auto-completing" | [HubSpot Task Queue dialing](#22-hubspot-task-queue-dialing) |
| "Why is Close asking me to reconnect after the v0.7.0 update?" / "Lost my Close login after the upgrade" | [Reconnecting after the v0.7.0 upgrade](#23-reconnecting-after-the-v070-upgrade) |
| "Apollo sequences not loading" / Task counts wrong | [Apollo sequence problems](#15-apollo-sequence-problems) |
| "Phone number wrong" / "Dialed wrong number" | [Wrong phone number dialed](#16-wrong-phone-number-dialed) |
| "Call notes not in CRM" / "Activity not logged" | [Call logging issues](#17-call-logging-issues) |
| "More than 500 contacts" / "Why is my session truncated" | [500-contact limit](#18-500-contact-limit) |
| "I selected 100 but only X showed up" / "Counts vary on the same list" / "Some of my selected contacts are missing from the dial session" | [Fewer contacts than selected](#24-fewer-contacts-in-dial-session-than-selected) |
| "The flame icon isn't showing" / "Click-to-call button missing" / "Two call buttons on every record" / "Mic prompt on click-to-call" | [Click-to-Call (HubSpot)](#25-click-to-call-hubspot) |
| "Can I use AgencyZoom?" / "AgencyZoom tasks not dialing" / "Multiple tasks per lead" | [AgencyZoom](#26-agencyzoom) |
| "HubSpot phone overwritten" / "Phone field changed in HubSpot" | [HubSpot Data Sync conflict](#19-hubspot-data-sync-conflict) |
| "Is my data safe" / Privacy questions | [Privacy and security](#20-privacy-and-security) |
| "Extension completely broken" / Nothing works | [Emergency reset](#21-emergency-reset) |

---

## 1. Installation

**Symptom:** Customer can't find or install the extension.

**Resolution by browser:**

| Browser | Where to install |
|---------|------------------|
| Google Chrome | [Chrome Web Store listing](https://chromewebstore.google.com/detail/phoneburner-dial-session/hcnjaaplagkloccolpgibokkdcnmhncj) |
| Microsoft Edge | [Edge Add-ons listing](https://microsoftedge.microsoft.com/addons/detail/phoneburner-dial-session-/mdkihhcphnngfcldfbkpjmifnnhinian) (preferred) — or the Chrome Web Store link above (Edge supports both) |
| Brave, Arc, Vivaldi, Opera, other Chromium browsers | Use the [Chrome Web Store link](https://chromewebstore.google.com/detail/phoneburner-dial-session/hcnjaaplagkloccolpgibokkdcnmhncj). Most Chromium browsers install Chrome extensions natively — the install button substitutes the browser's own name. Opera may require enabling Chrome extensions via its add-on store first if the install button doesn't appear. |
| Firefox, Safari | **Not supported.** The extension is built on Chromium's extension API and won't work in non-Chromium browsers. |

**After installing (all browsers):**
1. Click **Add to Chrome / Edge / Brave / etc.** in the store listing.
2. Pin the extension to the toolbar (puzzle-piece icon → pin).
3. Open a CRM page (e.g., HubSpot, Close, Apollo) and click the PhoneBurner icon.

**Requirements:**
- Chrome, Edge, Brave, Arc, Vivaldi, or Opera (any Chromium-based browser).
- Active PhoneBurner account (see [No PhoneBurner account](#2-no-phoneburner-account)).

**Escalate if:** The extension installs but the popup is blank or shows JavaScript errors. Ask for a screenshot of the popup and any errors visible in the browser's DevTools (right-click extension icon → Inspect popup → Console tab).

---

## 2. No PhoneBurner Account

**Symptom:** Customer installed the extension but doesn't have a PhoneBurner account.

**Resolution:**
- The extension has a **Get PhoneBurner** button in the popup that links to https://phoneburner.biz/.
- They need to sign up for an active PhoneBurner account first. The extension cannot work without one.

---

## 3. Finding Your PAT

**Symptom:** Customer doesn't know how to get a Personal Access Token (PAT).

**Resolution:**
- A PAT is a unique key that lets the extension connect to the customer's PhoneBurner account on their behalf.
- **Where to find it:** PhoneBurner → Account / API settings → Personal Access Tokens.
- **Video walkthrough:** https://www.youtube.com/watch?v=ivc-B6YomLQ (also linked from the extension's welcome screen).

**Important:**
- The PAT is sensitive — treat it like a password.
- After saving, the PAT is held on PhoneBurner's secure backend with strict owner-only file permissions. The browser keeps only a local client identifier used to look the token up server-side; the PAT itself isn't kept in browser storage. It's never shared with third parties.

---

## 4. PAT Errors

**Symptom:** "PAT invalid", "Failed to save PAT", "Connection failed", or the popup never shows "Connected" status after saving.

**Likely causes:**
- PAT was copied with extra whitespace or partial characters.
- PAT was revoked or expired in PhoneBurner.
- PAT belongs to a different PhoneBurner account than expected.
- Customer's PhoneBurner account is inactive or suspended.

**Resolution Steps:**
1. In PhoneBurner, **generate a new PAT** (revoking the old one is optional but cleaner).
2. Copy the full PAT — verify no leading/trailing spaces.
3. In the extension popup, open the **Dial** tab and paste the PAT into the **PhoneBurner Access Token** field.
4. Click **Save PAT**.
5. Wait for "Connected" confirmation (usually under 5 seconds).
6. If still failing, click **Disconnect** (under Settings) and try a fresh PAT.

**Escalate if:**
- A freshly generated PAT still fails to save → could be a backend/account issue. Confirm the customer's PhoneBurner account is active and they can log into the PhoneBurner web app.

---

## 5. CRM Not Detected

**Symptom:** Extension popup shows "Detected CRM: Loading…" forever, "Detected CRM: None", or shows the wrong CRM name.

**Clarifying question:** "What CRM are you using, and what's the URL of the page you're on?"

**Likely causes:**
- Customer is on a CRM page the extension doesn't recognize (e.g., a custom subdomain, a settings page instead of a list view).
- Customer's CRM isn't one of the supported CRMs.
- Content script didn't inject (rare, usually a Chrome bug).

**Resolution Steps:**
1. Confirm the CRM is supported (see [Supported CRMs](#supported-crms-and-integration-levels)).
2. Verify the customer is on a **list view** or **record view**, not a settings/admin page.
3. For HubSpot: URL should look like `app.hubspot.com/contacts/...`
4. For Close: URL should be on `close.com` (e.g., `app.close.com/leads` or `app.close.com/contacts`).
5. For Apollo: URL should be on `apollo.io` (e.g., `app.apollo.io/#/people`).
6. **Refresh the CRM page** — content scripts only run after page load.
7. If still not detected: close and reopen the extension popup.

**Escalate if:** Customer is clearly on a supported CRM domain and refreshing doesn't help. Ask for the exact URL and a screenshot of the extension popup.

---

## 6. Missing Launch Buttons

**Symptom:** Extension popup opens, CRM is detected, but no "Launch Dial Session" button appears. Or buttons appear initially, then disappear after navigating to a different page.

**Likely causes:**
- Customer hasn't saved a PAT yet (popup shows the PAT input first).
- For Level 3 CRMs (HubSpot, Close, Apollo): customer hasn't completed OAuth connection.
- Customer is on the wrong page type (e.g., a HubSpot Settings page vs. a contact list).
- Object type mismatch — the customer is viewing a HubSpot Companies list but expects Contact-only buttons (or vice versa).

**Resolution Steps:**
1. Verify PAT is saved → popup header should show "PhoneBurner: Connected".
2. For Level 3:
   - HubSpot: Settings tab → "Connect HubSpot" must be completed.
   - Close: Settings tab → "Connect Close" must be completed.
   - Apollo: Settings tab → "Connect Apollo" must be completed.
3. Confirm page type — actual button labels (HubSpot):
   - **Contacts list** → single button: **Launch HubSpot Dial Session**
   - **Companies list** → two buttons: **Launch Dial Session (Contacts)** and **Launch Dial Session (Companies)**
   - **Deals list** → single button: **Launch HubSpot Dial Session**
   - **Contact record page** → **Dial This Contact**
   - **Company record page** → two buttons: **Dial Contacts at This Company** and **Dial This Company**
   - **Deal record page** → **Dial Contacts on This Deal**
4. **Refresh the CRM page**, then reopen the popup.
5. If still missing, navigate to a known-good page (e.g., HubSpot Contacts list `/contacts/0-1/`) and try again.

**Escalate if:** Connection states are all green, customer is on a known-good page, but buttons are still missing. Ask them to capture the URL exactly and check the popup version (bottom of popup header).

---

## 7. No Dialable Contacts

**Symptom:** Customer clicks Launch, gets an error like "No dialable contacts after normalization" or "0 records found", or "X records skipped".

**Likely causes:**
- Selected records have no phone numbers in the CRM.
- Selected records have phone numbers in non-standard fields the extension didn't pick up.
- (HubSpot only) Customer's preferred Primary Phone Field is empty for these records.
- For scraping-based CRMs (L1/L2): the page format the extension expects doesn't match what's rendered.

**Resolution Steps:**
1. Verify the selected records actually have phone numbers in the CRM.
2. For HubSpot: open Settings → check **Primary Phone Field**. If set to a specific field (e.g., "Mobile Phone"), records without that field populated will be skipped. Set it to **Default (first available)** to widen the search.
3. For HubSpot company-level dialing: the extension dials the company's own primary phone field — not the phones of contacts associated with that company. To dial the associated **contacts** instead, the customer should click **Launch Dial Session (Contacts)** on a company list view, or **Dial Contacts at This Company** on a single company record page.
4. If "X records skipped" is shown, those records lack any usable phone. Customer can either:
   - Add phone numbers to those records in the CRM, or
   - Select different records that have phones.
5. For Level 2 (Salesforce, Pipedrive): verify phones are **visible on the current page**. The extension can only read what's rendered. Add a Phone column to the list view if missing.

**Escalate if:** Customer confirms records have phones but the extension can't find any. Ask them to send: (a) screenshot of the CRM page with phone column visible, (b) screenshot of the extension error, (c) which CRM and which fields contain the phone.

---

## 8. Stuck Permission Dialog

**Symptom:** Customer clicks Launch, Chrome shows a "This extension wants to access..." permission dialog, and the dialog won't close or freezes the browser.

**Resolution Steps (in order):**

1. **Press `Esc`** to dismiss the dialog.
2. **Press `Enter`** to accept it.
3. **Click outside the dialog** area.

**If browser is fully stuck:**

4. **Chrome Task Manager:** Press `Shift+Esc` in Chrome → find "Extension: PhoneBurner Dial Session Companion" → click **End process**.
5. **Windows users:** `Ctrl+Shift+Esc` → find Chrome processes → look for the extension process → End task.
6. **Mac/Linux users:** Close the Chrome window, then reopen Chrome (extension data is preserved).

**After recovery:**
- Reopen the extension popup. The 30-second timeout should have cleared.
- Try the Launch action again. Permission requests typically only appear the **first time** the extension needs access to a new CRM domain — after granting once, it won't ask again.

**Prevention:** The extension has a built-in 30-second timeout for permission requests. If a customer sees "Permission request timed out", that's normal — they should close the popup and try again.

---

## 9. Follow Widget Missing

**Symptom:** Dial session started, but no "Follow" overlay appears on the CRM page.

**Likely causes:**
- The Follow widget appears in the **CRM browser tab**, not in PhoneBurner. Customer may be looking at the wrong window.
- Browser blocked the popup or the SSE connection.
- Network/CORS issue (rare).

**Resolution Steps:**
1. Switch back to the **CRM tab** (HubSpot, Close, Apollo, etc.) where the dial session was launched. The widget renders there, not in the PhoneBurner dialer.
2. Refresh the CRM page once the dial session is live — the widget should appear in the bottom-right.
3. Verify the dial session is actually running in PhoneBurner (contacts are visible in the dialer queue).
4. If the widget had auto-collapsed: look for the small minimized version in the bottom-right corner.

**Escalate if:** Customer confirms they're on the right tab, dial session is live in PhoneBurner, no widget appears even after refresh. Ask for the CRM URL and a screenshot of the page.

---

## 10. Follow Widget Not Updating

**Symptom:** Follow widget appears but shows stale data, the wrong contact, or stops updating after the first call.

**Likely causes:**
- Network connection dropped (the widget uses a live stream — Server-Sent Events).
- Browser tab was inactive in the background and the connection timed out.
- Customer navigated away from the CRM and back.

**Resolution Steps:**
1. **Refresh the CRM page.** This re-establishes the live stream. Active dial sessions resume seamlessly.
2. Check internet connection — flaky Wi-Fi can drop the stream.
3. Verify the dial session is still active in PhoneBurner. If the session ended, the widget will stop updating (this is expected).

**Escalate if:** Widget consistently stops updating mid-session even with stable internet. Ask for: (a) approximate time the issue started, (b) the CRM, (c) browser console errors (right-click on CRM page → Inspect → Console).

---

## 11. HubSpot OAuth Issues

### Symptom: "You don't have permission to install this app" / admin needs to grant App Marketplace access

**What's happening:** The HubSpot user trying to Connect HubSpot doesn't have **App Marketplace access** in their HubSpot user permissions. This is HubSpot's safeguard against rank-and-file users installing third-party apps that could modify CRM data — our extension requests `crm.objects.contacts.write` (used by the Task Queue feature) which triggers this gate.

The affected user **cannot reach the OAuth consent screen at all** until their HubSpot admin grants this permission.

**Resolution — a HubSpot admin (not the affected user) needs to do this:**

1. In HubSpot, click the **Settings** gear icon (top right)
2. Navigate to **Users & Teams** and click the affected user's name
3. Click the **Access** tab on the user's profile
4. Click **Edit Permissions**
5. In the left sidebar, expand **Account** and click **Settings access**
6. Find **App Marketplace access** and toggle it **on**
7. Click **Save** (top right of the page)

Once saved, the affected user retries the **Connect HubSpot** flow in the extension and the consent screen should appear normally.

**Alternative if the admin doesn't want to grant App Marketplace access to individual users:**

The admin can install the app on the account themselves by running the OAuth flow from their own HubSpot user. The OAuth grant is at the portal level, so other users in the same portal can then use the existing connection without each needing App Marketplace access.

**Why this happens:** The extension requests `crm.objects.contacts.write` (needed for HubSpot Task Queue auto-completion) and several read scopes. HubSpot gates any app requesting write scopes behind the App Marketplace permission. This is not something we can bypass from our side — it's an org-level HubSpot security control.

---

### Symptom: Consent screen appears but connection fails after approving

**Likely causes:**
- Customer denied the consent request.
- Customer chose the wrong HubSpot portal (if they have multiple).
- Cookies/popups blocked.
- HubSpot session expired mid-flow.

**Resolution Steps:**
1. Open Settings → click **Disconnect HubSpot** if shown.
2. Log in fresh to the correct HubSpot portal in a separate tab.
3. Return to the extension popup → Settings → click **Connect HubSpot**.
4. Approve all requested scopes (contacts, companies, deals, lists, and contacts write for Task Queue).
5. Make sure Chrome isn't blocking the OAuth popup window.

**Escalate if:** Customer follows all steps, sees "Authorization failed" or a blank page after approving. Ask: which HubSpot account, the exact error text/URL, and screenshots.

---

## 12. Close OAuth Issues

**Symptom:** "Connect Close" fails or shows error after approving access.

**Resolution Steps:**
1. Settings → **Disconnect Close** if previously connected.
2. Log into Close in a separate tab.
3. In the extension Settings, click **Connect Close** → approve the OAuth consent.
4. After approval, the popup closes automatically. Reopen the extension popup — Settings should now show "Connected to Close".

**Escalate if:** Customer sees "Invalid client" or "Token exchange failed" repeatedly. Likely a server-side credentials issue — escalate to engineering with the timestamp.

---

## 13. Apollo OAuth Issues

**Symptom:** "Connect Apollo" fails or doesn't complete.

**Resolution Steps:**
1. Settings → **Disconnect Apollo** if previously connected.
2. Log into Apollo at apollo.io in a separate tab.
3. In the extension Settings → click **Connect Apollo** → approve scopes.
4. After approval, return to the extension popup. Settings should show "Connected to Apollo".

**Apollo-specific note:** Apollo plan tier matters. The extension uses Apollo's API for contacts and sequences — customers on very restrictive Apollo plans may have rate limits or feature limits that affect bulk dialing.

**Escalate if:** OAuth completes but contact data is empty or sequences don't load. Likely an Apollo permissions/plan issue — confirm the customer's Apollo plan allows API access.

---

## 14. HubSpot List Problems

**Symptom:** Saved HubSpot lists don't appear in the dropdown, show wrong contact count, or "list is empty" when launching.

**Likely causes:**
- HubSpot's API doesn't return list member counts (this is a known HubSpot API limitation — the extension displays "(contacts list)" instead of "(N contacts)" when the count isn't available).
- List has more than 500 members (PhoneBurner dial sessions cap at 500).
- Customer is searching for a list that doesn't exist or is a Static list with no members.

**Resolution Steps:**
1. Use the search box above the list dropdown to find lists by name.
2. If a list shows "(contacts list)" without a count, it doesn't mean the list is empty — HubSpot just doesn't tell us the size. Try launching it.
3. If a list has more than 500 members, the dial session will be truncated to the first 500. This is a PhoneBurner platform limit, not an extension limit. The customer can split the list into smaller segments in HubSpot.
4. If lists are missing from the dropdown that the customer expects to see: disconnect and reconnect HubSpot (Settings → Disconnect HubSpot → Connect HubSpot). The extension may need to refresh scope.

**Escalate if:** HubSpot is connected, lists exist in HubSpot, but the extension dropdown is empty. Ask: which HubSpot portal, list names they expect, screenshot of the empty dropdown.

---

## 15. Apollo Sequence Problems

**Symptom:** Apollo sequences don't load, task counts are wrong, or "Launch from Tasks" button doesn't work.

**Resolution Steps:**
1. Verify Apollo is connected (Settings → "Connected to Apollo").
2. Open the Sequences card in the Dial tab. Select a sequence from the dropdown.
3. Choose a **Filter tasks** option:
   - **Due today** — only tasks dated today.
   - **Due today + overdue** — today's plus past-due tasks.
   - **All open** — all incomplete call tasks in the sequence.
4. The task preview line shows how many call tasks match.
5. Only sequences with **call tasks** (not email tasks) will produce dialable contacts.

**Escalate if:** Sequence has visible call tasks in Apollo but the extension shows 0. Ask for: sequence name, filter used, screenshot.

---

## 16. Wrong Phone Number Dialed

**Symptom:** PhoneBurner dialed a different number than what the customer expected (e.g., dialed work phone instead of mobile).

**Likely causes:**
- The CRM record has multiple phone numbers and the extension picked one as primary.
- Customer hasn't set a Primary Phone Field preference.

**Resolution Steps (HubSpot):**
1. Open the extension popup → Settings → **Primary Phone Field**.
2. Select the field that should be dialed first (e.g., "Mobile Phone Number" or a custom field).
3. Save. New dial sessions will use this preference.
4. Other phone fields are still included as **additional numbers** in the PhoneBurner record — they're not lost, just deprioritized.

**Resolution Steps (Apollo):**
1. Settings → **Primary Phone Field**.
2. Choose: Direct Phone, Mobile Phone, or Corporate/HQ Phone.
3. Default behavior tries Direct → Mobile → Corporate in that order.

**For other CRMs (Close, Salesforce, Pipedrive):**
- The extension uses whichever phone number is detected first or marked primary in the CRM. No per-field preference is available in the current version.

**Escalate if:** Customer's CRM has a phone number that the extension consistently ignores. Likely a custom field the extension doesn't read — engineering can add support if the field name is provided.

---

## 17. Call Logging Issues

**Symptom:** Customer expected a call activity, call note, or call disposition to appear in their CRM after the call, but nothing was logged.

**Supported call logging:**
- **HubSpot:** Call activities log to HubSpot via the existing **PhoneBurner ↔ HubSpot integration** in the customer's PhoneBurner account — not via this extension. The customer must have that integration activated at https://www.phoneburner.com/myaccount/integrations/hubspot/index for activities to appear in HubSpot.
- **Close:** Call activities are logged automatically by the extension after each call. Notes typed in PhoneBurner during the call are included.
- **Apollo:** Call activities and notes are logged automatically by the extension.

**Resolution Steps (HubSpot):**
1. Direct the customer to https://www.phoneburner.com/myaccount/integrations/hubspot/index in their PhoneBurner admin account.
2. Confirm the PhoneBurner ↔ HubSpot integration is **activated** there. Without it, calls won't log to HubSpot regardless of how the dial session was launched.
3. After activating, run a test dial session and confirm the activity appears on the contact in HubSpot.
4. Note: this is a separate integration from the extension. The extension launches dial sessions; this PhoneBurner integration handles writing activities back to HubSpot.

**Resolution Steps (Close / Apollo):**
1. Confirm Close/Apollo is still connected in the extension (Settings).
2. Verify the call actually happened — open the PhoneBurner Activity log.
3. Allow up to 30 seconds for the activity to appear in Close/Apollo after the call ends.
4. **Close-specific — where to find the real PhoneBurner result:** Close's API stores the standard **disposition** field as "answered" for any externally-created call activity, regardless of what PhoneBurner reported. This is a Close API limitation (confirmed by Close support), not a bug in this extension. The actual PhoneBurner result is recorded in **two** places on the Close call activity:
   - **Custom Outcome** — the extension auto-creates and assigns a Close Outcome that matches the PhoneBurner status text (e.g., "Set Appointment", "No Answer", "Voicemail"). Look at the Outcome column or the call activity's Outcome field. Note: custom Outcomes require a Close plan that supports them; on plans without Outcomes the extension silently skips this step.
   - **Note body** — the PhoneBurner status, any call notes entered during the call, and (when available) a link to the call recording are written into the call activity's note.

**Tips for teams configuring Close:**
- **Existing Close Outcomes are reused, not duplicated.** The extension matches PhoneBurner status text against the team's existing Close Outcomes by name, **case-insensitively**. So if the team already has an Outcome called "No Answer" in Close, PhoneBurner's "No Answer" / "no answer" / "NO ANSWER" all map to that same existing Outcome. Only PhoneBurner statuses with no name match get auto-created in Close. Teams with a curated set of Outcomes in Close don't need to pre-configure anything — their existing names will be picked up automatically as PhoneBurner reports matching statuses.
- **Outcome IDs are cached for 24 hours per org.** If the team adds, renames, or deletes an Outcome in Close and expects the extension to pick up the change immediately, there may be up to a 24-hour delay before the cache refreshes. For an immediate refresh, engineering can clear the cache manually — the file lives at `cache/close_outcomes_{org-hash}.json` on the server, and removing it forces a re-fetch on the next call.

**Escalate if:**
- HubSpot: customer confirms the PhoneBurner ↔ HubSpot integration is activated but calls still aren't appearing. Escalate to PhoneBurner integrations support (not extension engineering).
- Close/Apollo: call activities are missing for confirmed-completed calls. Ask: customer email/org, call timestamp, contact name. Extension engineering can trace via webhook logs.

---

## 18. 500-Contact Limit

**Symptom:** Customer selected/launched with more than 500 records, but the dial session only has 500 contacts.

**Explanation:**
- PhoneBurner dial sessions cap at **500 contacts per session**. This is a PhoneBurner platform limit, not an extension limit.
- When more are selected, the extension takes the first 500.

**Resolution:**
- Split the selection into batches of 500 or fewer.
- For HubSpot Lists: create smaller lists in HubSpot (filter by date added, owner, lifecycle stage, etc.).
- For sequence tasks: filter by "Due today" instead of "All open" to reduce the count.

---

## 19. HubSpot Data Sync Conflict

**Symptom:** A contact's phone number in HubSpot was overwritten or changed after a dial session.

**Cause:** If the customer has PhoneBurner's **separate "Data Sync" app** connected to HubSpot, PhoneBurner syncs the primary dialed number back to HubSpot's "Phone Number" field. This can overwrite the original value.

**This is not the extension's behavior** — it's the PhoneBurner Data Sync integration, which is a separate product.

**Resolution:**
- Tell the customer: disable phone number syncing in the PhoneBurner Data Sync app settings, OR rely on the extension to feed phone numbers into dial sessions and not use Data Sync for phone fields.
- The extension itself never writes back to HubSpot — it only reads.

**Escalate to:** PhoneBurner Data Sync support, not extension engineering.

---

## 20. Privacy and Security

**Common customer questions and answers:**

**Q: Where is my PhoneBurner access token stored?**
A: It's stored on PhoneBurner's secure backend with strict file permissions (owner-only access, kept outside the public web root). The browser holds a local client identifier — not the token itself — which is used to look the token up server-side. The token is never shared with third parties.

**Q: Does the extension read all my CRM data?**
A: No. It only reads contact data (name, phone, email, record link) when the customer **explicitly clicks Launch**. It does not run silently in the background.

**Q: Does PhoneBurner sell my data?**
A: No. Data flows only between the customer's browser, the customer's CRM, and PhoneBurner's dialer API. No third-party sharing.

**Q: Where does the extension run?**
A: Only on CRM pages the customer visits. It does not scan unrelated websites.

**Q: How is contact data transmitted?**
A: Over HTTPS only. OAuth tokens (HubSpot, Close, Apollo) are exchanged using standard OAuth 2.0 flows.

**Q: Can I delete my data?**
A: Yes — but **disconnect before uninstalling** for a complete cleanup:
1. In the extension popup → Settings → click **Disconnect** for each connected service (PhoneBurner, HubSpot, Close, Apollo). This clears tokens both locally and on PhoneBurner's backend.
2. Then uninstall the extension from `chrome://extensions`.

**Why the order matters:** Uninstalling alone only removes local Chrome data — it does not signal the server to clear the backend tokens. If a customer has already uninstalled without disconnecting first, the backend tokens become orphaned (still owner-restricted on disk, but no longer reachable from the extension). To request manual server-side cleanup in that case, contact PhoneBurner support with the customer's account email.

---

## 21. Emergency Reset

**Symptom:** Extension is completely broken — popup hangs on "Loading…", nothing works, after some change or update.

**Resolution Steps (in order — least to most destructive):**

1. **Refresh the CRM page** and reopen the popup.
2. **Close the popup and reopen** (don't just close — fully close by clicking outside).
3. **Disable and re-enable** the extension (Chrome → puzzle-piece icon → Manage Extensions → toggle off, then on).
4. **Reload the extension** (chrome://extensions → "Reload" arrow on the extension card).
5. **Disconnect everything** (extension Settings → Disconnect PhoneBurner, Disconnect HubSpot/Close/Apollo) and reconnect fresh.
6. **Uninstall and reinstall** (chrome://extensions or edge://extensions → Remove → reinstall from the appropriate store: [Chrome Web Store](https://chromewebstore.google.com/detail/phoneburner-dial-session/hcnjaaplagkloccolpgibokkdcnmhncj) or [Microsoft Edge Add-ons](https://microsoftedge.microsoft.com/addons/detail/phoneburner-dial-session-/mdkihhcphnngfcldfbkpjmifnnhinian)). The customer will need to re-enter their PAT and reconnect OAuth providers, but no CRM data is lost.

**Escalate if:** Even a fresh reinstall doesn't work. Ask for: Chrome version, OS, the CRM URL, screenshots of the extension popup, and any errors visible in Chrome DevTools console.

---

## 22. HubSpot Task Queue Dialing

**What it does:** Lets a customer launch a PhoneBurner dial session for all contacts associated with the tasks visible on their HubSpot tasks page (e.g., `app.hubspot.com/tasks/{portalId}/view/all` or any task queue). Once the session is running, each task gets marked **Completed** in HubSpot as the corresponding call finishes — no manual cleanup.

**Available in:** Extension v0.6.4 and later.

### How to use it

1. In HubSpot, open any tasks page (Tasks view, a specific Task Queue, or the "Due today" filter).
2. Open the extension popup. A new **"HubSpot Task Queue"** card appears.
3. (Optional) In HubSpot, check the checkboxes on the specific task rows you want to dial. If no rows are checked, the extension dials through the entire visible queue.
4. Click **Launch Task Queue** in the popup. A dial session is built from the contacts associated with those tasks.
5. As you complete each call, the corresponding task is auto-marked **Completed** in HubSpot.

### Symptom: "I don't see a Task Queue card / launch button"

**Likely causes:**
- Customer is not on a HubSpot tasks page. The card only appears on `app.hubspot.com/tasks/.../view/...` URLs.
- Customer is on an older extension version (pre-0.6.4). Wait for Chrome auto-update or force a reload at `chrome://extensions`.
- HubSpot isn't connected. Settings → Connect HubSpot.

**Resolution:**
1. Confirm the URL contains `/tasks/` and a `/view/` segment.
2. Confirm the extension version at the bottom of the popup is **0.6.4 or higher**.
3. Confirm HubSpot shows as **Connected** in Settings.

### Symptom: "Reconnect HubSpot to enable Task Queue dialing" prompt appears

**What this means:** The customer's HubSpot connection is missing the new `crm.objects.contacts.write` permission, which is required to mark tasks complete. This is expected for any customer who connected HubSpot before v0.6.4 launched — the older connection didn't request that permission.

**Resolution:**
1. Click **Reconnect HubSpot** inside the Task Queue card (or go to Settings → Disconnect HubSpot → Connect HubSpot).
2. Approve all requested scopes on HubSpot's consent screen — note this new connection asks for a write permission on contacts/tasks, which is what powers the auto-completion.
3. Return to the tasks page; the launch button will be active.

This is a one-time reconnect. The customer's existing dial flows (Selection, Saved Lists) keep working throughout — they're not blocked from any existing functionality during the reconnect prompt.

### Symptom: "Dial session launched but tasks aren't being marked complete in HubSpot"

**Likely causes:**
- Customer reconnected HubSpot but the OAuth flow was canceled before approving the new scope.
- The contact dialed has no HubSpot task associated with them in this session (e.g., the customer added contacts manually via Selection-mode on top of the Task Queue session — only the original task-driven contacts auto-complete).

**Resolution:**
1. In the extension popup, open Settings and confirm HubSpot shows as **Connected**.
2. Disconnect HubSpot → Connect HubSpot, making sure to approve all consent scopes on HubSpot's screen.
3. Launch a fresh Task Queue session and dial one contact as a test.
4. Check the task in HubSpot — it should show **Completed** status within a few seconds of the call ending.

**Escalate if:** Customer reconnected with all scopes approved and tasks still don't complete. Ask for: the HubSpot portal ID, the extension version, the timestamp of a recent test call, and the task ID in HubSpot. Engineering will check the webhook logs for the task-completion attempt.

### Symptom: "Some tasks didn't get dialed / fewer contacts than tasks"

**Cause:** Not every HubSpot task has a contact association. Tasks created without an associated contact (e.g., "Follow up on email" tasks attached only to a deal or company, or standalone reminders) can't be dialed — there's no phone number to call. The extension silently skips those tasks when building the session.

**Resolution:** Confirm in HubSpot that the missing tasks have a contact associated. Adding a contact association to a task in HubSpot won't retroactively add them to an in-progress dial session — start a new session after associating the contact.

### Edge cases worth knowing

- **500-contact cap:** Same as other HubSpot launch methods. If the visible task queue points to more than 500 unique contacts, the session is truncated at 500.
- **Duplicate contacts:** If two tasks are associated with the same contact, that contact appears once in the dial session but BOTH tasks get marked Completed when the call finishes.
- **Selection inside task page:** Checking specific task rows narrows the session to those rows only. Unchecking everything (the default) dials all visible tasks.
- **Mid-session task changes:** Marking a task complete manually in HubSpot during an active dial session doesn't pull that contact out of the session — PhoneBurner has already accepted the contact list.

---

## 23. Reconnecting after the v0.7.0 upgrade

**Overview:** v0.7.0 migrated the extension to a new production backend. As part of the rollout, we pre-positioned customer tokens onto the new backend ahead of the Chrome auto-update — so for most customers the cutover is **invisible**. PhoneBurner, HubSpot, and Apollo connections carry over automatically. Only Close requires a one-time reconnect, and only for customers who use Close.

CRM data, PhoneBurner data, and dial history are unaffected by the cutover. **Available in:** Extension v0.7.0 and later.

### Expected experience by CRM

| CRM the customer uses | What they see on first popup-open after v0.7.0 auto-updates |
|---|---|
| PhoneBurner only | Still connected. No action required. |
| PhoneBurner + HubSpot | Still connected to both. No action required. |
| PhoneBurner + Apollo | Still connected to both. No action required. |
| PhoneBurner + Close | PB and Close both still showing as connected initially, but the first Close dial session launch may prompt them to reconnect Close (this is the one re-OAuth the cutover requires). |
| PhoneBurner + HubSpot + Close | HubSpot still connected; Close prompts for one-time reconnect on next session launch. |

### Symptom: "Close is asking me to reconnect after the upgrade"

**Cause:** Close's OAuth model required us to set up a separate production app (Close doesn't support multiple redirect URIs on a single app the way HubSpot and Apollo do). Tokens from the previous Close app don't carry over to the new prod Close app. This is a planned, **one-time** reconnect.

**Resolution:** Click **Settings → Connect Close** → approve Close's consent screen → done. ~30 seconds.

Close customers will see a new entry in their Close *Connected Apps* list named **"PhoneBurner Chrome Extension Production"** — that's expected, and the previous "PhoneBurner Chrome Extension" entry can be removed at the customer's convenience.

### Symptom: "PhoneBurner is showing 'Not connected' after the upgrade" (rare)

**Likely causes:**
- Customer manually flipped the hidden Developer Options env toggle to **dev** before the upgrade and never flipped back.
- The customer's PB PAT was rotated or revoked since their last connection.
- The customer is on a non-standard Chrome profile or sync state where the pre-migrated tokens didn't apply.

**Resolution:**
1. Confirm the customer is on v0.7.0 (visible at the bottom of the popup header).
2. Re-copy a fresh PB Personal Access Token from PhoneBurner → **Settings → Personal Access Tokens**.
3. Paste freshly into the popup field — no leading/trailing whitespace.
4. If still failing, generate a brand-new PAT in PhoneBurner and try that.

### Symptom: "HubSpot or Apollo is showing 'Not connected' after the upgrade" (very rare)

**Likely causes:**
- Customer's HubSpot/Apollo tokens had already expired before the migration ran (e.g., they hadn't used the extension in months).
- Customer manually disconnected the CRM in the extension at some point after the migration ran.

**Resolution:**
1. Settings → **Connect HubSpot** / **Connect Apollo** → approve the consent screen.
2. Same scopes as before — no functional change for the customer.

### Symptom: "OAuth fails after I approve on the provider's side"

**Likely causes:**
- Browser blocked the OAuth popup window.
- Cookies are blocked for the provider.
- The customer's HubSpot user permissions don't include App Marketplace access (see [Section 11](#11-hubspot-oauth-issues) for the admin fix).

**Resolution:**
1. Disable any popup blockers for `extension.phoneburner.biz` and the CRM provider's domain.
2. Confirm cookies are enabled for the provider.
3. Try the OAuth flow in a fresh tab.
4. For HubSpot specifically: see Section 11 — the customer's HubSpot admin may need to grant App Marketplace access first.
5. If still failing, escalate with the provider name, exact error wording, and timestamp.

### "Why do I have to do anything?" — for support reps

For Close users only: tell them this is a one-time reconnect tied to a backend infrastructure migration. Close's OAuth model required us to set up a separate production app — that's why HubSpot and Apollo carry over but Close doesn't. Their PhoneBurner account, dial history, contact records, and Close data are all unaffected. The reconnect takes about 30 seconds.

**Escalate if:**
- Customer says PB or HubSpot/Apollo is showing "Not connected" after the upgrade and re-pasting a fresh PAT / re-OAuth doesn't fix it. Ask for the error message in the popup status area + a screenshot of the popup + the extension version.
- Close OAuth fails after consenting on Close's side AND popup-blocker / cookies / fresh-tab steps don't help. Escalate with the timestamp and exact error wording.

---

## 24. Fewer Contacts in Dial Session Than Selected

**Symptom:** Customer says: "I selected 100 contacts on my CRM list but only 38 (or 72, or some other smaller number) were added to my dial session."

> **A note on terminology:** "Contacts" below covers any record the extension can dial — contacts, companies, deals, or leads, depending on the CRM. The three causes below apply to all of them, but contacts are the most common case.

There are **three things that commonly cause this**, sometimes in combination. Walk the customer through them in order.

### Cause A — Scroll / virtualization issue (mostly HubSpot list views)

**The salesperson explanation:** HubSpot list views are like an infinite-scroll feed (think Twitter or Instagram). HubSpot only loads the rows currently on screen. When the customer clicks "Select all 100" or checks individual boxes, HubSpot remembers the selection internally, but the actual "checked" mark is only on the rows currently visible. The rest are tracked behind the scenes but not actually drawn on the page yet.

To capture everyone, the extension auto-scrolls through the list, pausing at each step to grab the rows that have just been loaded. This works most of the time, but a few things can cut the scroll short and miss some of the 100:

- **Slower internet** — if HubSpot pauses to load the next batch of rows, the extension may interpret the pause as "we've reached the end" and stop early.
- **Smaller browser window** — fewer rows fit on screen at once, so the extension has to scroll more times to catch them all, increasing the chance of timing out.
- **Background CPU load** (other heavy tabs, video calls, screen recording) — slows the scroll-and-capture loop.

**Tell-tale sign that it's a scroll issue:** the count is **different each time** the customer relaunches the same list (e.g., 38 once, then 51, then 22).

**Workaround:** Use the **Launch from List** dropdown in the extension popup instead of checking boxes on the page. The dropdown launch fetches the list through HubSpot's API, which doesn't depend on scrolling and reliably grabs every contact in the list (up to the 500-contact session cap — see Section 18).

### Cause B — Selected contacts that have no phone number

If a selected contact has no phone number in any of its phone fields (Phone Number, Mobile Phone, Home Phone, Account Phone, etc.), the extension can't dial it and quietly skips it. The session ends up smaller than the selection by the number of phone-less contacts.

**Tell-tale sign:** the dial session screen shows an "**X contacts skipped**" message, OR the customer can see in the CRM that some of the missing contacts have empty phone fields.

**Resolution:**
- Add phone numbers to those contacts in the CRM and relaunch, OR
- Filter the list to exclude contacts with empty phone numbers before selecting.

**For HubSpot specifically:** if the customer has Settings → **Primary Phone Field** set to a specific field like "Mobile Phone Number", any contact without a value in THAT exact field will be skipped — even if it has a value in another phone field. Setting Primary Phone Field to **Default (first available)** widens the search to any phone-typed field on the contact.

### Cause C — Duplicate phone numbers (PhoneBurner removes them)

PhoneBurner has an account-level setting "Don't add duplicate phone numbers to the same dial session." When that setting is on (default for most accounts), if multiple selected contacts share the same phone number — for example, five contacts who all list the same company's main line — PhoneBurner keeps just one and drops the rest.

**Tell-tale sign:** the contacts that got dropped tend to share something — same employer, same household, same shared assistant phone, or the same toll-free / main line number.

**Resolution:**
- This is intentional behavior in PhoneBurner. If the customer **wants** the duplicates dialed, they can turn the setting off in their PhoneBurner account preferences (PhoneBurner → Account Settings → Dial Session Settings → "Allow duplicate phone numbers in a session").
- Or clean up shared phone numbers in the CRM (e.g., give each contact at the same company their own direct extension if available).

### How to figure out which cause is in play

| Observation | Most likely cause |
|-------------|-------------------|
| Counts vary across attempts on the same list | A — scroll / virtualization |
| Dial session shows "X contacts skipped" message | B — missing phone numbers |
| Dropped contacts all work at the same company or share a phone | C — duplicate phone dedup |
| Count is dramatically lower than expected (e.g., 5 of 100) even with the dropdown | Escalate (see below) |

**Escalate if:**
- Customer uses the **Launch from List dropdown** in the popup (not page-checked selection) AND still gets dramatically fewer contacts than the list has.
- The dropped contacts have phones and don't fit pattern A, B, or C.
- The count is consistently the same wrong number every time (e.g., always exactly 50 out of 100) — that's a different kind of bug.

Ask for: the list name, exactly how they selected (Select all / individual checks / list dropdown), screenshot of the CRM page with the selection visible, and a screenshot of the PhoneBurner dial session summary with the contact count.

---

## 25. Click-to-Call (HubSpot)

**What it is:** A small PhoneBurner flame icon appears next to phone numbers on HubSpot record pages and list views. Clicking it opens a small PhoneBurner softphone window and places a single call to that number — no dial session, no batch. It's for one-off follow-ups where launching a full dial session would be overkill.

**Where it appears:**
- HubSpot contact record pages — one flame icon next to each phone-typed property (Phone Number, Mobile Phone Number, custom phone fields, etc.).
- HubSpot company record pages — same.
- HubSpot contacts and companies list views — one flame icon next to each row's phone value.

The icon does NOT appear on non-HubSpot CRMs yet (Close, Apollo, Pipedrive, etc. planned for future releases).

### First-time microphone prompt

The very first time a customer clicks a flame icon in a new browser profile, Chrome will prompt to allow microphone access. This is Chrome's normal WebRTC permission flow — the softphone needs the mic to place a call. After the customer clicks Allow, the permission is remembered per-origin and they won't see the prompt again.

**Symptom:** Customer clicks the flame, softphone window opens, then hangs at "Setting up call…" or fails.

**Likely cause:** Chrome mic permission was dismissed / blocked on the first prompt.

**Resolution:**
1. Ask the customer to close the softphone window
2. In Chrome, click the lock icon in the address bar for the softphone page → set Microphone to Allow
3. Retry the click-to-call

### The Click-to-Call button doesn't appear

**Common causes and fixes:**

| Symptom | Cause | Fix |
|---|---|---|
| No flame icon anywhere on HubSpot | Customer turned it off in Settings | Popup → Settings → "Show click-to-call buttons on CRM phone fields" → turn back on |
| Icon appears but the phone number column is too narrow | List-view column width | Widen the Phone Number column in HubSpot until the icon has room |
| Icon appears on Phone Number field but not Mobile / Home | Only should happen on very old versions of the extension | Confirm extension is v0.8.0 or newer; if so, escalate with a screenshot of the contact showing multiple phone fields |
| Two "call this number" buttons on every phone (ours + another dialer's) | Customer has RingCentral or another dialer extension also installed | Popup → Settings → turn OFF "Show click-to-call buttons on CRM phone fields" to keep only the other one, OR remove the other dialer to keep ours |

### Calls placed via click-to-call

- **Logging in HubSpot**: calls placed via click-to-call are logged to the contact's HubSpot record through the same PhoneBurner ↔ HubSpot integration that handles dial sessions. Customer needs the native PB↔HS integration activated in PhoneBurner's admin — same requirement as dial sessions.
- **What if the customer isn't set up with the native PB↔HS integration?** The call still connects, but the activity won't appear in HubSpot. Direct them to https://www.phoneburner.com/myaccount/integrations/hubspot/index to activate it.
- **Recording, disposition, call notes**: same as dial session calls — the softphone in the popup window has the same UI as PhoneBurner's regular dialer.

### Escalate if

- Softphone window opens but stays blank / white screen for more than 5 seconds
- Mic permission is granted but the call still won't connect
- Calls dial but never appear in HubSpot even though the customer has the native integration activated
- Ask for: extension version, HubSpot portal ID (from any HubSpot URL), timestamp of the attempt, screenshot of the softphone window state

---

## 26. AgencyZoom

**What it is:** The extension detects AgencyZoom's task list page (`app.agencyzoom.com/task/list`) and adds a "Scan & Launch Dial Session" affordance in the popup. Reps working through their AgencyZoom task queue can launch a PhoneBurner dial session for every visible task's lead in one click.

**Key behavior:**

- **One dial-session contact per lead, not per task.** AgencyZoom shows one row per open task, so a lead with 5 open tasks shows as 5 rows. The extension collapses those to a single dial-session contact (using the lead's phone number). The customer dials the person once, not five times.
- **If the customer checks specific task-row checkboxes** (top-left of each row) BEFORE clicking Scan & Launch, the extension only includes those rows in the scan. If nothing is checked, all visible rows are scanned.
- **Tasks do NOT auto-complete after a call** (AgencyZoom has no public API we can use for that) — but the Follow widget lands the rep on each contact's lead page during the call, and the open task on that lead page carries **"Mark Completed"** and **"Mark Completed + Note"** buttons right on the activity. The rep clicks Mark Completed (or + Note) as the call wraps and moves on. Result: task closure fits naturally into the flow without needing to leave the record.

**Common questions and fixes:**

| Symptom | Cause | Fix |
|---|---|---|
| No "Scan & Launch" card in popup on AgencyZoom | Not on `/task/list` | The scanner only runs on the task list page. Other AgencyZoom pages (lead records, dashboards) aren't currently supported. |
| Fewer contacts than expected in the dial session | Multiple tasks per lead got deduped (working as intended) | Explain the "one contact per lead" collapse. If customer wants every task as a separate contact, that's a feature request. |
| Contact missing entirely | The row had no visible phone number in the Phone column | AgencyZoom shows the lead's stored phone. If the phone field is empty, we can't dial. Add a phone to the lead in AgencyZoom. |
| Follow widget doesn't open the correct AgencyZoom lead on next contact | Fixed in v0.8.0 (the record-ID-in-query-string bug) — earlier versions had this issue | Confirm customer is on v0.8.0 or newer. |

**Escalate if:**
- Task list has ~N visible tasks but the scan returns <<N distinct leads (dedup is over-collapsing)
- Scan finds contacts but the dial session opens with 0 records
- Follow widget lands on the wrong AgencyZoom lead when navigating between calls

Ask for: URL of the task list (with any filter/sort visible), extension version, count expected vs. count actually in the PhoneBurner session.

---

## Escalation Contact

When escalating to PhoneBurner engineering:
- Provide the **customer's PhoneBurner email/account**, the **CRM name**, **extension version** (visible at the bottom of the popup header), the **exact symptom**, and any **screenshots or URLs**.
- For data integrity issues (wrong phone dialed, missing call logs): include the **approximate timestamp** so engineering can trace via server logs.

---

## Appendix: Quick Reference for AI Agents

### Decision tree for "extension isn't working":

```
Is PhoneBurner status "Connected" in the popup header?
├── No → Go to [PAT errors](#4-pat-errors)
└── Yes →
    Is the CRM name shown correctly?
    ├── No → Go to [CRM not detected](#5-crm-not-detected)
    └── Yes →
        Are launch buttons visible?
        ├── No → Go to [Missing launch buttons](#6-missing-launch-buttons)
        └── Yes →
            Does Launch produce an error?
            ├── "No dialable contacts" → Go to [No dialable contacts](#7-no-dialable-contacts)
            ├── Permission dialog stuck → Go to [Stuck permission dialog](#8-stuck-permission-dialog)
            └── Session starts but widget missing → Go to [Follow widget missing](#9-follow-widget-missing)
```

### Key phrases to listen for:

| Customer phrase | Most likely issue |
|-----------------|-------------------|
| "popup is empty/blank/loading" | [Emergency reset](#21-emergency-reset) or [Installation](#1-installation) |
| "won't save my token" / "rejected" | [PAT errors](#4-pat-errors) |
| "doesn't see my CRM" | [CRM not detected](#5-crm-not-detected) |
| "no button to click" | [Missing launch buttons](#6-missing-launch-buttons) |
| "session has wrong number" | [Wrong phone number dialed](#16-wrong-phone-number-dialed) |
| "calls aren't showing up in [CRM]" | [Call logging issues](#17-call-logging-issues) |
| "task queue" / "tasks not completing" / "reconnect HubSpot" | [HubSpot Task Queue dialing](#22-hubspot-task-queue-dialing) |
| "why is Close asking me to reconnect" / "after the v0.7.0 update" | [Reconnecting after the v0.7.0 upgrade](#23-reconnecting-after-the-v070-upgrade) |
| "only got 500 contacts" / "session was cut off" | [500-contact limit](#18-500-contact-limit) |
| "HubSpot changed my number" | [HubSpot Data Sync conflict](#19-hubspot-data-sync-conflict) |
| "browser froze" / "Chrome stuck" | [Stuck permission dialog](#8-stuck-permission-dialog) |

### Do **not** suggest these (common bad fixes):

- ❌ "Clear all browser data" — destroys other Chrome state, unnecessary.
- ❌ "Reinstall Chrome" — extreme; reinstall the extension instead.
- ❌ "Use a different browser" — the extension only supports Chromium-based browsers; suggesting Firefox/Safari is wrong.
- ❌ "Disable other extensions" — rarely the cause; only suggest if multiple resets failed.
- ❌ Manually editing `chrome.storage` from DevTools — risk of corrupting state; use the in-popup Disconnect buttons.

---

**Last updated:** 2026-06-25
**Documents features through extension version:** 0.7.0 (some features may not appear in older installed builds)
**Maintained by:** PhoneBurner engineering. To request changes, contact the extension team.
