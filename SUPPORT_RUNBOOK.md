# Support Runbook — diagnosing a customer report

**Purpose:** when a support ticket lands with "extension threw an error, here's what it said," this runbook takes you from that raw report to a diagnosable server-side log entry in under 5 minutes. Written 2026-08-02 after LESSONS.md 2026-08-02 (the diagnostic-capture PR arc). If you're reading this months later without recent context, everything you need is on this one page.

**Companion docs:**
- [KB_EXTENSION_TROUBLESHOOTING.md](KB_EXTENSION_TROUBLESHOOTING.md) — customer-facing troubleshooting (what the customer should try before escalating)
- [SERVER_SETUP.md](SERVER_SETUP.md) — how to SSH into the VPSes and where things live

---

## Step 1 — What did the customer see?

Ask the customer for a **screenshot of the popup error alert.** Every error alert since v0.8.4 ends with a suffix like:

> Error saving PAT: Expired oauth token **(Support ID: 09b29e30822d)**

The 12-hex-char string after "Support ID:" is what you need. If they can't screenshot, "what time did you see it, roughly?" narrows the grep.

**Extension versions older than v0.8.4** don't show a Support ID. In that case, ask for their `client_id_hash` — they can find it via `chrome://extensions → PhoneBurner → Inspect popup → Console → chrome.storage.local.get(["pb_unified_client_id"], console.log)`. The 12-char hash lands in the server log alongside every error entry.

---

## Step 2 — SSH to the right VPS

| Environment | Host | Log file path |
|---|---|---|
| **Prod** (customer report) | `extension.phoneburner.biz` | `/opt/pb-extension-dev/var/log/api.log` |
| **Dev** (internal testing) | `extension-dev.phoneburner.biz` | `/opt/pb-extension-dev/var/log/api.log` |

Both use the same on-disk path. If the customer's popup DEV badge is red/absent, they're on prod. If it shows a `DEV` chip near the version, they're on dev.

```bash
ssh jeff@extension.phoneburner.biz
```

---

## Step 3 — Find the log entry

**Path A — you have a Support ID** (most cases):

```bash
grep '<support-id>' /opt/pb-extension-dev/var/log/api.log | jq .
```

Example (real from a dev test):

```bash
grep '3b57022fd5f3' /opt/pb-extension-dev/var/log/api.log | jq .
```

**Path B — you have a client_id_hash + approximate time:**

```bash
grep '"client_id_hash":"<hash>"' /opt/pb-extension-dev/var/log/api.log \
  | jq 'select(.ts | test("2026-08-05T15"))' \
  | jq 'select(.event | test("error|fail|invalid"))'
```

Adjust the date/hour prefix in the `test("...")` and the `event` regex to narrow.

**Path C — you only know the CRM the customer was using:**

```bash
tail -1000 /opt/pb-extension-dev/var/log/api.log \
  | jq 'select(.event | test("hs_|hubspot"))' \
  | jq 'select(.event | test("error|fail|invalid"))'
```

Swap `hs_|hubspot` for `close_|apollo_` etc.

---

## Step 4 — Read the entry

Every diagnostic log entry from the v0.8.4+ instrumentation has this shape:

```json
{
  "ts": "2026-08-02T19:59:25-06:00",
  "request_id": "3b57022fd5f3b551",
  "event": "oauth_pb_save.reject.pat_validation_failed",
  "duration_ms": 151,
  "client_id_hash": "16e3e717e25c",
  "status": 401,
  "provider_msg": "Expired oauth token",
  "response": { "http_status": 401, "debug_codes": [{"code": 40109, "reason": "..."}] },
  "body_snippet": "",
  "curl_error": ""
}
```

**Where to look:**

- **`provider_msg`** — the provider's OWN error text (PhoneBurner / HubSpot / Close / Apollo). If populated, this is usually all you need. If empty, look at `response`.
- **`response`** — the full PII-redacted decoded body from the provider. Even when `provider_msg` is empty, useful clues live here (`debug_codes[N].reason`, `errors[]`, provider-specific fields).
- **`body_snippet`** — only populated when the response wasn't valid JSON (rare — usually means the provider returned an HTML error page or the connection was truncated). If present, gives you the first 500 chars of raw text (with OAuth tokens scrubbed).
- **`curl_error`** — populated only on network-level failures (DNS, timeout, TLS). If present, the request never reached the provider.
- **`status`** — HTTP code. `401` almost always means expired/invalid token. `429` = rate-limited. `5xx` = provider outage.
- **`event`** — the code path that fired. Grep this in the server code to see exactly which endpoint / helper produced the log.

---

## Step 5 — Common events → what they mean

| Event | What happened | First thing to check |
|---|---|---|
| `oauth_pb_save.reject.pat_validation_failed` | Customer's PhoneBurner PAT is invalid/expired | Ask them to regenerate their PAT and try again. `provider_msg` will say "Invalid" vs "Expired." |
| `hubspot_refresh.error` | HubSpot refresh_token invalid/expired | Ask them to reconnect HubSpot in Settings. Common cause: they revoked the app in HubSpot admin. |
| `close_refresh.error` / `apollo_refresh.error` | Same as above for Close/Apollo | Ask them to reconnect that CRM. |
| `pb_dialsession.error` | PB's `/dialsession` API rejected the launch | Most common: customer has an active dial session in another tab. Ask them to end that first. |
| `hs_list_members.fetch_fail` | HS list-membership fetch failed on a list launch | Usually a scope issue — list requires an OAuth scope the customer's connection doesn't have. Reconnect. |
| `hs_fetch_contacts.batch_summary` | Some contacts in a batch failed to fetch | Look at `fail` vs `total` ratio. If fail == total, HS was fully down or scope missing. Partial fail is usually per-contact permission. |
| `close_lead_to_contact.per_lead_failed` | Close lead didn't resolve to any contacts | Lead exists but has no contact records. Customer needs to add contacts to the lead in Close. |
| `apollo_save_key.invalid` | Apollo API key was rejected by /auth/health | Ask them to regenerate the master key in Apollo settings. **Note:** Apollo /auth/health is permissive — some invalid keys pass this check and fail later during actual data fetches. |
| `apollo_sequences.search_fail` / `apollo_sequence_tasks.fetch_failed` | Apollo API rejected the sequence/task fetch | Usually rate-limit (Apollo enforces 100/min). `status: 429` confirms. |
| `hs_call_log.refresh_failed` | HubSpot task-completion webhook couldn't refresh the token | Customer's HS token expired mid-session. Task auto-completion won't work for the rest of the session; they need to reconnect and start a new session. |
| `close_call_log_token_refresh.error` / `apollo_call_log_token_refresh.error` | Same as above for Close/Apollo | Same fix — reconnect that CRM. |

---

## Step 6 — What if the Support ID isn't in the log?

Rare but possible:

1. **Customer is on an extension version older than v0.8.4** — no Support ID exists. Use Path B (client_id_hash + timestamp) instead.
2. **Customer is on prod but you're grepping dev, or vice versa** — check the version chip on their popup for the `DEV` badge.
3. **The failure happened server-side in a webhook path** — webhook fires from PhoneBurner's servers, not from the customer's request. No Support ID. Grep by `client_id_hash` + event name (see Path B).
4. **The log rotated** — logs rotate weekly. Look for `api.log.1`, `api.log.2.gz`, etc. `zgrep` handles the compressed ones.
5. **The customer error was purely client-side** (Chrome extension crashed, popup DOM error, network failure before the request left the browser). No server log will exist. Ask them to open DevTools → right-click popup → Inspect → Console, and screenshot any red text.

---

## Step 7 — What if `provider_msg` is empty but there IS a log entry?

Means the provider used a response shape we don't extract from. Look at `response` — the raw text is in there. Common shapes we DO catch:
- `error.message` (some HubSpot endpoints)
- `error_description` (OAuth 2.0 spec — HS/Close/Apollo OAuth token endpoints)
- `message` (HubSpot general)
- string `error` (Close, some OAuth error responses)
- `debug_codes[0].reason` (PhoneBurner API — added in PR #194 after Jeff caught it on dev)

If you find a NEW shape a provider is using, **add it to `describe_api_failure` in `server/public/utils.php`** with a unit test. Every new shape captured = one more customer's alert becomes actionable.

---

## Quick copy-paste one-liner

For 90% of tickets, this is what you'll actually type:

```bash
ssh jeff@extension.phoneburner.biz \
  "grep '<SUPPORT_ID>' /opt/pb-extension-dev/var/log/api.log | jq ." \
  | tee /tmp/support-lookup.json
```

Swap `<SUPPORT_ID>` for the 12-char string from the alert. Output shows every field you need, and `/tmp/support-lookup.json` saves it locally for pasting into the ticket.

---

## When to escalate to engineering

- `curl_error` is populated (network-level failure to the provider) — could be an outage on our side or theirs. Check status.phoneburner.com / status.hubspot.com / etc.
- `provider_msg` is populated with something unrecognized ("account disabled," "region blocked," etc.) — customer's CRM account has a state we haven't seen before.
- No matching log entry at all despite a valid Support ID — extension may be hitting a URL/endpoint we don't own. Get the network-tab request URL from the customer.
- The event is a `.batch_summary` with `fail == total` and `status: 5xx` — provider-wide outage; likely affecting all customers on that CRM.
