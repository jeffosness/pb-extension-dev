# PhoneBurner CRM Extension

A Chrome extension that connects supported CRMs (HubSpot, Salesforce, Zoho, Pipedrive, Close, Apollo, monday.com, AgencyZoom, and more) to [PhoneBurner](https://www.phoneburner.com) — click **Scan & Launch** on any CRM contact list to open a PhoneBurner dial session with those contacts pre-loaded, and see live "who's being called now" navigation as reps work the list.

- **Marketing site:** https://extension.phoneburner.biz
- **Customer KB:** https://extension.phoneburner.biz/kb.php
- **Chrome Web Store:** installed via the CWS listing (search "PhoneBurner Dial Session Companion")

---

## Where to find things

**Depending on what you're doing, start here:**

| I'm trying to... | Read this |
|---|---|
| **Onboard to the codebase** | [CLAUDE.md](CLAUDE.md) — invariants and rules (always the first stop) |
| **Understand how the system is put together** | [ARCHITECTURE.md](ARCHITECTURE.md) — components, data flow, provider adapter contract |
| **Add a new CRM (L1/L2/L3)** | [CRMS.md](CRMS.md) — level-picker + walkthroughs for each level |
| **Provision the backend on a new host** | [SERVER_SETUP.md](SERVER_SETUP.md) |
| **Change something in `utils.php` or `bootstrap.php`** | [SHARED_CODE.md](SHARED_CODE.md) — blast-radius reference first |
| **Change anything security-relevant** | [SECURITY.md](SECURITY.md) — threat model + known gaps |
| **Troubleshoot a customer issue** | [KB_EXTENSION_TROUBLESHOOTING.md](KB_EXTENSION_TROUBLESHOOTING.md) — same source that renders at extension.phoneburner.biz/kb.php |

## Repo layout at a glance

```
chrome-extension/       Chrome extension (MV3): manifest, background service worker,
                        content script, popup UI, softphone host, changelog, icons.
server/public/          PHP backend served from the docroot.
                        - utils.php                     shared utilities
                        - api/core/                     core endpoints + bootstrap
                        - api/crm/{generic,provider}/   per-provider L1/L2/L3 code
                        - webhooks/                     PhoneBurner callbacks
                        - sse.php                       real-time follow-me stream
                        - softphone.php + softphone_host.js  embedded softphone
                        - index.html, kb.php, privacy.html   public marketing/KB
scripts/                Build + one-off tooling (currently: CWS zip builder)
.github/workflows/      CI (KB-impact + Security-impact checks, tag-triggered
                        prod deploy, merge-triggered dev deploy)
```

## Contributing

- Always work on a `feature/*` branch off `main` (see [CLAUDE.md → Golden Rule #9](CLAUDE.md)).
- Every PR must declare KB impact (CI-enforced) — see the PR template. If your PR touches security-relevant files, it must also declare a Security Impact.
- User-facing changes bump `chrome-extension/manifest.json` + add a `changelog.js` entry.
- Merge to `main` auto-deploys to the dev backend; tagging `prod-vX.Y.Z` triggers the prod deploy.

## Running tests

Security-critical utilities have PHPUnit coverage. On a fresh clone:

```bash
composer install    # installs PHPUnit
composer test       # runs the suite (~1 second)
```

CI runs the same suite on every PR — merging is blocked on a red run. See [CLAUDE.md → Automated tests](CLAUDE.md) for what's covered and how to add more.

## License

Proprietary. All rights reserved.
