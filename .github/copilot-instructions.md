# Copilot / Agent Instructions — PhoneBurner extension + API

Purpose: Help AI coding agents become productive quickly in this repository.

- Big picture
  - Two main components:
    - Chrome extension in `chrome-extension/` (MV3): `manifest.json`, `background.js` (service worker), `popup.js`, `content.js`, and `popup.html`.
    - Backend API in `server/public/` serving endpoints under `/api/*` (PHP). Key shared bootstrap is `server/public/api/core/bootstrap.php`.
  - Data flow: popup/UI -> `background.js` (chrome.runtime messages) -> `content.js` (chrome.tabs.sendMessage) for page scraping, and `background.js` -> backend API via fetch to BASE_URL (see `BASE_URL` in `background.js`/`popup.js`). Server returns `session_token` + `launch_url` used to open dialsession popups.

- Important patterns to follow (concrete)
  - Message types handled in `chrome-extension/background.js`: `GET_CONTEXT`, `SCAN_AND_LAUNCH`, `HS_LAUNCH_FROM_SELECTED`, `SCANNED_CONTACTS`, `GET_STATE`, `SAVE_PAT`, `CLEAR_PAT`, `GET_CLIENT_ID`, `START_FOLLOW_SESSION`, `STOP_FOLLOW_SESSION`.
  - Inject content script safely with `chrome.scripting.executeScript` and always target the top frame (`frameId: 0`) to avoid iframe issues (see `ensureContentScript`).
  - Use `chrome.storage.local` keys: `pb_unified_client_id` and `pb_current_session` for persistent client id and follow session state.
  - Server-side responses: endpoints include `api/core/bootstrap.php` which provides `api_ok`, `api_ok_flat`, `api_error` helpers — return JSON in the shapes the extension expects. For non-JSON endpoints (OAuth finish pages, SSE) set `PB_BOOTSTRAP_NO_JSON` before including bootstrap.
  - CORS is intentionally permissive in dev: `bootstrap.php` reflects `Origin` and enables credentials. Tests and local dev should mimic expected origins.

- Dev / run notes (practical steps)
  - Serve the PHP backend from `server/public` (example):

    ```bash
    php -S 127.0.0.1:8000 -t server/public
    ```

  - Copy sample config if needed: `server/public/config.sample.php` -> `server/public/config.php` (the repo contains `config.php` and `config.sample.php` locations to check).
  - To test the extension locally: load `chrome-extension/` as an unpacked extension in Chrome (Extension Developer Mode). Edit `BASE_URL` in `chrome-extension/background.js` and `chrome-extension/popup.js` to point at your local server (or map hostnames).

- Logging & security conventions
  - Server logging via `api_log()` writes to `var/log/api.log` by default (override with `PB_LOG_DIR`). The logger redacts tokens/PII keys: `token`, `access_token`, `authorization`, `email`, `phone`, `contacts`, `payload`.
  - Client API calls use `fetch(..., { credentials: 'include' })` and set an `X-Client-Id` header (see `getClientId()` + `api()` in `background.js`). Preserve this behavior when adding endpoints.

- Integration examples (copyable)
  - HubSpot L3 flow (from UI to server): `popup.js` triggers `HS_LAUNCH_FROM_SELECTED` -> `background.js` ensures content script -> calls `/api/crm/hubspot/pb_dialsession_selection.php` -> server returns `session_token` + `launch_url` -> background opens popup window.
  - Generic scan flow: popup triggers `SCAN_AND_LAUNCH` -> background sends `SCAN_PAGE` to top-frame `content.js` -> background posts to `/api/crm/generic/dialsession_from_scan.php`.

- When editing or adding endpoints
  - Include `server/public/api/core/bootstrap.php` at the top of API files.
  - Prefer `api_ok_flat()` for responses consumed by the extension (legacy top-level keys).
  - Avoid returning raw tokens or PII; rely on `api_log()` redaction and do not change the deny list without cross-checking extension code that expects redacted fields.

- Quick references
  - Extension entrypoints: `chrome-extension/manifest.json`, `chrome-extension/background.js`, `chrome-extension/popup.js`, `chrome-extension/content.js`, `chrome-extension/popup.html`.
  - Server bootstrap + helpers: `server/public/api/core/bootstrap.php`.
  - Example endpoints: `server/public/api/crm/hubspot/` and `server/public/api/crm/generic/`.

If anything here is unclear or you want tighter rules (naming, linting, test commands), tell me which areas to expand and I will iterate.
