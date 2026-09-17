// tools/touch-plan/data.js
//
// Data model for the Touch Plan viewer (viewer.html). Loaded as a plain <script>
// (assigns window.TOUCH_PLAN) so viewer.html opens by double-click on file://
// without a server or fetch/CORS issues. The touch-plan skill can emit this
// exact shape per feature; drop it next to viewer.html and open.
//
// Concern scale is 0–2 (matches the project's risk-tier vocabulary):
//   0 = de minimis · 1 = moderate · 2 = significant

window.TOUCH_PLAN = {
  title: "HubSpot dial-pad — phone → contact resolver",
  change:
    "Add a HubSpot-only, dev-gated dial pad: type a number → resolve it to the right HubSpot contact (search → picker on multi-match → prompt-to-create on no-match) → launch click-to-call against that record so PhoneBurner logs the call via external_crm_data.",
  branch: "feature/hubspot-dial-pad-resolver",
  overallConcern: 2, // max dimension
  ciRiskTier: 1,     // no Tier-2 pattern touched; the 2 is impact-driven

  // 0–2 per dimension, with a one-line justification citing the driving file.
  dimensions: [
    { key: "Data", score: 2, note: "hs_create_contact.php writes a new contact into the customer's HubSpot (system of record); resolved id flows into external_crm_data that PB logs against." },
    { key: "Interface", score: 1, note: "New popup dial pad + picker/create prompts + new DIALPAD_* message types — all additive, dev-gated." },
    { key: "Logic", score: 1, note: "New resolve/create logic in hs_helpers.php, additive; search strategy already settled empirically." },
    { key: "Permissions", score: 0, note: "No new host permissions; contacts.write already in our OAuth scope — no rescope." },
    { key: "Compliance", score: 1, note: "New 'dial any typed number' surface (TCPA/consent differs from a CRM list); create-contact warrants a store-listing/privacy note at launch." },
    { key: "Dialing", score: 1, note: "Initiates real outbound calls via the proven CTC/softphone path (additive); logging hinges on the resolved id + crm_name='hubspot'." },
    { key: "Billing", score: 0, note: "Calls bill like any dial; no new billing logic." },
  ],

  files: [
    { path: "server/public/api/crm/hubspot/hs_create_contact.php", isNew: true, why: "No-match 'create & dial' path", what: "Creates a contact in the customer's HubSpot; sets hubspot_owner_id via hs_ensure_owner_cached(); reads + writes", blast: "0 callers (new); WRITE to system-of-record", dims: ["Data", "Dialing", "Compliance"], impact: 2, rollback: "hard — created CRM records are not auto-reverted" },
    { path: "server/public/api/crm/hubspot/hs_resolve_by_phone.php", isNew: true, why: "Wraps hs_search_contacts_by_phone()", what: "Returns {matches:[…]} for the picker; reads HS token", blast: "0 callers (new); reads tokens → whitelist", dims: ["Interface", "Logic"], impact: 1, rollback: "easy — delete file" },
    { path: "server/public/api/crm/hubspot/hs_helpers.php", isNew: false, why: "Home of the settled search helper; add hs_create_contact() helper", what: "Additive functions only — no signature changes", blast: "HS-shared file (many HS endpoint callers) but additive", dims: ["Logic"], impact: 1, rollback: "easy — revert funcs" },
    { path: "server/public/api/core/token_summary_lib.php", isNew: false, why: "Both new endpoints call load_hs_tokens()", what: "Add both basenames to the hubspot array in token_read_whitelist()", blast: "Consumed by crm_usage_dashboard.php + token_summary_stats.php", dims: ["Interface"], impact: 0, rollback: "easy" },
    { path: "chrome-extension/background.js", isNew: false, why: "Wire dial-pad → resolve/create → existing CTC path", what: "New DIALPAD_* handlers; reuse buildSoftphoneUrl() / CLICK_TO_CALL", blast: "New message types = additive stability contract", dims: ["Interface", "Logic"], impact: 1, rollback: "easy (dev-gated)" },
    { path: "chrome-extension/popup.js", isNew: false, why: "Dial-pad UI logic + prompts", what: "Keypad, multi-match picker, create-confirm, getErrorMessage(), loading state", blast: "Popup-internal", dims: ["Interface"], impact: 1, rollback: "easy" },
    { path: "chrome-extension/popup.html + popup.css", isNew: false, why: "The dial-pad surface", what: "New UI block", blast: "Popup-internal", dims: ["Interface"], impact: 0, rollback: "easy" },
    { path: "manifest.json / changelog.js / KB / STORE_LISTING (at launch)", isNew: false, why: "Customer-facing wiring when the gate flips", what: "Version bump, changelog, KB, listing/privacy note", blast: "Customer-visible", dims: ["Interface", "Compliance"], impact: 1, rollback: "easy" },
  ],

  // Directed blast-radius graph. Node concern is 0–2. Edges = "affects".
  graph: {
    nodes: [
      { id: "create", label: "hs_create_contact.php", sub: "NEW · writes CRM", concern: 2, file: "server/public/api/crm/hubspot/hs_create_contact.php" },
      { id: "resolve", label: "hs_resolve_by_phone.php", sub: "NEW", concern: 1, file: "server/public/api/crm/hubspot/hs_resolve_by_phone.php" },
      { id: "helpers", label: "hs_helpers.php", sub: "additive", concern: 1, file: "server/public/api/crm/hubspot/hs_helpers.php" },
      { id: "whitelist", label: "token_summary_lib.php", sub: "token_read_whitelist", concern: 0, file: "server/public/api/core/token_summary_lib.php" },
      { id: "bg", label: "background.js", sub: "DIALPAD_* + CLICK_TO_CALL", concern: 1, file: "chrome-extension/background.js" },
      { id: "soft", label: "softphone.php", sub: "external_crm_data → PB logs", concern: 1, file: null },
      { id: "popup", label: "popup.js / popup.html", sub: "dial pad UI", concern: 0, file: "chrome-extension/popup.js" },
    ],
    edges: [
      ["create", "helpers"], ["resolve", "helpers"],
      ["create", "whitelist"], ["resolve", "whitelist"],
      ["create", "bg"], ["resolve", "bg"],
      ["bg", "soft"], ["bg", "popup"],
    ],
  },

  failureModes: [
    { text: "Duplicate contact creation — a search miss on an existing contact makes the create path spawn a duplicate in the customer's CRM (worst outcome). Mitigated by the settled search (EQ on hs_searchable_calculated_phone/mobile_number) + prompt-before-create, never silent.", precedent: "The risk the probe existed to retire" },
    { text: "New token-reading endpoints not whitelisted → phantom 'missing token' / 'endpoint not in whitelist' anomalies on the CRM Usage dashboard the morning after deploy. Fix: add both basenames to token_read_whitelist() in the same PR.", precedent: "LESSONS.md 2026-07-03 (CTC softphone endpoints)" },
    { text: "Wrong-contact logging on multi-match — if the picker is bypassed/auto-picks, the call logs to the wrong person (the exact failure this feature prevents). Multi-match is confirmed real (demo has 20+ contacts on one number).", precedent: "Probe finding, 2026-09-16" },
    { text: "Copy-template plumbing gap — building the endpoints by copying can carry over wrong include depth, wrong crm_name, or drop the owner-enrichment call.", precedent: "LESSONS.md 2026-08-24 (wrong template hid Forth CTC plumbing)" },
    { text: "Customer-facing surfaces forgotten at launch — KB/changelog/store-listing not updated when the gate flips.", precedent: "LESSONS.md 2026-07-01 (AgencyZoom shipped invisible)" },
  ],

  alternatives: [
    { title: "Split into two PRs: resolve+dial first, create-contact second", trade: "v1 drops to Data-1 (no CRM writes): found → dial, multi → picker, no-match → 'add them in HubSpot first'. Removes the only Data-2 / hard-rollback piece while proving the picker UX.", decision: "recommended" },
    { title: "Dev-gate (CURRENT_ENV==='dev')", trade: "Soak on dev before customers.", decision: "chosen" },
    { title: "Additive endpoints + new message types (vs overloading CLICK_TO_CALL)", trade: "No shared-signature changes; no un-reloaded-cohort break.", decision: "chosen" },
    { title: "Per-user toggle layered on the env gate at launch (à la ctcShouldShowPills)", trade: "Off-switch for individual customers.", decision: "deferred to launch" },
  ],

  recommendation:
    "Proceed with guardrails — strongly consider the split. Land hs_resolve_by_phone.php + dial-pad UI + picker first (read-only, Data-1, easy rollback) behind the dev gate; add hs_create_contact.php as a second PR once the picker/prompt UX is proven. Non-negotiables: (a) add both endpoints to token_read_whitelist() in the same PR, (b) prompt-before-create — never silent, (c) picker on multi-match — never auto-pick, (d) crm_name exactly 'hubspot'.",
};
