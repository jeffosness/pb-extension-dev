# SHARED_CODE.md — Blast Radius Reference

**Purpose:** quick lookup for "if I change this, what am I about to break?"

The counts below are hand-curated snapshots — not auto-generated. Refresh them when you notice they're stale (see the "How to refresh" section at the bottom). They shift slowly; the *ordering* is what matters, not the exact numbers.

**Last refreshed:** 2026-07-02 (against commit a5f1a67, after Phase 3 docs merge).

---

## Highest-blast-radius files

| File | Included by | Risk of touching |
|------|-------------|------------------|
| `server/public/utils.php` | ~44 PHP files | **CRITICAL** — every endpoint uses at least one function from it (tokens, safe file ops, PII redaction, temp codes, rate limiting, `pb_call_dialsession`). Signature changes break everything. |
| `server/public/api/core/bootstrap.php` | ~38 PHP files | **CRITICAL** — CORS, security headers, request-scoped logging, `api_ok` / `api_error` / `api_log`. Not for endpoints only meant to return HTML (OAuth-finish pages set `PB_BOOTSTRAP_NO_JSON`). |

## Highest-blast-radius functions

| Function | Called from ~N files | What breaks if you change the signature |
|----------|----------------------|-----------------------------------------|
| `api_error()` | 32 | Every endpoint's error paths |
| `get_client_id_or_fail()` | 31 | Every authenticated endpoint |
| `rate_limit_or_fail()` | 26 | Every rate-limited endpoint |
| `api_ok_flat()` | 20 | Every extension-facing endpoint that returns flat keys |
| `load_pb_token()` | 16 | Every endpoint that authenticates as the PB user |
| `api_ok()` | 15 | Every "wrapped `data`" response endpoint |
| `temp_code_store()` | 10 | Dial-session launch, softphone auth-code mint, SSE code refresh |
| `pb_call_dialsession()` | 8 | Every L3 provider's dial session creation |
| `load_hs_tokens()` | 8 | HubSpot-touching endpoints |
| `load_apollo_tokens()` | 7 | Apollo-touching endpoints |
| `safe_file_path()` | 6 | Any file path construction (session/token/user_settings writers) |
| `load_close_tokens()` | 4 | Close-touching endpoints |
| `temp_code_retrieve_and_delete()` | 3 | Softphone-launch handshake, SSE bootstrap, extension launch URL |

## Extension-side stability contracts

- **Message types** (grep `msg.type ===` in `chrome-extension/background.js` — 26+ handlers). Renaming a message type without a compatibility shim breaks in-flight dial sessions on customers who haven't reloaded.
- **`chrome.storage.local` keys**:
  - `pb_unified_client_id` — the browser's stable identity
  - `pb_last_seen_version` — changelog / welcome gating
  - `pb_current_session` — active session token
  - `pb_softphone_runtime_override` — click-to-call runtime override
  - `pb_env_override` — user-selected dev/prod
- **CRM registry entries** in `crm_config.js` — the `id` field is a stability contract because it flows into `crm_name` (webhook lookup key), `record_url` builders, and dashboard telemetry.

## Rules for changing anything in this table

1. **Find every caller before you edit.** `grep -rl "\bfunction_name(" server/public --include="*.php"` (the `\b` matters — `pb_call_dialsession` and `pb_call_dialsession_wrapper` are different callers).
2. **Additive changes only** — add a new function, add an optional parameter, add a new field to a return value. Never rename in place. Never remove a parameter that has existing callers.
3. **If you must break a signature,** rename the function first (e.g. `api_error` → `api_error_v2`), migrate callers incrementally in follow-up PRs, then delete the old signature.
4. **Run PHPUnit before pushing.** `safe_file_path`, `atomic_write_json`, `temp_code_store`, and `temp_code_retrieve_and_delete` have automated tests under `tests/`. Run `composer test` locally — CI blocks PRs on red tests but you should catch failures before pushing. If your change touches a function that isn't tested yet, add tests in the same PR (see [CLAUDE.md → Automated tests](CLAUDE.md) for the template and priority list).
5. **For extension message types**, the same rule applies AND the extension is auto-updated by Chrome — meaning you have both old and new versions in the field for ~24 hours. Server code that handles a message must accept BOTH old and new payload shapes until the old version has aged out of Chrome Web Store.

## How to refresh this file

Static counts drift slowly. Refresh when:
- You add a new L3 provider (new `load_{provider}_tokens` row)
- You add a new heavily-used utility to `utils.php`
- You notice the numbers feel stale during a review

Quick refresh commands (run from repo root):

```bash
# File inclusion counts
grep -rl "require_once.*utils\.php\|include.*utils\.php" server/public --include="*.php" | wc -l
grep -rl "require_once.*bootstrap\.php\|include.*bootstrap\.php" server/public --include="*.php" | wc -l

# Function call counts
for fn in api_error api_ok api_ok_flat get_client_id_or_fail rate_limit_or_fail \
          safe_file_path atomic_write_json redact_pii_recursive pb_call_dialsession \
          temp_code_store temp_code_retrieve_and_delete \
          load_pb_token load_hs_tokens load_close_tokens load_apollo_tokens; do
  count=$(grep -rl "\b${fn}(" server/public --include="*.php" | wc -l)
  echo "$fn: $count files"
done
```

Update the tables above with the new numbers and bump the "Last refreshed" line.

---

**History:** this file replaced an auto-generated `PROJECT_MAP.md` (removed 2026-07-02). The old version tried to be a full dependency graph — every function, every include, every call chain — but nobody actually opened it during a task (we always grep'd source), and it added a 200-500 line diff to every commit via a Claude Code hook. The Danger Zone table was the one section that pulled its weight; it lives here now as a static, hand-refreshed reference.
