# Lessons Learned

An append-only log of production incidents, near-misses, and diagnostic dead-ends that shaped the process gates in this repo. Each entry captures what happened, why we didn't catch it earlier, and the concrete process change (if any) that came out of it. The point is not to assign blame — it's to make sure the next contributor (human or AI) inherits the reasoning behind the rules in [CLAUDE.md](CLAUDE.md), [SECURITY.md](SECURITY.md), and the CI workflows, without having to re-derive it from scratch.

Ordered newest-first. When adding a new entry, use the template at the bottom of this file.

---

## 2026-07-08 — PhoneBurner drops arbitrary custom_data on the softphone dial

**What happened:** While designing [#170](https://github.com/jeffosness/pb-extension-dev/issues/170) (CTC-completes-task), we assumed we could pass a `custom_data: { task_id, client_id }` object through the DIAL postMessage to PhoneBurner's softphone and get it echoed back on the `softphone_call_done` webhook alongside the `pb_user_id` / `slug` fields we already see. Confirmed empirically — PB drops everything except the fields it populates itself. The webhook only exposes `pb_user_id` (from the authenticated softphone session) and `slug` (from the softphone registration record). No third-party pass-through.

**Why we didn't catch it:** No documentation of PB's softphone postMessage contract exists in the repo — the fields we send were reverse-engineered from working traffic. The `custom_data` field's presence on the webhook created a false-positive signal that we could add our own keys. The real contract is "PB owns the whole custom_data namespace on the softphone envelope."

**Process change:** PR #172 built a server-side intent bridge instead — `softphone_auth_code.php` writes a `(pb_user_id, phone) → {client_id, task_id}` record on CTC-click; `softphone_call_done.php` reads it on webhook fire. FIFO queue on same-key collisions since PB's softphone is single-call-per-agent. Documented the "PB owns custom_data" constraint inline in `softphone_call_done.php`'s header comment (extending the payload-shape section from PR #165) so the next dev doesn't repeat the assumption. The general lesson — whenever a third-party webhook exposes a field with an ambiguous name like `custom_data`, don't assume it's ours to populate; verify empirically or from their docs.

---

## 2026-07-03 — Softphone webhook payload shape mismatch

**What happened:** PR #164 landed click-to-call disposition tracking (`event_type=click_to_call_done`) on the new `softphone_call_done` webhook. The handler assumed the same envelope as the dial-session `call_done` webhook — reading `payload.agent.user_id`, `payload.disposition`, `payload.external_crm_data`. In reality the softphone webhook sends a completely different shape: `payload.contact.crm_id`, `payload.contact.crm_name`, `payload.custom_data.pb_user_id`. Every logged CTC-done event landed with `member_user_id=null`, `crm_id=null` — the entire dashboard dimension was blank on the first day. Caught by Jeff comparing the real PhoneBurner debug payload to the code.

**Why we didn't catch it:** No stored example of either webhook's payload existed in the repo. The dial-session `call_done` code became load-bearing tribal knowledge; when a new webhook arrived, the natural move was to pattern-match from the neighbor. There was no schema test, no PB fixture, and no "here's what the real payload looks like" comment to correct the assumption.

**Process change:** PR #165 stored the actual captured payloads inline in the file header of both `softphone_call_done.php` and `call_done.php`, with an explicit cross-reference and a "Do NOT cross-pollinate the two" warning. PII-bearing fields are called out separately from safe-to-log fields so future editors don't have to reverse-engineer that either. Real-payload comments become the schema anchor when there's no test fixture available.

---

## 2026-07-03 — Click-to-call generated phantom token anomalies

**What happened:** The v0.8.0 CTC launch immediately triggered "no token found" anomalies on the CRM Usage dashboard. The softphone HTML host page was doing a token existence probe that logged `res:"missing"` for anyone who hadn't yet completed OAuth — which was nearly every early tester. The dashboard's enumeration rule flagged the probes as suspicious traffic.

**Why we didn't catch it:** The dashboard rules were written before the softphone endpoints existed. They implicitly assumed "missing token reads = suspicious," which was true for the endpoints that existed at the time. When we added the softphone endpoints (which legitimately probe for token existence as part of the UI-gating logic), we didn't update the anomaly whitelist.

**Process change:** PR #163 added the softphone endpoints to the anomaly rule's benign-endpoint whitelist. Broader lesson captured in [SECURITY.md](SECURITY.md)'s "Known implementation gaps" — anomaly rules are coupled to the endpoint surface and must be reviewed when new endpoints are added. Any new endpoint that intentionally reads-then-decides on token presence needs to be either whitelisted or restructured to not log `res:"missing"`.

---

## 2026-07-06 — Enumeration anomaly fired on legitimate corporate NAT traffic

**What happened:** Dashboard showed "Possible enumeration" for AWS IP `35.148.27.44` — 30+ missing-token reads across many client_ids from a single source. Initial read looked like enumeration probing; deeper look showed the same IP had many successful (`res:"ok"`) reads across those same client_ids on the same day. Reality: a customer with 6 employees behind a shared corporate VPN, each legitimately reading their own tokens with occasional cache misses.

**Why we didn't catch it:** The rule counted `res:"missing"` events per IP without checking whether the same IP also had `res:"ok"` events. On a naive count basis, corporate NATs and enumeration probes look identical.

**Process change:** PR #166 added a compensating filter: skip the rule for any IP that has at least one `res:"ok"` read in the window. Enumeration probes never succeed; legitimate NATs do. This is now the reference pattern for anomaly rules — a positive-signal check that dampens the negative-signal count. Documented in the dashboard's inline comment so the next reviewer sees the reasoning.

---

## 2026-07-01 — AgencyZoom shipped invisible to customers

**What happened:** PR #141 landed AgencyZoom as an L2 CRM. Code worked end-to-end. But the marketing site, the STORE_LISTING, and the KB were never updated — so from a customer's perspective, the feature didn't exist. Discovered days later during a v0.8.0 release-readiness pass. Caught only because Jeff manually reviewed "what would a customer see."

**Why we didn't catch it:** CLAUDE.md's "Adding L1/L2 Provider" section had four steps — all about the code. Customer-facing surfaces (marketing site, KB, changelog, store listing) were treated as implicit follow-up work. Without a checklist, easy to forget.

**Process change:** PR #152 back-filled the missing coverage and added a **Step 5** to the "Adding L1/L2 Provider" section in CLAUDE.md explicitly listing every customer-facing surface with pointers. PR #162 later added an equivalent L3 Pre-PR Wiring Checklist in [CRMS.md](CRMS.md) covering the L3 case. Filed follow-up #151 for a CI check that extracts each CRM from `crm_config.js` and verifies it's referenced on the marketing site + STORE_LISTING.

---

## 2026-06-30 — CTI conflict blocking customer's click-to-call pills

**What happened:** Customer (member_user_id 1291929843) reported that click-to-call pills weren't rendering on their HubSpot contact pages after v0.8.0 launched. SSH into prod confirmed the customer had both a valid PhoneBurner PAT and an active HubSpot OAuth token stored. All backend probes returned healthy. Feature gate was open. Root cause: customer had Kixie + Aloware CTI extensions installed alongside ours; those extensions inject their own phone-field UI and win the DOM race, hiding ours.

**Why we didn't catch it:** Cross-extension conflicts are outside the code we own. We can't test every combination of CTI tools a customer might install. The failure mode looked like an extension-side bug from the customer's perspective ("nothing happens when I click"), so early triage went into the extension code and backend logs before we asked what other CTI extensions were installed.

**Process change:** No code change (correctly out of scope). Triage script for future CTC-not-rendering reports: (1) verify backend has PAT + OAuth for the client_id, (2) check the finder DOM path via devtools on the customer's actual page, (3) ASK about other CTI extensions before diving deeper. A KB entry covering "click-to-call pills not appearing" that includes the "check for other CTI extensions" step is a candidate follow-up.

---

## 2026-07-02 — Apollo popup probes read as dashboard noise

**What happened:** Dashboard showed sustained Apollo read-token activity even though no customer had completed Apollo OAuth. Initial concern: something was probing our Apollo endpoints. Investigation showed it was just the popup UI checking `apollo_ready` state every time a user opened the popup on an Apollo tab, which happened frequently for a few beta testers.

**Why we didn't catch it:** The dashboard didn't distinguish between "the extension is politely checking connection state" and "someone is actively trying to use the feature." Both generate token reads. On a low-activity endpoint like Apollo (pre-launch), routine UI probes dominate the graph.

**Process change:** No code change — the dashboard is reading the data correctly, we just needed to interpret it correctly. This is a reminder to always ask "what does the extension do on popup open?" before treating token-read volume as user-intent signal. On future dashboards, distinguish `ui_probe` from `active_use` at the event_type level so the graphs don't require interpretation.

---

## Template for new entries

```markdown
## YYYY-MM-DD — Short title

**What happened:** One paragraph. Plain description of the bug/incident/near-miss. Include the customer-visible or dashboard-visible symptom, the actual root cause, and the PR number(s) that fixed it. If it's an ongoing issue, say so.

**Why we didn't catch it:** One paragraph. The gap in our process, tests, docs, or CI that let this ship. Be specific — "we didn't have tests" is less useful than "we didn't have a schema fixture for webhook X, so the natural move was to pattern-match from webhook Y."

**Process change:** One paragraph. The concrete thing we changed (or decided NOT to change) as a result. Link to the PR, the file, the CI workflow, or the CLAUDE.md section that captures the new rule. If we decided not to codify a change (e.g., customer-environment issues outside our code), say that too — future contributors deserve to know the case was considered.
```

Keep entries short. If an incident needs long analysis, that goes in the PR description; this file is the index.
