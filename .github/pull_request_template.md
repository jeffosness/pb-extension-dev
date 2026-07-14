<!--
  Thanks for contributing! Fill in the sections below.
  CI enforces required sections based on your PR's risk tier and the files
  you touched — the KB, Security, Test, and Risk Tier check workflows will
  fail the PR if something's missing or inconsistent.
-->

## Summary

<!-- 1–3 sentences describing what this PR does and why. -->

## Risk Tier

<!--
  CI auto-labels this PR with `risk:tier-N`. Reference:
  - Tier 0 — docs, dashboard, tests, tooling, marketing, changelog. Ships freely.
  - Tier 1 — extension code (background/content/popup/crm_config/softphone_config),
             popup.html, manifest.json, most api/ endpoints, softphone.php,
             softphone_host.js. Requires "Adversarial Review" below.
  - Tier 2 — security-critical: utils.php, api/core/bootstrap.php, webhooks,
             sse.php, config.sample.php, oauth_* endpoints, *_call_logger.php,
             SECURITY.md. Requires Adversarial Review; the 4-hour cool-off
             applies at PRODUCTION DEPLOY (prod-* tag push), NOT at merge.
             Merging is fine — main auto-deploys to dev for soak-testing.
             Cut the prod tag once the freshest Tier-2 commit has been on
             dev for 4h. Emergency override: suffix the prod tag with
             `-hotfix`, `-urgent`, or `-rollback`.
-->

## KB Impact

This PR's effect on the customer-facing KB (`KB_EXTENSION_TROUBLESHOOTING.md`).
**Check exactly one box.** CI will fail the PR otherwise.

- [ ] **No customer-visible change** — refactor, infra, internal tooling, dev-only docs, etc. KB does not need an update.
- [ ] **Customer-visible change — KB updated in this PR** — `KB_EXTENSION_TROUBLESHOOTING.md` has been edited as part of this PR.
- [ ] **Customer-visible change — KB follow-up tracked** — paste the follow-up issue/PR link here: `<link>`

### What is a "customer-visible change"?

A change a customer or support rep might notice or ask about. Examples:

- New CRM provider, new launch path, new setting, new error message
- Renamed buttons or status text in the popup or Follow widget
- Behavior changes in dial session creation, call logging, follow-me, or OAuth flows
- New permissions, new domains, changes to data handling or privacy
- Changes to webhook payloads or PhoneBurner record fields a customer could see in their CRM (e.g., PR #86 — silently changed what shows up in `external_crm_data`)

If unsure, treat it as customer-visible and update the KB or open a follow-up.

## Test Impact

This PR's effect on automated tests. **Required if this PR touched `server/public/utils.php`, `server/public/api/core/bootstrap.php`, anything under `tests/`, `composer.json`, `composer.lock`, or `phpunit.xml`.** For PRs that don't touch those files, the section is optional (leave it or delete it — CI won't fail).

**Check exactly one box if the section is required.**

- [ ] **No new testable behavior** — refactor, docs, config, formatting, or purely UI change. No PHP logic changed that would benefit from a new test.
- [ ] **Tests added or updated in this PR** — see `tests/` in the diff.
- [ ] **Test follow-up tracked** — paste the follow-up issue/PR link and a one-line rationale for deferring: `<link>` — <why>

See [TESTING.md](../TESTING.md) for what's covered today, how to run locally, and the follow-up test targets.

## Adversarial Review

<!--
  REQUIRED for Tier 1 and Tier 2 PRs. Delete this section for Tier 0.

  Argue against your own change. Flip your frame from "help this succeed"
  to "find the flaw." What's the most likely way this ships wrong?

  Fill in at least two lines of substantive content — placeholder HTML
  comments don't count. CI enforces this for Tier 1+.

  Examples of good adversarial questions to answer:
    - What assumption am I making about the environment that could be wrong?
    - What edge case did I NOT test?
    - What upstream code depends on this that I haven't traced?
    - What happens if my change runs against unexpected input shape?
    - What if the third-party API/DOM I'm coding against changes?
    - Compensating factor — why we're proceeding anyway despite the above.
-->

## Post-Deploy Verification

<!--
  REQUIRED if this PR changes production behavior (Tier 1 or 2 that touches
  something a customer or the dashboard will observe). Delete this section
  if the PR is docs-only or Tier 0.

  List the SPECIFIC checks you (or a future teammate) will perform within
  24 hours of the prod tag going out. Written down so nothing is skipped
  and so onboarding contributors know what "confirming a deploy worked"
  looks like in practice.

  Examples:
    - [ ] Refresh `extension.phoneburner.biz/metrics/crm_usage_dashboard.php`
          and confirm the "Anomalies" count reflects the expected change.
    - [ ] `sudo grep '<pattern>' /opt/pb-extension/var/log/app.log | tail`
          shows the expected shape.
    - [ ] Trigger a click-to-call and disposition it; confirm both events
          reach the dashboard.
    - [ ] Rollback plan if the above fails: `git tag prod-vX.Y.Z-rollback
          <previous-good-commit>` and push.
-->

## Test Plan

<!-- Bulleted checklist of what was manually tested (as opposed to automated tests above). -->
