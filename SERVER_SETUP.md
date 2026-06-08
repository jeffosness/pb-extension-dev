# Server Setup Guide

**Audience:** anyone standing up the PhoneBurner Dial Session Companion backend on a fresh Linux server — whether that's a new VPS for a second customer, a move from the current single-tenant VPS to a larger box, or eventually a migration into PhoneBurner's AWS environment alongside the rest of PB's code.

**Goal:** end-to-end runbook to reproduce the current production setup. No tribal knowledge.

If you only need to make a code change, see [CLAUDE.md](CLAUDE.md). This file is specifically about provisioning hosts.

---

## Architecture overview

This repo deploys to **two independent environments** living side-by-side on the same host:

| Env | Webroot | Tokens dir | Logs dir | Deployed by |
|---|---|---|---|---|
| **dev** | `/opt/pb-extension-dev/server/public` | `/var/lib/pb-extension-dev/tokens` | `/opt/pb-extension-dev/var/log` | Push to `main` (auto) |
| **prod** | `/opt/pb-extension/server/public` | `/var/lib/pb-extension/tokens` | `/opt/pb-extension/var/log` | Pushing a `prod-v*` git tag |

Each env serves its own subdomain (`extension-dev.phoneburner.biz`, `extension.phoneburner.biz`) via its own Apache vhost, with its own `config.php` and its own OAuth tokens — they share nothing at runtime. The split was designed so that prod cutover (Phase 4) is purely a customer-side action; no token migration is required server-side.

A single VPS hosting both envs is the current setup. Splitting them onto separate hosts later is a config change, not a code change — every path is environment-scoped and the deploy script accepts `REPO_DIR` / `ENV` overrides.

---

## Prerequisites

Before starting, have these in hand:

1. **A Linux server** — Ubuntu 22.04 LTS or newer. Tested on Ubuntu 24.04. Minimum 1 vCPU, 2 GB RAM, 20 GB disk for a single-customer install. Scale up if onboarding multiple high-volume customers.
2. **Two DNS A records** pointed at the server's public IP:
   - `extension.{your-domain}` → prod
   - `extension-dev.{your-domain}` → dev
3. **SSH access** as a sudo-capable non-root user (referred to below as `$DEPLOY_USER` — currently `jeff` on the VPS, would be something like `pb-deploy` on AWS).
4. **GitHub access** to `jeffosness/pb-extension-dev` — either a deploy key, the GitHub App, or a Personal Access Token. The auto-deploy GitHub Actions workflows SSH in and `git pull`, so the server's git remote needs read access.
5. **OAuth apps registered** at HubSpot, Close, and Apollo (see [Section 11](#11-oauth-app-registration)).
6. **PhoneBurner admin access** to register webhook URLs (see [Section 10](#10-phoneburner-webhook-registration)).

---

## 1. System packages

```bash
sudo apt update
sudo apt install -y \
  apache2 \
  php php-cli php-curl php-json php-mbstring php-xml \
  libapache2-mod-php \
  certbot python3-certbot-apache \
  git unzip ufw
```

Enable required Apache modules:

```bash
sudo a2enmod ssl rewrite headers
sudo systemctl reload apache2
```

Disable the default Apache vhost so it doesn't intercept requests:

```bash
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

Configure the firewall:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'   # opens 80 + 443
sudo ufw --force enable
```

---

## 2. Directory layout

Create the per-environment trees. **Tokens live OUTSIDE the webroot** (`/var/lib/...`) — never in `/opt/.../public/...`.

```bash
# Webroots — repo clones land here in Step 3
sudo mkdir -p /opt/pb-extension-dev /opt/pb-extension

# Per-env log dirs
sudo mkdir -p /opt/pb-extension-dev/var/log /opt/pb-extension/var/log

# Token storage — keep outside the webroot, mode 0700
sudo mkdir -p /var/lib/pb-extension-dev/tokens/{pb,hubspot,close,apollo}
sudo mkdir -p /var/lib/pb-extension/tokens/{pb,hubspot,close,apollo}

# Ownership: www-data needs read/write everywhere PHP writes
sudo chown -R www-data:www-data /var/lib/pb-extension-dev /var/lib/pb-extension
sudo chown -R www-data:www-data /opt/pb-extension-dev/var /opt/pb-extension/var

# Tight permissions on token dirs (owner-only)
sudo chmod 0700 /var/lib/pb-extension-dev/tokens
sudo chmod 0700 /var/lib/pb-extension/tokens
sudo find /var/lib/pb-extension-dev/tokens -type d -exec chmod 0700 {} \;
sudo find /var/lib/pb-extension/tokens -type d -exec chmod 0700 {} \;
```

---

## 3. Clone the repo into both webroots

```bash
sudo git clone https://github.com/jeffosness/pb-extension-dev.git /opt/pb-extension-dev
sudo git clone https://github.com/jeffosness/pb-extension-dev.git /opt/pb-extension

# Apache needs to be able to read everything; PHP runs as www-data
sudo chown -R www-data:www-data /opt/pb-extension-dev /opt/pb-extension

# Git checkouts on Windows-touched repos sometimes flip executable bits;
# this normalizes them.
sudo -u www-data git -C /opt/pb-extension-dev config core.filemode false
sudo -u www-data git -C /opt/pb-extension config core.filemode false
```

Check out the right ref in each:

```bash
# Dev tracks main directly
sudo -u www-data git -C /opt/pb-extension-dev checkout main
sudo -u www-data git -C /opt/pb-extension-dev pull --ff-only origin main

# Prod tracks the latest prod-v* tag
sudo -u www-data git -C /opt/pb-extension git fetch --tags
sudo -u www-data git -C /opt/pb-extension checkout prod-v0.6.4   # adjust to current tag
```

---

## 4. `config.php` for each environment

`config.php` is **not** in git — each env gets a hand-edited copy with environment-specific values. Source of truth for shape: [`server/public/config.sample.php`](server/public/config.sample.php).

### Dev

```bash
sudo cp /opt/pb-extension-dev/server/public/config.sample.php \
        /opt/pb-extension-dev/server/public/config.php
sudo nano /opt/pb-extension-dev/server/public/config.php
```

Set:
- `BASE_URL` → `https://extension-dev.phoneburner.biz`
- `TOKENS_DIR` → `/var/lib/pb-extension-dev/tokens`
- `LOG_FILE` → `/opt/pb-extension-dev/var/log/app.log`
- `PB_WEBHOOK_SECRET` → the secret shared with PB's webhook config
- OAuth credentials (`HS_CLIENT_ID`, `HS_CLIENT_SECRET`, `CLOSE_*`, `APOLLO_*`)
- `DEBUG_MODE => false`

### Prod

```bash
sudo cp /opt/pb-extension/server/public/config.sample.php \
        /opt/pb-extension/server/public/config.php
sudo nano /opt/pb-extension/server/public/config.php
```

Set the same things, but with prod values:
- `BASE_URL` → `https://extension.phoneburner.biz`
- `TOKENS_DIR` → `/var/lib/pb-extension/tokens`
- `LOG_FILE` → `/opt/pb-extension/var/log/app.log`
- Same OAuth credentials (HubSpot/Close/Apollo apps support multiple redirect URIs, so dev and prod can share one app)

### Lock both files against accidental edits

```bash
sudo chmod 0600 /opt/pb-extension-dev/server/public/config.php
sudo chmod 0600 /opt/pb-extension/server/public/config.php
sudo chown www-data:www-data /opt/pb-extension-dev/server/public/config.php
sudo chown www-data:www-data /opt/pb-extension/server/public/config.php

# Make immutable — even root has to unlock with `chattr -i` to edit. Prevents
# accidental deletion via `git checkout config.php` or rm; also lets git pull
# safely run without trying to touch the file.
sudo chattr +i /opt/pb-extension-dev/server/public/config.php
sudo chattr +i /opt/pb-extension/server/public/config.php
```

To edit later:
```bash
sudo chattr -i <path>   # unlock
sudo nano <path>        # edit
sudo chattr +i <path>   # relock
lsattr <path>           # verify (look for 'i' flag)
```

---

## 5. Apache vhost configs

Each env has two vhost files: a port-80 `*.conf` that redirects to HTTPS, and a port-443 `*-le-ssl.conf` generated by certbot in Step 6. Both files need the same access rules so the redirect target works correctly.

### Dev vhost

`/etc/apache2/sites-available/extension-dev.phoneburner.biz.conf`:

```apache
<VirtualHost *:80>
    ServerName extension-dev.phoneburner.biz
    DocumentRoot /opt/pb-extension-dev/server/public

    <Directory /opt/pb-extension-dev/server/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/extension-dev-error.log
    CustomLog ${APACHE_LOG_DIR}/extension-dev-access.log combined
</VirtualHost>

# Protect everything under /metrics with Basic Auth
<Location "/metrics/">
  AuthType Basic
  AuthName "PhoneBurner Metrics"
  AuthUserFile /etc/apache2/.pb-metrics.htpasswd
  Require valid-user
</Location>

# Still block direct access to log files (even if someone is logged in)
<LocationMatch "^/metrics/.*\.log$">
  Require all denied
</LocationMatch>

# Defense-in-depth: block raw token/session/state storage at the vhost level
<LocationMatch "^/(tokens|sessions|daily_stats|user_settings)/">
  Require all denied
</LocationMatch>
```

### Prod vhost

`/etc/apache2/sites-available/extension.phoneburner.biz.conf` — identical to dev except for the docroot and ServerName:

```apache
<VirtualHost *:80>
    ServerName extension.phoneburner.biz
    DocumentRoot /opt/pb-extension/server/public

    <Directory /opt/pb-extension/server/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/extension-error.log
    CustomLog ${APACHE_LOG_DIR}/extension-access.log combined
</VirtualHost>

<Location "/metrics/">
  AuthType Basic
  AuthName "PhoneBurner Metrics"
  AuthUserFile /etc/apache2/.pb-metrics.htpasswd
  Require valid-user
</Location>

<LocationMatch "^/metrics/.*\.log$">
  Require all denied
</LocationMatch>

<LocationMatch "^/(tokens|sessions|daily_stats|user_settings)/">
  Require all denied
</LocationMatch>
```

Enable both:

```bash
sudo a2ensite extension-dev.phoneburner.biz.conf
sudo a2ensite extension.phoneburner.biz.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

The `-le-ssl.conf` companions get generated automatically by certbot in Step 6 and inherit the same `<Location>` and `<LocationMatch>` blocks. **If you ever edit one vhost file, edit both** — certbot can't merge upstream changes back into the HTTPS variant.

---

## 6. SSL certificates (Let's Encrypt via certbot)

Once both vhosts are enabled and DNS is propagated:

```bash
sudo certbot --apache \
  -d extension.phoneburner.biz \
  -d extension-dev.phoneburner.biz \
  --redirect \
  --agree-tos \
  -m {your-email@example.com}
```

`--redirect` adds an HTTP→HTTPS rewrite to the port-80 vhost. certbot also creates the `-le-ssl.conf` variants serving port 443.

Auto-renewal is set up by the certbot package via systemd timer. Verify:

```bash
sudo systemctl list-timers | grep certbot
sudo certbot renew --dry-run
```

---

## 7. Metrics dashboard Basic Auth

The metrics dashboard (`/metrics/crm_usage_dashboard.php`) is gated by Basic Auth — same credentials file used by both envs:

```bash
# First user — creates the file
sudo htpasswd -c /etc/apache2/.pb-metrics.htpasswd jeff

# Add more users without overwriting (-c would clobber)
sudo htpasswd /etc/apache2/.pb-metrics.htpasswd someoneelse

# Lock down ownership
sudo chown root:www-data /etc/apache2/.pb-metrics.htpasswd
sudo chmod 0640 /etc/apache2/.pb-metrics.htpasswd
```

Reload Apache after creating the file:

```bash
sudo systemctl reload apache2
```

Verify by hitting `https://extension.phoneburner.biz/metrics/crm_usage_dashboard.php` and `https://extension-dev.phoneburner.biz/metrics/crm_usage_dashboard.php` — both should prompt for credentials and render the dashboard after auth.

---

## 8. GitHub Actions deploy automation

The repo includes two workflows in `.github/workflows/`:

| Workflow | Triggered by | What it does |
|---|---|---|
| `deploy-dev.yml` | Push to `main` | SSH to host, run [`scripts/deploy-on-server.sh`](scripts/deploy-on-server.sh) with `REPO_DIR=/opt/pb-extension-dev BRANCH=main` |
| `deploy-prod.yml` | Push of a `prod-v*` tag | SSH to host, run the same script with `REPO_DIR=/opt/pb-extension BRANCH=<tag>` |

Both rely on the same SSH key + sudoers entry. Setup:

### 8a. SSH key for GitHub Actions

```bash
sudo -u $DEPLOY_USER ssh-keygen -t ed25519 -N "" -f ~/.ssh/gh-actions-deploy
sudo -u $DEPLOY_USER bash -c 'cat ~/.ssh/gh-actions-deploy.pub >> ~/.ssh/authorized_keys'
sudo -u $DEPLOY_USER chmod 0600 ~/.ssh/authorized_keys
```

Copy the **private key** (`~/.ssh/gh-actions-deploy`) into the repo's GitHub Actions secrets as `DEPLOY_SSH_KEY`.

Add these GitHub Actions repo secrets (Repo → Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `DEPLOY_SSH_HOST` | The server's hostname or IP |
| `DEPLOY_SSH_USER` | `$DEPLOY_USER` (the sudo-capable user) |
| `DEPLOY_SSH_KEY` | Contents of `~/.ssh/gh-actions-deploy` (the private key) |

### 8b. Sudoers entry

The deploy script runs as `www-data` (so it owns the resulting files) but is launched by `$DEPLOY_USER` through `sudo -n` (no password prompt). Set up the sudoers exception:

```bash
sudo visudo -f /etc/sudoers.d/pb-deploy
```

Add:

```
# Allow the deploy user to run the deploy script as root, passing
# REPO_DIR/BRANCH/ENV environment variables through. The SETENV: tag is
# required because sudo strips environment by default.
$DEPLOY_USER ALL=(root) NOPASSWD: SETENV: /bin/bash /opt/pb-extension-dev/scripts/deploy-on-server.sh
```

(Replace `$DEPLOY_USER` with the actual username.)

The path points at the dev clone of the script because that one's always-current; both env's deploys invoke it. The script then internally `cd`s to whatever `REPO_DIR` you pass.

### 8c. Test the dev workflow

Push any commit to `main` (or use the workflow's "Run workflow" button) and watch:

```bash
sudo journalctl -u apache2 -f &
tail -f /opt/pb-extension-dev/var/log/app.log &
```

Should see `[deploy]` log lines indicating success, then the next `state.php` request returning the new commit hash via `version.php`.

---

## 9. Cron jobs for cleanup

PHP doesn't reap its own stale files. Add to `$DEPLOY_USER`'s crontab (`crontab -e`):

```cron
# Cleanup old SSE presence files (daily at 3am UTC)
0 3 * * * find /opt/pb-extension-dev/server/public/metrics/sse_presence -type f -mtime +1 -delete 2>/dev/null
0 3 * * * find /opt/pb-extension/server/public/metrics/sse_presence -type f -mtime +1 -delete 2>/dev/null

# Cleanup old rate-limit cache (hourly)
0 * * * * find /opt/pb-extension-dev/server/public/cache -name 'rl_*.txt' -mmin +60 -delete 2>/dev/null
0 * * * * find /opt/pb-extension/server/public/cache -name 'rl_*.txt' -mmin +60 -delete 2>/dev/null

# Cleanup expired temp codes (every 15 min)
*/15 * * * * find /opt/pb-extension-dev/server/public/cache -name 'temp_code_*.json' -mmin +10 -delete 2>/dev/null
*/15 * * * * find /opt/pb-extension/server/public/cache -name 'temp_code_*.json' -mmin +10 -delete 2>/dev/null
```

Add log rotation (`/etc/logrotate.d/pb-extension`):

```
/opt/pb-extension-dev/var/log/*.log /opt/pb-extension/var/log/*.log {
    daily
    rotate 90
    compress
    missingok
    notifempty
    create 0664 www-data www-data
}
```

---

## 10. PhoneBurner webhook registration

Configure PhoneBurner to call our webhooks after each dialer event. **Do this once per environment** in the PB admin UI under Settings → Integrations → Webhooks (or via PB support if the UI isn't exposed):

| Event | Dev URL | Prod URL |
|---|---|---|
| `api_contact_displayed` | `https://extension-dev.phoneburner.biz/webhooks/contact_displayed.php` | `https://extension.phoneburner.biz/webhooks/contact_displayed.php` |
| `api_calldone` | `https://extension-dev.phoneburner.biz/webhooks/call_done.php` | `https://extension.phoneburner.biz/webhooks/call_done.php` |

The webhook URLs are **also embedded per-session at dialsession creation time** by the server code, so PB will receive them via the session payload regardless of admin-level registration. Admin-level config is the failsafe and the default for sessions that don't override.

---

## 11. OAuth app registration

For each integrated CRM provider, register an OAuth app and add **both** dev and prod redirect URIs (where the provider supports multiple URIs).

### HubSpot

- Create an app at https://developers.hubspot.com → Apps → Create app.
- Required scopes (paste into the scope picker):
  ```
  crm.lists.read
  crm.objects.companies.read
  crm.objects.contacts.read
  crm.objects.contacts.write
  crm.objects.deals.read
  crm.objects.owners.read
  crm.schemas.companies.read
  crm.schemas.contacts.read
  ```
- Redirect URIs (Authentication tab):
  - `https://extension.phoneburner.biz/api/crm/hubspot/oauth_hs_finish.php`
  - `https://extension-dev.phoneburner.biz/api/crm/hubspot/oauth_hs_finish.php`
- Copy the Client ID + Secret into both `config.php` files.

### Close

- Create an app at https://app.close.com/settings/developer/oauth-apps
- Required scopes: `read`, `write` (Close uses coarse scopes)
- Redirect URIs:
  - `https://extension.phoneburner.biz/api/crm/close/oauth_close_finish.php`
  - `https://extension-dev.phoneburner.biz/api/crm/close/oauth_close_finish.php`
- Copy Client ID + Secret into both `config.php` files.

### Apollo

- Create an app at https://developer.apollo.io → OAuth Registration
- Required scopes: as listed in the Apollo OAuth setup screen (contacts.read, sequences.read, etc.)
- Redirect URIs:
  - `https://extension.phoneburner.biz/api/crm/apollo/oauth_apollo_finish.php`
  - `https://extension-dev.phoneburner.biz/api/crm/apollo/oauth_apollo_finish.php`
- Copy Client ID + Secret into both `config.php` files.

**Apollo caveat:** Apollo's OAuth has had auth issues in the past requiring customers to fall back to manual API key generation. See [`reference_apollo_integration`](CLAUDE.md) and [`apollo_call_logger.php`](server/public/api/crm/apollo/apollo_call_logger.php) for context.

---

## 12. Verification checklist

After all of the above, walk through this list. Each step should succeed cleanly. If anything fails, the most useful diagnostic is `sudo tail -f /var/log/apache2/{extension-,extension-dev-}error.log` and `tail -f /opt/pb-extension-dev/var/log/app.log`.

- [ ] `curl https://extension.phoneburner.biz/health.php` returns `{"ok": true, ...}`
- [ ] `curl https://extension-dev.phoneburner.biz/health.php` returns `{"ok": true, ...}`
- [ ] `curl https://extension.phoneburner.biz/version.php` returns prod's deployed version + commit
- [ ] `curl https://extension-dev.phoneburner.biz/version.php` returns dev's version + commit (different from prod)
- [ ] `curl https://extension.phoneburner.biz/kb.php?format=md | head` returns the troubleshooting markdown (PB's AI agent endpoint)
- [ ] `curl -X POST https://extension.phoneburner.biz/api/core/state.php -H "Content-Type: application/json" -d '{"client_id":"verify-test"}'` returns `{"ok": true, "pb_ready": false, ...}`
- [ ] Hitting `https://extension.phoneburner.biz/metrics/crm_usage_dashboard.php` in a browser prompts for Basic Auth, accepts your htpasswd credentials, and renders the dashboard
- [ ] Hitting `https://extension.phoneburner.biz/tokens/anything` returns 403 (token dir blocked at vhost level)
- [ ] Hitting `https://extension.phoneburner.biz/sessions/anything` returns 403
- [ ] Loading the extension in Chrome (Developer Options toggle → prod), pasting a PAT, and clicking Connect HubSpot completes the OAuth flow and lands back on the extension popup with "Connected"
- [ ] Launching a dial session against a real HubSpot record produces a session in PB, the Follow widget appears in the CRM tab, and `call_done` webhooks land in `/opt/pb-extension/var/log/app.log`

---

## Migration: moving to a new server

When the time comes to move off the current VPS — whether to a larger box, into AWS, or anywhere else — the migration is:

1. **Stand up the new server** by following Sections 1–12 above. Both envs come up empty (no tokens, no sessions).
2. **Stop writes on the old server.** Pause cron, stop accepting deploys.
3. **Sync token storage:**
   ```bash
   # On the old server
   sudo tar czf /tmp/pb-tokens.tar.gz -C /var/lib pb-extension pb-extension-dev
   # Transfer to new server, then on new server:
   sudo tar xzf /tmp/pb-tokens.tar.gz -C /var/lib
   sudo chown -R www-data:www-data /var/lib/pb-extension /var/lib/pb-extension-dev
   ```
4. **Sync active sessions** (only matters if there are in-flight dial sessions you can't drain):
   ```bash
   # Per env:
   sudo rsync -av /opt/pb-extension/server/public/sessions/ \
     newserver:/opt/pb-extension/server/public/sessions/
   sudo rsync -av /opt/pb-extension-dev/server/public/sessions/ \
     newserver:/opt/pb-extension-dev/server/public/sessions/
   ```
   Or wait for active sessions to end (typical dial session is < 2 hours).
5. **Flip DNS** — point `extension.phoneburner.biz` and `extension-dev.phoneburner.biz` A records at the new server's IP. SSL certs auto-renew via certbot on the new host.
6. **Sanity-test** with the verification checklist above before announcing the cutover.

**No customer action required.** OAuth tokens are server-side; the extension's `client_id` is browser-side; webhooks are embedded per-session at creation time. A clean DNS cutover with token-dir sync produces zero customer-visible disruption.

### Migrating to a non-default domain

If the new server uses a domain other than `extension.phoneburner.biz` / `extension-dev.phoneburner.biz`, three additional changes are needed:

1. **Override the CORS whitelist.** Add to each `config.php` (above the `return [...];` array):
   ```php
   define('PB_CORS_ORIGINS', [
     'https://your-new-prod-domain',
     'https://your-new-dev-domain',
   ]);
   ```
   This replaces the hardcoded defaults in [`server/public/api/core/bootstrap.php`](server/public/api/core/bootstrap.php). Skip if you're keeping the canonical domains.
2. **Update the extension's `host_permissions`** in [`chrome-extension/manifest.json`](chrome-extension/manifest.json) to include the new domain, then ship a new extension version through the Web Store. Without this, the extension can't make API calls to the new backend.
3. **Re-register all OAuth redirect URIs** at HubSpot, Close, and Apollo. Existing OAuth tokens minted against old redirect URIs will fail to refresh under the new domain — customers will need to reconnect each provider.

If migrating into AWS specifically, the only path that needs thought is the deploy automation — the GitHub Actions SSH model works against any host with a reachable port 22 and a sudo-capable user, but PB's AWS ops conventions may prefer CodeDeploy or a similar in-VPC mechanism. The deploy logic itself ([`scripts/deploy-on-server.sh`](scripts/deploy-on-server.sh)) is pure bash and runs anywhere.

---

## Appendix: useful commands on a running server

```bash
# What version is each env on?
curl -s https://extension.phoneburner.biz/version.php | jq
curl -s https://extension-dev.phoneburner.biz/version.php | jq

# Watch live app log for an env
sudo tail -f /opt/pb-extension/var/log/app.log
sudo tail -f /opt/pb-extension-dev/var/log/app.log

# Inspect a customer's connection state (replace client_id)
sudo ls -la /var/lib/pb-extension/tokens/pb/
sudo cat /var/lib/pb-extension/tokens/pb/{client_id}.json | jq

# Disk usage by env
sudo du -sh /opt/pb-extension /opt/pb-extension-dev /var/lib/pb-extension /var/lib/pb-extension-dev

# Force a manual deploy (skips GitHub Actions — useful during incident response)
sudo -u www-data bash /opt/pb-extension-dev/scripts/deploy-on-server.sh \
  REPO_DIR=/opt/pb-extension-dev BRANCH=main ENV=dev

# Roll back prod to a previous tag
sudo -u www-data git -C /opt/pb-extension fetch --tags
sudo -u www-data git -C /opt/pb-extension checkout prod-v0.6.3   # previous tag

# Check config.php immutability
lsattr /opt/pb-extension/server/public/config.php
lsattr /opt/pb-extension-dev/server/public/config.php
```

---

**Last updated:** 2026-06-08
**Reflects setup deployed on:** Jeff's VPS, both dev and prod environments under `extension-dev.phoneburner.biz` and `extension.phoneburner.biz`.
**Maintained by:** keep this in sync with [`scripts/deploy-on-server.sh`](scripts/deploy-on-server.sh), [`server/public/config.sample.php`](server/public/config.sample.php), and `.github/workflows/deploy-*.yml`.
