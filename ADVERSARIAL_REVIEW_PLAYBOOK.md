# Adversarial PR Review — the playbook

The method behind the reviews that have been catching high-impact bugs other reviewers miss. Copy the prompts; keep the process.

## The one-sentence version
Run **two independent hostile reviewers** (different model families) who must **prove every finding with `file:line` + a concrete failure scenario**, seed them with the **system's known failure classes**, make them **verify against live state (not the diff or the PR text)** — then **adjudicate every claim yourself before it reaches a human**, killing what you can't reproduce.

## Why it works (don't skip this)
- **Hostility + an evidence bar** kills vague findings. The persona line that does the work: *"a false accusation embarrasses you — only report a real problem with a concrete failure scenario."*
- **Two independent models** (e.g. Claude + Codex) have **uncorrelated blind spots**. Convergence = high confidence; divergence = look harder.
- **Adjudication is the secret sauce, not the agents.** The agents produce candidate findings, some wrong. Value comes from re-verifying each against live code and dropping the unverifiable. Without it you get confident noise, and people stop reading reviews.

## The process (7 steps)
1. **Fetch ground truth.** The diff, the full files on the branch (`git show origin/<branch>:<path>`), and the *live* state the change depends on — `gh api` for branch protection, live PyPI for a dep, the actual RLS/GRANT on a referenced table, the current-main body of an RPC being replaced. **Never trust the PR description or the diff alone.**
2. **Calibrate the lens to the artifact:**
   - *Code* → can it break prod / corrupt data / leak?
   - *Docs* → does it mislead, drift from what shipped, or falsely certify?
   - *Operating contract / runbook* → an agent will EXECUTE this; can any instruction cause harm or bypass a control?
3. **Seed the reviewers** with the system's **known failure classes** (below) and its invariants (e.g. "all business logic in one place / one source of truth"). Past incidents are the reviewer's regression suite.
4. **Run 2+ independent hostile passes with DIVERSE lenses** (don't run identical personas — assign different angles so they don't converge on the same blind spot):
   - security / authz surface
   - data-integrity & blast radius
   - **silent failure & observability** (what returns empty-as-success? what fails with no alarm?)
   - single-source-of-truth / duplication
   - the "does the fix address the cause or just the symptom, and does it introduce a new bug" lens
5. **Adjudicate.** Re-verify EVERY finding against the code yourself. Label each **CONFIRMED** (you reproduced it) vs **PLAUSIBLE** (agent-claimed, unverified). Drop or downgrade what doesn't hold. Track false positives to calibrate.
6. **Report** with severity (BLOCKER / MAJOR / MINOR / NIT), evidence, failure scenario, concrete fix — most severe first — **and a coverage statement**: what you checked, and what you could *not* verify (so a clean review isn't over-trusted).
7. **Re-review the fix hostilely too.** A fix can address the symptom not the cause, or introduce a new bug. Verify the fix reached `main` and that a regression test now guards the class.

## The hostile-reviewer prompt (copy this)
> You are a [30-year domain expert — e.g. telecom billing-systems auditor + database engineer] doing a HOSTILE code review. Your conviction: the author shipped fast with AI and got something wrong, and you will prove it with EVIDENCE (`file:line`). Be harsh — but a false accusation embarrasses you, so only report a REAL problem with a concrete failure scenario. READ-ONLY: do not modify or merge anything.
> Verify claims against the actual branch and live state, not the diff or the PR description.
> Known failure classes for this system to check explicitly: [list].
> Your lens: [assign ONE — security / data-integrity / silent-failure / one-brain / fix-quality].
> Output: numbered findings, most severe first — SEVERITY (BLOCKER/MAJOR/MINOR/NIT), evidence (file:line), failure scenario, concrete fix. End with a per-PR verdict (MERGE / MERGE AFTER FIXES / BLOCK) and a coverage statement (what you could not verify).

## Known failure classes — ARMOR (generic regression checklist)
Seed every review with these; they're the incidents this program has actually hit across projects:
- **Identity by mutable name, not immutable ID** (cross-customer exposure).
- **Cross-field contradiction** shipped to customers (e.g. "cleared" while still flagged).
- **Write-path divergence** — a write updates the audit table but not the table the UI/consumer reads (false success).
- **Silent suppression / empty-as-success** — a gate or query returns nothing and it's read as "all good" (e.g. #700: coverage gate read the wrong field → 17% → suppressed every email, no alarm).
- **`REVOKE ... FROM anon` no-op** — EXECUTE defaults to PUBLIC and anon inherits it; must revoke `anon, PUBLIC`.
- **Stale-paste of an RPC body** — re-`CREATE OR REPLACE` from an old copy silently reverts the latest fix.
- **Destructive migration** — cascading delete destroys evidence, or a no-`ON DELETE` FK aborts the release.
- **Doc drift** — the plan-of-record certifies a safety posture the shipped code no longer has.
- **Approval integrity** — material change pushed onto a stale approval; guardrail assumed but not enforced by branch protection.

## Improvements folded into this version
1. **Live-state verification is now step 1**, not ad hoc — most real findings hinged on state outside the diff.
2. **Diverse lenses** across the parallel passes (was: identical personas) — cuts correlated blind spots.
3. **Silent-failure/observability is a first-class lens** — the highest-impact ARMOR bugs suppress or lie quietly.
4. **A coverage/couldn't-verify statement is mandatory** — a clean review must state its scope so it isn't over-trusted.
5. **CONFIRMED vs PLAUSIBLE labels** — separates what was reproduced from what was asserted.

---

## Known failure classes for THIS repo (pb-extension-dev)

Seed every review of a Tier 1+ PR with these. They're drawn from [LESSONS.md](LESSONS.md) — real incidents this repo has actually hit. When you brief a hostile reviewer, paste the specific ones that could plausibly apply to the PR's blast area.

- **Shallow error handlers copy-pasted across integrations** — N endpoints, N missed opportunities to log the provider's error body. See LESSONS.md 2026-07-27. The adversarial pass on that PR found the sweep itself missed 3 more sites — BLOCKER caught pre-merge.
- **Undefined-variable dangling refs after a refactor** — deleting a local assignment (`$t0 = ...; $pb_ms = ...`) but leaving downstream references. Under PHP 8 these emit warnings on every success and silently break stability contracts (e.g. `pb_ms: null` in the response JSON). Caught in the 2026-07-27 adversarial pass.
- **Docs-as-guardrail without a CI counterpart** — CLAUDE.md checklist items drift because there's no mechanical enforcement. Repeat class: LESSONS.md 2026-07-09 (whitelist checklist item hit a repeat regression), 2026-07-27 (adversarial-review process was "fill in a section" self-review, missed a BLOCKER).
- **Silent suppression / empty-as-success on dashboards** — LESSONS.md 2026-07-08 (CRM Distribution silently included non-user event types after CTC events landed in the same log); 2026-07-23 (level:0 pollution from webhook writes in user-behavior aggregations).
- **PHP `?>` inside `//` comment terminates PHP mode** — a fresh prod deploy served source code because a comment referenced PHP close-tag syntax literally. LESSONS.md 2026-07-17. When reviewing PHP that quotes syntax in comments, grep for close-tag inside line comments.
- **Payload-shape guess for a new webhook** — assumed the shape from a neighbor. Real webhook uses different keys, entire dashboard dimension logs as null. LESSONS.md 2026-07-03. Verify against a captured live payload before shipping.
- **Cool-off gate at the wrong boundary** — a Tier-2 gate at PR-merge blocked the dev-testing path itself. LESSONS.md 2026-07-08. Any new gate must be traced against the actual deploy pipeline end-to-end before shipping.
- **Whitelist drift after a new call site is added** — new endpoint reads a token, dashboard anomaly rule doesn't know about it, false-positive alarm the morning after prod deploy. LESSONS.md 2026-07-09 (repeat).
- **Virtualized DOM under-fetch** — HubSpot's IntersectionObserver rows not in the DOM until scrolled. One-shot `querySelectorAll` sees only viewport. LESSONS.md 2026-07-22. Any DOM-scrape code on HubSpot lists must scroll-and-harvest, not one-shot.
- **PB drops arbitrary custom_data on softphone dial** — reverse-engineered webhook contracts have unlabeled fields that look like they're ours to populate but aren't. LESSONS.md 2026-07-08. Verify with a live capture, not by pattern-matching from an adjacent field.
- **crm_name is a stability contract** — renaming an existing crm_name orphans every PB record matched by that string. Never rename a crm_name after it ships.
- **contacts_map key must match crm_id** — divergence breaks webhook lookup and follow-me navigation silently. See CLAUDE.md's session-state invariants.
- **Session file permissions inconsistency** — sessions written 0660 (group-readable) while token files are 0600 (owner-only). See SECURITY.md. Any code writing session state should use `atomic_write_json()`.
- **Deploy-verification too loose** — curl'ing the version endpoint doesn't tell you a NEW endpoint works. Any PR adding an endpoint must curl that specific URL post-deploy. LESSONS.md 2026-07-17.

---

## How to run this playbook in Claude Code

When the task calls for a hostile review (any Tier 2 PR — see CLAUDE.md Risk-tier gates), invoke it like this:

1. **Spawn 3–4 parallel agents via the Agent tool**, one per lens. Use `subagent_type: general-purpose` and `run_in_background: true` so they can work in parallel. Copy the hostile-reviewer prompt above into each; assign a different lens (security / data-integrity / silent-failure / fix-quality) to each so their blind spots don't converge. Seed each prompt with the specific known-failure-classes above that plausibly apply to the PR's blast area, plus the full file list of what changed.
2. **Wait for all to return.** Do NOT read the JSONL transcript files directly (they'll overflow context). Each will notify inline when complete.
3. **Adjudicate every finding.** For each, grep or Read the actual file/line to reproduce the claim yourself. Label CONFIRMED (you reproduced it) vs PLAUSIBLE (asserted, not fully verified). Drop or downgrade anything you can't reproduce.
4. **Report to the user** with a merged table: severity, reviewer, status. Fix BLOCKERs and MAJORs before merge. Ship MINORs / NITs as same-PR if cheap, or as follow-up.
5. **When you push fixes for BLOCKERs, re-run at least one hostile pass on the fix.** Fixes have their own regression class (step 7 of the process above).

The playbook takes about 15 minutes of parallel agent-work. On its first application in this repo (LESSONS.md 2026-07-27) it caught a BLOCKER that two solo review passes missed. **For Tier 2 PRs it is not optional — CLAUDE.md's Risk-tier gates require it.** For Tier 1 PRs it's strongly encouraged but not gated.

## When self-review is enough (Tier 0 / trivial PRs)

Not every PR needs the full playbook. For Tier 0 (docs, dashboard, changelog, marketing) and trivial Tier 1 changes (a single-line bug fix, a rename, a comment update), the PR-body "Adversarial Review" section — a real 2–5 line stab at "what could break?" — is sufficient. Reserve the parallel-hostile-agent process for changes where a BLOCKER hitting prod would actually cost customers: shared helpers, security-critical files, auth/OAuth flows, cross-integration abstractions, anything that fans out to many callers. When in doubt, run it — 15 min of parallel work is cheaper than a bad prod deploy.
