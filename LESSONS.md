# Lessons Learned

An append-only log of production incidents, near-misses, and diagnostic dead-ends that shaped the process gates in this repo. Each entry captures what happened, why we didn't catch it earlier, and the concrete process change (if any) that came out of it. The point is not to assign blame — it's to make sure the next contributor (human or AI) inherits the reasoning behind the rules in [CLAUDE.md](CLAUDE.md), [SECURITY.md](SECURITY.md), and the CI workflows, without having to re-derive it from scratch.

Ordered newest-first. When adding a new entry, use the template at the bottom of this file.

---

## 2026-07-17 — PHP close-tag in a comment broke a fresh prod deploy; deploy-verification only checked the version endpoint

**What happened:** Shipped a small dashboard feature (auto-refresh the Token Security section — PR #183) that added `api/core/token_summary_lib.php` and `api/core/token_summary_stats.php`. Deployed to prod as `prod-v0.8.2-dashboard-refresh`. Sanity-checked with `curl version.json.php` — returned `0.8.2-dashboard-refresh`, called it done. Two minutes later, curl'd the actual new endpoint out of curiosity and it was returning PHP source code, not JSON. Root cause: I'd written the literal string `<?= htmlspecialchars(...) ?>` inside a `//` comment as an example of what NOT to do in strings. **PHP treats a literal `?>` inside a `//` comment as an actual close-tag** — it terminates PHP mode mid-file. Everything after that line stopped being PHP and became raw text output. `token_summary_stats.php` served source code; the dashboard's Token Security section server-render broke silently because `compute_token_summary()` was never defined. Hotfixed in ~10 minutes as `prod-v0.8.2-dashboard-hotfix`. Total exposure on prod: ~2 minutes.

**Why we didn't catch it:** Two layers of miss.

1. **PHP-syntax gotcha**: `?>` inside a `//` comment is well-documented in PHP's manual but not intuitive — you'd expect the comment to consume the rest of the line. This class of trap only bites when writing about PHP syntax inside PHP files. Rare enough that developers don't reflexively watch for it.
2. **Deploy verification checklist was too loose**: `curl version.json.php` confirms the deploy ran but tells you nothing about whether the code in it actually works. New endpoints have to be curl'd specifically — but PR #183's Post-Deploy Verification section listed "load the dashboard, turn Auto on, wait for two refresh cycles" without a `curl <new-endpoint>` step for a fast, isolated check.

**Process change:** Two small guardrails.

1. **When writing about PHP syntax in comments, use plain English or escape the close-tag**. Added an inline warning comment in `token_summary_lib.php` at the site of the bug so the next contributor tempted to reference PHP syntax there sees the trap named.
2. **Deploy verification for a PR that adds a NEW endpoint MUST include a `curl` against that specific URL** as the first check — before UI-level tests. `curl` fails fast, deterministic, and exposes source-code-instead-of-JSON in a way that a UI test wouldn't for at least seconds. The pull request template's Post-Deploy Verification section already asks for specific checks; the discipline is remembering that "new endpoint" means "curl the endpoint's URL explicitly, not just an unrelated health check."

**Broader lesson — small internal-only surfaces are the perfect place to make these mistakes and learn from them**. The dashboard is admin-only; blast radius was one internal user (Jeff). If the same pattern hits a customer-facing endpoint next time, blast radius could be all customers. The verification-checklist upgrade above applies to every deploy of a new endpoint, not just internal ones.

---

## 2026-07-09 — Anomaly-whitelist drift after CTC-completes-task (repeat of 2026-07-03 pattern)

**What happened:** The morning after PR #172 shipped to prod, the CRM Usage Dashboard flagged two "Endpoint not in the whitelist for hubspot token reads" anomalies for `softphone_auth_code` and `softphone_call_done`. Both are legitimate: my new code in PR #172 added HubSpot-token reads to those endpoints (checking "is HS connected?" before writing an intent, and loading tokens to PATCH the task on webhook fire). Same class of drift as 2026-07-03 (PR #163), where the softphone endpoints needed to be whitelisted for PB token reads. Fixed by adding the two endpoints to the `hubspot` whitelist in `crm_usage_dashboard.php`.

**Why we didn't catch it:** This is a REPEAT of the failure pattern documented on 2026-07-03. The 2026-07-03 entry ended with "any new endpoint that intentionally reads-then-decides on token presence needs to be either whitelisted or restructured." But that guidance lived only in LESSONS.md, not in a checklist adjacent to the code being changed. When I wrote PR #172 I didn't consult LESSONS.md before shipping. Neither did the Adversarial Review section of PR #172 — I audited data-integrity + failure isolation but not "does this add a new (endpoint × provider) token-read pair that the anomaly rule doesn't know about?"

**Process change:** Two layers, so this stops repeating.

1. **PR #175 fixed the immediate whitelist gap** with an inline code comment on the added lines that ties back to this LESSONS entry — future contributors reading that region see the trap named.
2. **CLAUDE.md's Security Checklist** now includes an explicit item: *"If your PR adds a new call site that reads any of `load_pb_token()` / `load_hs_tokens()` / `load_close_tokens()` / `load_apollo_tokens()`, add the endpoint's basename to the matching `$token_read_whitelist` array in `crm_usage_dashboard.php`."* This is the specific mechanical check that would have caught PR #172 at review time.

Broader lesson — **when a class of failure repeats, the fix isn't "another LESSONS entry pointing at the previous LESSONS entry." It's making the check mechanical.** Text guidance in LESSONS.md is background reading; a line item in a security checklist is what gets consulted. If this failure repeats a third time, the next step is a CI check that greps for new `load_*_tokens(` call sites and requires whitelist changes in the same PR.

---

## 2026-07-08 — Cool-off gate was checking the wrong boundary

**What happened:** The Tier-2 cool-off gate we shipped in PR #167 was implemented in `risk-tier-check.yml` at PR merge time — a Tier 2 PR couldn't be merged to main for 4 hours after it opened. When we went to test PR #172 (CTC-completes-task, Tier 2) on the dev backend today, we discovered the gate blocked the entire flow: since `deploy-dev.yml` triggers on push to main, blocking the merge blocked dev-testing itself. The whole point of the cool-off — soak on dev before shipping to customers — got inverted.

**Why we didn't catch it:** When designing the gate (PR #167), we conflated "merged to main" with "deployed to prod." But the pipeline is `merge → dev auto-deploy` and `prod-* tag push → prod deploy`. Those are two different boundaries; the cool-off belongs on the second one. The mistake wasn't caught because we didn't stress-test the gate against the intended flow — we shipped it with the assumption that dev testing already happened locally, ignoring that Jeff's workflow (and any future contributor's) relies on the dev backend for end-to-end pre-prod validation.

**Process change:** PR #173 moved cool-off enforcement from `risk-tier-check.yml` to `deploy-prod.yml`. It now runs when a `prod-*` tag is pushed, walks the diff since the previous prod tag, finds the freshest commit that touched a Tier-2 file, and requires its committer date on main (= dev-merge time) to be at least 4h old. Emergency override moved from PR labels (`hotfix`/`urgent`) to tag suffix (`prod-vX.Y.Z-hotfix` / `-urgent` / `-rollback`) so the escape hatch lives at the same boundary as the gate. Documented in CLAUDE.md's "Risk-tier gates" section. Broader lesson — **when introducing a new gate, always trace the actual pipeline end-to-end to identify the correct enforcement point.** "Where does the change actually reach the customer?" is the anchor, not "where does the commit live?"

---

## 2026-07-08 — PhoneBurner drops arbitrary custom_data on the softphone dial

**What happened:** While designing [#170](https://github.com/jeffosness/pb-extension-dev/issues/170) (CTC-completes-task), we assumed we could pass a `custom_data: { task_id, client_id }` object through the DIAL postMessage to PhoneBurner's softphone and get it echoed back on the `softphone_call_done` webhook alongside the `pb_user_id` / `slug` fields we already see. Confirmed empirically — PB drops everything except the fields it populates itself. The webhook only exposes `pb_user_id` (from the authenticated softphone session) and `slug` (from the softphone registration record). No third-party pass-through.

**Why we didn't catch it:** No documentation of PB's softphone postMessage contract exists in the repo — the fields we send were reverse-engineered from working traffic. The `custom_data` field's presence on the webhook created a false-positive signal that we could add our own keys. The real contract is "PB owns the whole custom_data namespace on the softphone envelope."

**Process change:** PR #172 built a server-side intent bridge instead — `softphone_auth_code.php` writes a `(pb_user_id, phone) → {client_id, task_id, crm_name}` record on CTC-click; `softphone_call_done.php` reads it on webhook fire. FIFO queue on same-key collisions since PB's softphone is single-call-per-agent. The general lesson — whenever a third-party webhook exposes a field with an ambiguous name like `custom_data`, don't assume it's ours to populate; verify empirically or from their docs.

---

## 2026-07-08 — Dashboard CRM Distribution silently drifted from Dial Sessions total

**What happened:** The top KPI on the CRM Usage Dashboard showed 27 dial sessions today, while the CRM Distribution table just below showed hubspot: 16 + close: 16 = 32. Two adjacent widgets, both about "sessions," giving numbers that couldn't both be right. Traced back to a data-source split done deliberately in commit 9db0eac (April 19, 2026): the top card uses SSE unique sessions (complete count), the CRM Distribution uses `crm_usage_stats.by_crm_id` (per-CRM attribution). At the time, `crm_usage_stats` only contained `event_type=dial_session` rows, so both were counting the same thing and the split was invisible. Then PR #135 added `click_to_call` events and PR #164 added `click_to_call_done` events into the same log. The CRM Distribution query didn't filter by `event_type`, so the per-CRM slices silently started inflating with CTC events — the invariant the April fix relied on had been erased by an unrelated feature. Fixed by adding a dial-session-filtered fetch and feeding the section from it, plus renaming the chart title from "Events by CRM" (a hint from the last dev that the label had drifted from the data) to "Dial Sessions by CRM."

**Why we didn't catch it:** Two adjacent numbers on a dashboard that used to reconcile weren't asserted to still reconcile. PR #135 (introducing `click_to_call`) and PR #164 (introducing `click_to_call_done`) both correctly added their new event types to the same log — that was the right architectural call. But neither PR audited existing dashboard queries against that log for the assumption "there is only one event_type." The invariant lived in one dev's head and in a commit message from three months earlier. No CI check would ever catch this class of drift — the code paths in question all pass linting, tests, and every existing gate.

**Process change:** Two things. (1) Added the fix comment inline in `crm_usage_dashboard.php` at the new filtered-fetch site, pointing at this LESSONS.md entry so a future dev wondering "why the dial_session filter here?" gets the whole story. (2) When we ship the CTC-completes-task feature ([#170](https://github.com/jeffosness/pb-extension-dev/issues/170)) it'll add another event type — that PR's checklist needs an item "audit dashboard consumers of `crm_usage_stats.by_crm_id` / `by_user` / `by_object_type` for event_type filter presence." Adding a real CI check that fails PRs adding new event_types when unfiltered dashboard reads exist is technically possible but overkill for the current volume; the checklist item is the right weight.

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
