# Security Model

**Last reviewed:** 2026-06-29
**Next review:** 2026-09-29 (quarterly cadence)
**Owner:** Jeff Osness · jeff@phoneburner.com

This document captures the security model of the PhoneBurner Dial Session Companion extension and its backend.

It exists for three reasons:

1. To make our protections explicit so we can audit them.
2. To be **honest** about what we DON'T defend against, so we don't hide behind false reassurance.
3. To give engineers a clear basis for review when changing security-relevant code.

If you change a file listed under [Files that trigger Security Impact review](#files-that-trigger-security-impact-review) below, the **Security Impact CI check** requires you to either update this document or explicitly declare the security impact in your PR description.

---

## What we store

| Provider | Tokens stored | Lifetime |
|---|---|---|
| **PhoneBurner** | Personal Access Token (PAT) | Until the customer explicitly disconnects |
| **HubSpot** | OAuth `access_token` + `refresh_token` + `expires_at` + granted `scopes` | Until disconnect (refresh tokens rotated on refresh per HubSpot's response) |
| **Close** | OAuth `access_token` + `refresh_token` + `expires_at` | Until disconnect |
| **Apollo** | OAuth `access_token` + `refresh_token` (or manual API key) | Until disconnect |
| **Browser side** | `pb_unified_client_id` (random UUID, anonymous lookup key) and user preferences | Local to Chrome profile |

Token values themselves are **never** stored in the browser after the initial save. The browser only holds the UUID; every API request from the extension sends the UUID, and the server looks up the corresponding token file at request time.

## How we protect tokens at rest

- Stored as per-customer JSON files at `/var/lib/pb-extension/tokens/{provider}/{client_id}.json`
- File mode **0600** (owner-only read/write)
- Directory mode **0700** (owner-only access)
- Owned by `www-data`
- **Outside** the webroot — no HTTP path resolves to these files
- Apache vhost adds defense-in-depth via `<LocationMatch "^/(tokens|sessions|daily_stats|user_settings)/">` deny-all, so even if the directory layout ever changed, HTTP access would still be blocked

## How we protect tokens in transit

- HTTPS only between extension and backend (enforced by extension `host_permissions` in `manifest.json`)
- TLS via Let's Encrypt, auto-renewed by `certbot.timer`
- No tokens in URLs (the SSE bootstrap uses single-use `temp_code` exchanges via `temp_code_store()`/`temp_code_retrieve_and_delete()`)
- Webhook traffic from PhoneBurner over HTTPS

## What's audited

- Every `load_*_tokens()` / `save_*_tokens()` / `clear_*_tokens()` call writes a structured event to `/opt/pb-extension/var/log/token-audit.log`
- Audit event fields: `t` (ISO-8601 timestamp), `evt` (read/write/delete), `prov` (pb/hubspot/close/apollo), `cid` (SHA-256-hashed client_id, never the raw value), `ep` (calling script name), `ip` (source IP), `res` (ok/missing/error)
- **Token values are never logged.** Only event metadata.
- The dashboard at `https://extension.phoneburner.biz/metrics/crm_usage_dashboard.php` (and the dev equivalent) surfaces a 24-hour Token Security section with anomaly detection.

### Anomaly detection rules

The dashboard flags an event as an anomaly if any of these are true:

1. **Endpoint not in whitelist.** Each provider has an explicit list of scripts that are EXPECTED to read its tokens. A token read from anywhere else gets flagged. The whitelist lives inline in `metrics/crm_usage_dashboard.php` and must be updated when new legitimate consumers are added.
2. **Read burst.** > 30 reads from the same `client_id_hash` in a 5-minute window — could indicate a stuck loop or scraping.
3. **Read-miss enumeration.** > 10 read-missing events from the same source IP in 24h — looks like someone scanning for valid client_ids.

The dashboard's Token Security section is intended to be checked during business hours. Real-time alerting (email/webhook on anomaly count crossing a threshold) is a planned follow-up but not implemented today.

## Disconnect flow

When a customer disconnects an integration:

- `clear_pb_token()` / `clear_hs_tokens()` / `clear_close_tokens()` / `clear_apollo_tokens()` calls `@unlink()` on the corresponding file
- The file is removed from the filesystem at that moment (PHP `unlink()`)
- A delete event is recorded in the audit log
- **Manually validated multiple times** by Jeff before any of this went live — see commit history for the disconnect flow files

## What we explicitly DO NOT protect against today

This is the honest part. These are known gaps:

1. **Encryption at rest.** Token files are plaintext JSON. An attacker who gains filesystem-level access (root escalation, accidentally world-readable backup, `www-data` RCE, leaked snapshot) reads tokens directly. _Planned: lazy in-place migration to authenticated-encryption format using libsodium with a master key in `config.php`._
2. **HSM-grade key management.** No hardware-backed keys, no vault. Master key (when added) will be in `config.php` which is `chattr +i` immutable and mode 0600. _Planned: long-term move to AWS Secrets Manager / Vault as part of folding into PhoneBurner's main infrastructure._
3. **Sysadmin / insider threat.** Anyone with sudo on the host can read tokens (even after Tier 2 encryption, if they also access `config.php`). _Mitigation: limited number of people have sudo; SSH is key-only._
4. **Real-time intrusion alerting.** No automatic page/email when anomalies fire — we rely on dashboard surfacing during business hours. _Planned: email or Slack webhook when anomaly count crosses a threshold._
5. **Token rotation enforcement.** We use providers' refresh tokens but don't impose shorter access-token TTLs beyond what providers grant. _Planned: opportunistically rotate refresh tokens on every use (HubSpot supports this in its refresh response)._
6. **Audit log retention/rotation policy.** Currently the audit log grows unbounded. _Planned: logrotate config matching `app.log` (daily, 365-day retention)._

The honest takeaway: we're at industry-standard third-party-SaaS OAuth handling. We are NOT at "best possible." The gaps above are Tier 2 / Tier 3 improvements tracked in the security roadmap.

## Known implementation gaps (finer-grained)

Separate from the strategic gaps above, these are specific implementation weaknesses we're aware of but haven't fixed yet. They're smaller in scope but real.

**Medium risk (bugs worth fixing before broad-deployment growth):**

1. **Session state files are world-readable + world-writable.** `save_session_state()` currently creates `server/public/sessions/{token}.json` with permission 0777. Contents include contacts_map (names, phones), `current` contact, and stats — a filesystem-level attacker can read them. Fix: convert `save_session_state()` to use `atomic_write_json()` with 0600.
2. **PhoneBurner webhooks are unauthenticated.** `webhooks/contact_displayed.php` and `webhooks/call_done.php` accept `?s=session_token` in the URL and trust that PhoneBurner sent it. HMAC signature validation would give defense-in-depth if PB ever leaks a webhook URL upstream. (v0.8.0 introduced `softphone_call_done.php` WITH HMAC validation — that pattern is the template for retrofitting the other webhooks.)
3. **`server/public/metrics/` directory is under the webroot.** Contents include `sse_usage-*.log`, `sse_presence/*.json`, and `crm_usage-*.log`. Directory listing is disabled, but individual files are guessable and publicly fetchable if paths are known. Fix: move to `/var/lib/pb-extension-dev/metrics/` or add explicit Apache deny rules.

**Low risk (harden when time permits):**

1. **Webhook handlers log full payloads with PII.** `webhooks/call_done.php` and `webhooks/contact_displayed.php` currently `log_msg()` the raw PB webhook body, which contains names, phone numbers, and emails. Should route through `redact_pii_recursive()`.
2. **No CSP on the extension popup.** Chrome's default CSP for MV3 extensions is strict, so injected inline JS won't run, but explicit CSP hardening in `manifest.json` would document our stance.
3. **Stale presence files aren't cleaned up automatically.** SSE presence files at `metrics/sse_presence/*.json` accumulate if a session disconnects unexpectedly. Cron cleanup entry in [SERVER_SETUP.md](SERVER_SETUP.md) exists but has been observed to miss edge cases.
4. **Date fields are not range-checked.** Anywhere we accept a date from a client (stats endpoints, user settings), we don't reject future-dated or ancient timestamps. Minor risk since these end up in per-user stats files, not query keys.

**Data integrity (customer-facing, document don't fix):**

1. **PhoneBurner ↔ HubSpot Data Sync overwrite.** If a customer has PhoneBurner's built-in HubSpot Data Sync app enabled, PB will sync the primary phone number from dial sessions back to HubSpot's "Phone Number" property. This can overwrite the original value if PB used a different alternate phone during dialing. Customers should either disable phone-number sync in the Data Sync app or rely exclusively on this extension for phone-number handling. This is PB platform behavior, not something our code controls.

## Threat model summary

| Threat | Mitigated today? | Notes |
|---|---|---|
| Browser/profile compromise | ✓ Yes | Tokens never in browser; only a UUID |
| Direct HTTP scraping of token dir | ✓ Yes | Outside webroot + vhost deny |
| Token leak in app.log | ✓ Yes | PII redaction recursively applied; tokens never logged |
| Compromise of one customer doesn't compromise others | ✓ Mostly | Per-customer files; same key compromises all _(when encryption is added; Tier 2 plans per-customer keys)_ |
| Filesystem leak (backup, RCE, sysadmin) | ✗ No | All tokens plaintext on disk |
| Replay of stolen refresh token | ✗ Partial | Tokens valid until provider revokes; we don't proactively rotate refresh tokens |
| Insider with sudo | ✗ No | Trust model assumes sudo users are trusted |

## Files that trigger Security Impact review

If your PR modifies any of these, the **Security Impact CI check** requires you to declare the impact in your PR description (using the `## Security Impact` section template):

- `server/public/utils.php` (token functions, audit logging, future encryption)
- `server/public/config.sample.php`, `server/public/config.php` (config keys, secrets)
- `server/public/api/**/oauth_*.php` (any OAuth start/finish/disconnect endpoint)
- `server/public/api/**/*_call_logger.php` (call loggers — they load tokens to call provider APIs)
- `server/public/api/core/oauth_pb_save.php`, `oauth_pb_clear.php` (PAT save/clear)
- `server/public/webhooks/*.php` (webhook handlers — they load session state, sometimes tokens)
- `server/public/sse.php` (handles temp-code → session-token exchange)
- `SECURITY.md` itself

### How to declare Security Impact in a PR

Add this section to your PR description:

```markdown
## Security Impact

- [ ] **No security model change** — refactor/comments/non-security code path
- [ ] **Security model updated in SECURITY.md** — see the changes in this PR
- [ ] **Security follow-up tracked** — #issue-N (or a link)
```

Check exactly one box. The CI check enforces this and gates merge to `main`.

## How to update this document

This document is intentionally short. To keep it from going stale:

1. **Don't add aspirational text.** Only write what's true today. Future plans go in commit messages or PR descriptions, not in this file (the "What we explicitly DO NOT protect against today" section is the exception — it explicitly tracks known gaps).
2. **Update on real changes only.** Refactors don't update this. New attack surface / new mitigation / new provider does.
3. **Quarterly review.** Bump `Last reviewed` and `Next review` even if nothing else changes — that's the forcing function to re-read and confirm everything's still accurate.
4. **Cross-reference CLAUDE.md.** CLAUDE.md is the engineering guide; this file is the security guide. They should not duplicate content. If something belongs in both, it lives in CLAUDE.md and is linked from here.
