<!--
  Thanks for contributing! Fill in the sections below.
  The KB Impact section is required — CI will fail the PR if it's missing or inconsistent.
-->

## Summary

<!-- 1–3 sentences describing what this PR does and why. -->

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

## Test Plan

<!-- Bulleted checklist of what was tested. -->
