# Lessons Learned

An append-only log of production incidents, near-misses, and diagnostic dead-ends that shaped the process gates in this repo. Each entry captures what happened, why we didn't catch it earlier, and the concrete process change (if any) that came out of it. The point is not to assign blame — it's to make sure the next contributor (human or AI) inherits the reasoning behind the rules in [CLAUDE.md](CLAUDE.md), [SECURITY.md](SECURITY.md), and the CI workflows, without having to re-derive it from scratch.

Ordered newest-first. When adding a new entry, use the template at the bottom of this file.

---

## 2026-08-02 — Diagnostic instrumentation took FOUR sweeps to close; the pattern is now formally named

**What happened:** Jeff local-tested v0.8.4 on dev after PR #190 merged, hit an OAuth `PAT validation failed` error, screenshot showed the new Support ID (`6893d68645a5`), SSHed into the dev VPS, grepped `/opt/pb-extension-dev/var/log/api.log`, found the entry — and it was **still shallow**. The `oauth_pb_save.php` endpoint had never been in any of the three prior audit sweeps for shallow error handlers (the initial PR #190 sweep, the audit-sweep follow-up, and the adversarial review of that follow-up). Fourth sweep started with a proper full audit (an Explore agent enumerating every callsite of `pb_api_call` / `http_post_form_info` / `curl_exec` / all provider-specific helpers). Found 15 more sites. Instrumented all of them. Ran the adversarial-review playbook. Two hostile reviewers converged on THREE more sites the fourth sweep still missed:

1. `apollo/pb_dialsession_from_tasks.php` (BLOCKER — mirror of `apollo_sequence_tasks.php` which the fourth sweep DID fix)
2. `hubspot/pb_dialsession_from_list.php` (MAJOR — HubSpot list-membership fetch)
3. `hs_helpers.php` — `hs_fetch_tasks_by_ids` batches, `hs_discover_phone_properties`, `hs_resolve_contact_ids_map_from_objects` (MAJOR × 3)

**Plus a NEW BLOCKER the reviewers surfaced:** direct `api_log(...)` calls in `close_call_logger.php:92` and `apollo_call_logger.php:89` (introduced in PR #190) would fatal-error in the webhook context because webhooks intentionally don't load `bootstrap.php` (they avoid its HTTP-response setup). The bug lay dormant only because no Close/Apollo token had needed refresh during a webhook fire in the dev soak window. My new `log_api_failure_from_tuple` had a `function_exists('api_log')` guard — but that made the log entries silently no-op in webhook context, meaning the entire hs_call_logger diagnostic capture was dead code.

Then a fix-verification (playbook step 7) hostile review surfaced ANOTHER three MAJORs: missing batch-summary for `hs_fetch_tasks_by_ids`, missing instrumentation on `close/pb_dialsession_selection.php` lead→contact resolution, and no PII redaction in the webhook-context fallback of `_pb_write_api_log` (a latent leak vector for future callers).

**Total sites instrumented by the time the class was closed: 24. Sites the initial audit found: 15. Sites the four sweeps collectively missed (later caught by adversarial review): 9. Ratio of hostile-review catches to solo-audit catches: 9:15.**

**Why we kept missing:** Every solo audit had the same failure mode — grep-based enumeration missed patterns that don't match the grep. The initial audit grepped for `pb_api_call` and missed `http_post_form_info`. The follow-up audit added `http_post_form_info` and missed the `list($code, $json, $_raw)` throw-away idiom used by the provider-specific helpers (`hs_api_get_json`, etc.). The fourth-sweep Explore-agent audit covered all those and STILL missed the two customer-facing dial-session launch paths (`apollo/pb_dialsession_from_tasks.php`, `hubspot/pb_dialsession_from_list.php`) — because those files USE `pb_dialsession_or_fail` for the PB call and the audit noted them as "already covered" — while their preceding provider-fetch calls (before the PB call) were shallow.

**Process change — this class of failure needs a CI check, not another audit:**

1. **New CI workflow (follow-up PR):** grep-based lint that fails any PR introducing `list(\$code,\s*\$json,\s*\$_?raw)` or `curl_exec\(` without a same-PR reference to `describe_api_failure` / `log_api_failure_from_tuple` in the diff. Every prior sweep would have caught its own misses with this check. Tracked as the immediate follow-up.

2. **CRMS.md** already documents the required helpers with the exact patterns to copy. The pattern doc is only useful when contributors USE it, but with the CI check enforcing "if you touched an external-API failure branch, you must have touched the helper" it becomes mechanical.

**Meta-lesson: after N sweeps, if the class keeps producing new instances, stop sweeping and add a mechanical check.** The 2026-07-09 LESSONS entry made the same argument for the token-whitelist class. Same argument applies here. Documentation guardrails on their own aren't enough; the four-sweep evidence is now on the record.

**Broader lesson — the fix-verification review (playbook step 7) caught a NEW BLOCKER the initial adversarial pass had introduced.** The fix for the original BLOCKER (dangling `$pb_ms` refs) landed cleanly. But the fix's fix — adding a webhook-safe `_pb_write_api_log` — introduced its own PII-leak vector that only a re-review caught. Step 7 of the playbook exists for exactly this. Don't skip it, even when the primary review already reported findings.

---

## 2026-07-28 — Our "adversarial review" gate was a documentation prompt, not a hostile-review process

**What happened:** During the 2026-07-27 diagnostic-capture PR (Tier 2, touches `utils.php`), Jeff asked me to "take another couple of passes on this to ensure we are doing this right and secure" before deploying. I ran the process from the untracked `ADVERSARIAL_REVIEW_PLAYBOOK (1).md` file — four parallel hostile reviewers with diverse lenses (security / data-integrity / silent-failure / fix-quality), evidence-required, adjudicate against live code. Two reviewers converged independently on a BLOCKER (dangling `$pb_ms` / `$httpCode` refs across all 7 refactored endpoints, silently breaking latency telemetry and emitting PHP-8 warnings on every success path). A third escalated a MAJOR: three additional sibling refresh helpers were still unfixed. All confirmed by adjudication. Fixed pre-merge.

Then Jeff asked the real question: "did we use the untracked playbook, or did we use our process — and if it was our process, why didn't it trigger without me having to ask?" The honest answer: I used the untracked playbook, but only *because* Jeff asked. Our actual documented "adversarial review" gate for Tier 1+ PRs was ["fill in an Adversarial Review section in the PR body ... Argue against your own change"](.github/pull_request_template.md). That's self-review dressed up as adversarial review. The CI check confirms the section exists with ≥2 lines of content — it can't tell whether the section actually did rigorous hostile work by an independent observer. On the 2026-07-27 PR I self-applied that section in the original PR body (`## Adversarial Review — What could break?`), it passed CI, and shipped what I thought was fine. The BLOCKER would have merged to main and hit dev if Jeff hadn't asked for another pass.

**Why we didn't catch it:** The playbook and the process gate were TWO DIFFERENT ARTIFACTS. The playbook (untracked, `ADVERSARIAL_REVIEW_PLAYBOOK (1).md`) said "run multiple independent hostile reviewers with diverse lenses." The process gate (`CLAUDE.md` Risk-tier gates + `.github/pull_request_template.md` Adversarial Review section) said "fill in a PR-body section." Neither doc referenced the other. When earlier reviewing the playbook file, we'd decided we "already had a good process in place" — but that assumed the PR-body section did what the playbook does, and it didn't. Classic doc-drift-plus-optimism failure: a stronger tool existed, wasn't wired to the gate, and the gate quietly let a weaker version take its place.

**Process change:** Same-turn follow-up PR:

1. **Moved the untracked playbook into the tracked repo** as `ADVERSARIAL_REVIEW_PLAYBOOK.md` at the root. Added a repo-specific "Known failure classes for THIS repo" section (drawn from this LESSONS.md) so future hostile reviewers get our actual regression suite as context. Added a "How to run this playbook in Claude Code" section with concrete `Agent`-tool invocation guidance.
2. **Updated CLAUDE.md Risk-tier gates**: Tier 2 PRs now require *executing* the playbook (spawn multiple parallel hostile reviewers, adjudicate, resolve BLOCKERs and MAJORs) — not just filling a PR-body section. Tier 1 keeps the self-review option for non-trivial changes but strongly encourages the playbook for anything nontrivial.
3. **Updated the PR template's Adversarial Review section** to distinguish Tier 1 self-review from Tier 2 playbook-execution, with an inline warning about the 2026-07-27 incident so the next contributor sees the reason.

**Broader lesson — a documentation gate is only as strong as the artifact it references.** "Fill in an Adversarial Review section" is not the same activity as "run a hostile-review process," and if a stronger process exists in the repo but the gate doesn't point at it, the gate quietly settles for the weaker interpretation. Whenever we add a new artifact (playbook, checklist, runbook) that would strengthen an existing gate, the gate itself must be edited in the same PR to reference the new artifact — otherwise the gate keeps enforcing the pre-artifact-era behavior forever. This is the twin of the LESSONS 2026-07-09 lesson ("docs alone don't enforce; make it mechanical") but from the other side: even when a stronger process EXISTS, if the gate doesn't cite it, it might as well not exist.

---

## 2026-07-27 — "PhoneBurner dialsession failed" was un-triageable because we discarded PB's error body

**What happened:** A customer (Kimberly, Supervest) reported the generic "PhoneBurner dialsession failed" alert while launching from a HubSpot contacts list. She'd had successful sessions the same day, so the top suspects (expired PAT, missing HS connection, empty selection) were ruled out immediately — the failure had to be PhoneBurner's `/dialsession` API returning 4xx/5xx. But we couldn't diagnose beyond that: the six inline copies of the failure handler across the L3 dialsession endpoints logged nothing at all (only the generic scan endpoint had an api_log call, and it too stripped the response body), and `pb_api_call` discarded PB's raw response after JSON-decoding. So even with the request timestamp we couldn't tell whether PB returned "You have an active dial session," "PAT is expired," "internal error," or something else. Support triage reduced to guessing.

**Why we didn't catch it:** Six endpoints, six copy-pasted failure handlers that all bubbled up `pb_http` + `pb_ms` and nothing else. Not a bad choice for the first cut when the class of failure was rare, but as call volume grew, the diagnostic gap became load-bearing on customer patience — and this was the first report where the customer explicitly couldn't self-diagnose. The 2026-07-08 dashboard-drift lesson ("adjacent aggregations copy-pasted the same shape and drifted") applies here too: **N copies of a pattern is N chances for the pattern to be wrong; centralize the pattern the first time you catch a smell.**

**Process change:** PR moved all seven dialsession callsites (six L3 + generic scan) onto a shared `pb_dialsession_or_fail($pat, $payload, $endpointLabel, $extraLog)` helper in `utils.php`. The helper:

1. **Preserves PB's raw response body** in `$info['raw_body']` (via a small change to `pb_api_call`) so the failure path can log it.
2. **Extracts PB's own error message** from common shapes (`error.message` / `message` / `error` string) and surfaces it via `api_error` extras as `pb_message`.
3. **Logs a structured breadcrumb** via `api_log('pb_dialsession.error', ...)` including endpoint slug, HTTP code, elapsed ms, PB message, redacted decoded body, raw snippet (only when JSON decode failed), and any per-endpoint context the caller wants to add (client_id_hash, contact_count, etc.).
4. **Extension side** now surfaces `pb_message` in the popup alert (falling back to the generic wrapper message) and appends the first 8 chars of `request_id` as a "Support ID" so a customer report can be traced to the exact server-side log line.

**Broader lesson — "shallow" error handling is fine at the first callsite, and a debt trap by the sixth.** The threshold for centralizing isn't "does the pattern repeat" — it's "when the pattern next fails, will N copies be N times harder to fix?" For error handlers specifically, the answer is almost always yes: the fix is always "add more context," and adding it to one shared helper is trivially cheaper than to six.

**Follow-through audit (same PR):** before deploying the first fix, Jeff asked "let's confirm this class of bug isn't somewhere else." Ran a codebase-wide sweep for external-API failure paths that discard response body — found 6 more sites in the same PR class:

- Close token refresh in `close_call_logger.php` (HOT path — every long dial session)
- Apollo token refresh in `apollo_call_logger.php` (HOT path)
- HubSpot / Close / Apollo OAuth-finish endpoints (setup / reconnect blockers)
- Apollo API key validation in `save_api_key.php` (already logged a 200-byte hint — deferred, marginal improvement)

Bundled the top 5 into the same PR onto a new pure helper `describe_api_failure($info, $rawBody, $decoded)` in `utils.php`. The helper is now the required entry point for any provider-integration failure log — `pb_dialsession_or_fail` composes it, OAuth-finish endpoints call it, call-logger token-refresh sites call it. CRMS.md gained an "Error handling requirements" section that new CRM integrations must follow, and CLAUDE.md's Security Checklist grew a new mechanical item ("if you added an external API call site, log the provider's error text via describe_api_failure — never just the HTTP code").

**Meta-lesson — when you find one shallow error handler and centralize the fix, sweep for siblings BEFORE deploying.** The audit surfaced 6 more instances in the same class. Deploying the first fix in isolation would have left the same triage cost buried in five other integration points, and each one would eventually cost another customer-report cycle. The audit + bundle turned six future incidents into one PR.

**Meta-meta-lesson — the audit ITSELF missed three sites, which four hostile reviewers caught before deploy.** After Jeff asked for adversarial review on this Tier-2 PR, we ran four parallel hostile passes with diverse lenses (security / data-integrity / silent-failure / fix-quality) per [ADVERSARIAL_REVIEW_PLAYBOOK](ADVERSARIAL_REVIEW_PLAYBOOK.md). Two of them converged independently on a BLOCKER (dangling `$pb_ms` / `$httpCode` variables in every refactored endpoint — the refactor removed the assignments but left ~21 downstream references, silently breaking latency telemetry and emitting PHP-8 warnings on every success path). One reviewer escalated a MAJOR: three MORE token-refresh helpers in `hs_helpers.php` / `close_helpers.php` / `apollo_helpers.php` still used the old `http_post_form` and logged only HTTP codes on failure. These are HOT paths — fire on every dial-session launch when a token is stale — and my "sweep" had missed them because it grep'd for `curl_exec` in call loggers but not `http_post_form` in the shared helpers. A separate MAJOR: `body_snippet` was unredacted, so a hypothetical 200-OK + JSON-parse-failure path could land raw access tokens in Loggly.

What the hostile pass added, that solo review missed:
- **BLOCKER:** 21 dangling variable references across 7 endpoints (two reviewers converged).
- **MAJOR:** 3 sibling refresh helpers still un-instrumented (fix-quality lens).
- **MAJOR:** `body_snippet` OAuth token scrub (security lens).
- **MINOR:** 8-char Support ID collision-prone at scale.
- **MINOR:** `describe_api_failure` signature had redundant `$rawBody` param.

The deeper lesson: **documentation-as-guardrail is aspirational without a mechanical enforcement counterpart.** CLAUDE.md's checklist now says "if you added an external API call site, log via describe_api_failure" — and yet the PR that established that rule violated it in three places. Same failure mode as LESSONS 2026-07-09 (whitelist checklist item hit a repeat). Next time we hit a class-of-failure repeat like this, the fix isn't another checklist item; it's a CI grep check that fails PRs introducing `curl_exec` / `http_post_form` / etc. without a same-PR reference to `describe_api_failure`. Tracked as follow-up.

Concretely for future adversarial reviews: this session followed the playbook end-to-end (four diverse lenses, evidence-required, adjudicate-before-report). The BLOCKER was caught because the "data-integrity" and "silent-failure" reviewers both grep'd for undefined-variable references — a mechanical check my solo review never ran. **When the stakes are Tier 2+, always run the playbook. It caught more real bugs in 15 minutes of parallel work than my self-review could have caught in an hour.**

---

## 2026-07-22 — HubSpot Task Queue launched only the first 30 of 91 tasks because rows are IntersectionObserver-virtualized

**What happened:** Patrick reported the Task-Based Dial Session was only picking up "30 of 91 tasks" — the customer discovered that if they zoomed the HubSpot page out to 25%, all 91 rendered and the dial session got everything. Gil also flagged a related shortfall on his Selection launches earlier in the week; both reports pointed at the same virtualization root cause once we lined them up. Root cause: HubSpot's task list uses IntersectionObserver-based virtualization (`data-observer-type="ROW"` attributes on the containers). Rows past the viewport aren't in the DOM at all until scrolled into view. Our `HS_GET_TASK_IDS` handler was a one-shot `querySelectorAll('tr[data-test-id^="row-"]')`, which only sees what's currently rendered. Zoom-out made more rows fit on-screen, so more rendered, so more got dialed — that's the "trick" the customer stumbled onto. Fixed in v0.8.3 by moving Task Queue onto the same scroll-harvest core the Selection flow already used (`hs_deepHarvest`), and adding narrated progress ("Scanning HubSpot for your selected tasks… Found 15 of 22") so the extra scan time is visible instead of a silent spinner.

**Why we didn't catch it:** The Selection flow had `hs_collectSelectedIdsDeep` — a scroll+dedup+harvest loop — since it was written specifically to defeat this same virtualization for contact/company selection. The Task Queue endpoint was added later (PR #142ish, when task-queue dialing shipped) and copy-pasted the "collect all `data-test-id^=row-` rows" pattern without picking up the deep-harvest wrapper. The bug lay dormant because our own testing accounts and Jeff's manual QA typically had task counts that fit in one viewport, so `querySelectorAll` returned everything. Only customers with larger queues hit the shortfall. A "test with a list bigger than one viewport" step in the launch checklist would have caught this, but the class of trap — virtualization defeats naive DOM scraping — has to be named for future code to route through the shared harvester.

**Process change:** Two things.

1. **Extracted the shared harvest core** into `hs_deepHarvest({harvestFn, keyOf, ...})` in content.js. New harvesters (per-object-type row scrapers) now plug into this shared driver instead of reimplementing scroll+dedup themselves. A `console.warn` diagnostic fires when the harvest comes up short of the UI-reported total — future virtualization changes surface in the console instead of quietly launching a partial session.
2. **Narrated progress makes the wait visible.** The scroll harvest can take 5-15s on big lists — silence during that window used to look like a hang. `HS_HARVEST_PROGRESS` messages now drive a live counter in the popup, then a rotating ticker during backend enrichment. Beyond UX polish, this doubles as a diagnostic: if the counter stalls at 30/91, we know immediately that the harvester lost the scroll container, without needing customer console logs.

**Broader lesson — when a UI framework uses virtualization, the DOM at any moment is a subset of the data.** Every "scrape the current DOM" call in a HubSpot code path is suspect until it either (a) drives the scroller itself, or (b) shells out to an API. Rule of thumb: if the target site's list can be zoomed out to render more rows, our scraper is under-fetching.

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
