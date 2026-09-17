# Touch Plan viewer (prototype)

A single, dependency-free HTML page that turns a Touch Plan into a clickable,
shareable artifact — for walking someone through a change's blast radius
**before** the code (or even the PR) exists.

## Use it

1. Double-click **`viewer.html`** (opens on `file://` — no server needed).
2. It renders whatever is in **`data.js`** (`window.TOUCH_PLAN = {...}`).

Current `data.js` = the HubSpot dial-pad phone→contact resolver plan.

## What it shows

- **Overall concern** + **CI risk tier**, both on the project's **0–2** scale
  (0 = de minimis · 1 = moderate · 2 = significant).
- **Concern by dimension** — the seven dimensions, color-coded, each with its
  driving justification.
- **Blast-radius map** — an interactive layered graph; click a node to jump to
  that file's row.
- **Files touched** — click a row to expand what-changes / blast-radius.
- **What could go wrong**, **Alternatives considered**, **Recommendation**.

## Why `data.js` and not `data.json`

`file://` blocks `fetch()` of a sibling JSON in Chrome. Loading the data as a
`<script>` that assigns `window.TOUCH_PLAN` sidesteps that, so the page opens by
double-click with zero setup.

## Reuse across projects (proposed)

If we like this, the `touch-plan` skill emits a `data.js` in this exact shape
next to a copy of `viewer.html`. Any project (salt, extension, …) then gets the
same clickable plan for free. See the `window.TOUCH_PLAN` shape in `data.js` for
the schema (title, change, overallConcern, ciRiskTier, dimensions[], files[],
graph{nodes,edges}, failureModes[], alternatives[], recommendation).

Prototype only — not wired into the skill yet.
