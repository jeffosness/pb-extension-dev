# Add New CRM Provider

Add support for a new CRM provider: $ARGUMENTS

## Step 0: Research the CRM

Before writing code, gather this information:
- **Host domain(s)** — What URL patterns identify this CRM? (e.g., `app.close.com`)
- **Integration level** — L1 (generic table), L2 (CRM-specific selectors), or L3 (full API)?
- **DOM structure** — For L1/L2: What stable selectors exist for name, phone, email, record URL?
- **API docs** — For L3: Auth method (OAuth2 vs API key), endpoints, rate limits
- **Important:** Avoid hashed/minified CSS class names — they change between deploys. Use `[data-*]`, `[aria-label]`, `[role]`, `a[href*="pattern"]`, `tr[data-index]` etc.

## Step 1: Add to CRM Registry

Edit `chrome-extension/crm_config.js`:

```javascript
{
  id: "newcrm",
  displayName: "New CRM Name",
  level: 2,                    // 1=generic, 2=CRM-specific, 3=full API
  hostMatch: "newcrm.com",     // String or array of strings
}
```

**TEST NOW:** Reload extension, open CRM page, verify popup shows "Detected CRM: New CRM Name, Level X". Do NOT proceed until this works.

## Step 2: Implement Scanner (L1/L2) or API Integration (L3)

### For L2: Add scanner function in `chrome-extension/content.js`

Follow the pattern of existing scanners (Pipedrive, Close, Salesforce). Key rules:

1. **Use stable DOM selectors only** — no hashed CSS classes
2. **Extract phone from aria-labels if needed** — `button[aria-label^="Call "]`
3. **Support checkbox selection** — if any rows checked, scan only those; otherwise scan all
4. **Return standard contact format:**
   ```javascript
   {
     name: "",
     phone: "",
     email: "",
     source_url: window.location.href,
     source_label: document.title || "",
     record_url: null,
     crm_identifier: null,
     row_index: 0,
   }
   ```

5. Add routing in `scanPageForContacts()`:
   ```javascript
   } else if (crmId === "newcrm") {
     contacts = scanNewCrmContacts();
   }
   ```

**TEST NOW:** Reload extension, verify popup still loads. Then test "Scan & Launch Dial Session".

### For L3: Create server-side provider directory

Follow the HubSpot pattern in `server/public/api/crm/hubspot/`. See CLAUDE.md "Adding L3 Provider" section.

## Step 3: Content Script Safety Rules

These are non-negotiable — learned from real breakage:

- **Test ONE file at a time** — edit crm_config.js, reload, verify. Then edit content.js, reload, verify. A broken content script silently kills the entire extension with NO console errors.
- **No CSS4 selectors** in querySelector — no `[attr*="val" i]` case-insensitive flag, no `:has()`
- **No unicode escapes in regex** — use literal characters, not `\u2013` etc.
- **`node -c content.js`** validates JS syntax but NOT CSS selector validity
- **If extension hangs on "Loading..."** — the content script has a parse/runtime error

## Step 4: Update Documentation

- [ ] Update CRM table in `CLAUDE.md` (Three-Level CRM Integration Model)
- [ ] Add CRM to test list in CLAUDE.md ("Test existing CRMs" line)
- [ ] For L2: Add scanner reference table to CLAUDE.md (selectors used)
- [ ] Bump version in `chrome-extension/manifest.json`
- [ ] Add changelog entry in `chrome-extension/changelog.js`

## Step 5: Commit & PR

- Create feature branch: `feature/{crm}-l{level}-scanner`
- One commit with all changes
- PR with test results documented

## Reference Files

- `chrome-extension/crm_config.js` — CRM registry (edit first, test first)
- `chrome-extension/content.js` — Scanner functions and dispatcher
- `chrome-extension/background.js` — URL detection (`detectCrmFromUrl`)
- `server/public/api/crm/hubspot/` — L3 reference implementation
- `server/public/api/crm/generic/dialsession_from_scan.php` — L1/L2 endpoint
- `CLAUDE.md` — Full guide in "Adding New CRM Providers" section
