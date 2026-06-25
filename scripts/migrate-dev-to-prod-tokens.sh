#!/bin/bash
# scripts/migrate-dev-to-prod-tokens.sh
#
# One-shot script to migrate customer tokens + user settings from the
# dev environment to the prod environment during the Phase 4 cutover.
#
# WHEN TO RUN:
#   After PR #110 (v0.7.0 prod-default flip) deploys to prod via a
#   `prod-v*` tag, and BEFORE submitting the v0.7.0 zip to the Chrome
#   Web Store. The intent: by the time customers auto-update to v0.7.0
#   and their extension starts hitting the prod backend, their tokens
#   are already in place on prod — so the popup shows them as already-
#   connected without requiring a re-paste of the PAT or re-OAuth of
#   HubSpot/Apollo. The only re-auth the cutover requires is Close.
#
# WHAT GETS MIGRATED:
#   - PhoneBurner PATs              — opaque tokens, env-agnostic
#   - HubSpot OAuth tokens          — same OAuth app on both envs
#   - Apollo OAuth tokens           — same OAuth app on both envs
#   - user_settings/                — per-user prefs (phone preference,
#                                     auto-collapse, etc.)
#   - daily_stats/                  — per-user operational metrics;
#                                     preserves dashboard continuity
#
# WHAT IS NOT MIGRATED (and why):
#   - Close tokens
#       Dev and prod use SEPARATE Close OAuth apps (Close doesn't
#       support multi-URI per app). Tokens minted by the dev app are
#       NOT valid against the prod app. Customers using Close must
#       reconnect on prod via OAuth — this is the only re-auth the
#       cutover requires.
#   - sessions/
#       Active dial-session state, tied to in-flight calls whose
#       webhooks point at dev. Leave them on dev; they'll drain
#       naturally as sessions end.
#   - cache/
#       Rate-limit counters, temp codes, and per-org caches —
#       env-scoped by design, regenerated on demand.
#
# SAFETY GUARANTEES:
#   - `rsync --ignore-existing` everywhere — any file that already
#     exists on prod (e.g., from your own env-toggle testing, or a
#     customer who manually flipped to prod before cutover) is NOT
#     overwritten.
#   - Idempotent: safe to re-run. Subsequent runs are no-ops for
#     already-migrated files; net-new dev files do get migrated.
#   - Ownership reset to www-data:www-data on the prod side after
#     copy (defensive — rsync should preserve it, but the explicit
#     chown removes any ambiguity).
#   - --dry-run mode prints what WOULD be copied without making any
#     changes. Use this first to sanity-check the file counts.
#
# USAGE:
#   sudo bash scripts/migrate-dev-to-prod-tokens.sh --dry-run
#   sudo bash scripts/migrate-dev-to-prod-tokens.sh

set -euo pipefail

# -----------------------------------------------------------------------------
# Args + paths
# -----------------------------------------------------------------------------
DRY_RUN_FLAG=""
DRY_RUN_LABEL=""
if [[ "${1:-}" == "--dry-run" ]]; then
  DRY_RUN_FLAG="--dry-run"
  DRY_RUN_LABEL=" [DRY RUN]"
fi

DEV_REPO=/opt/pb-extension-dev
PROD_REPO=/opt/pb-extension
DEV_TOKENS=/var/lib/pb-extension-dev/tokens
PROD_TOKENS=/var/lib/pb-extension/tokens

# Sanity check: all four root paths must exist.
for path in "$DEV_REPO" "$PROD_REPO" "$DEV_TOKENS" "$PROD_TOKENS"; do
  if [[ ! -d "$path" ]]; then
    echo "[migrate] ERROR: required directory missing: $path"
    echo "[migrate] aborting — make sure both envs are deployed and tokens dirs exist."
    exit 1
  fi
done

echo "[migrate] starting dev -> prod token migration$DRY_RUN_LABEL"
echo "[migrate] $(date -u +%FT%TZ)"
echo ""

# -----------------------------------------------------------------------------
# Helper: migrate a single subdirectory.
# Args: $1 source, $2 destination, $3 friendly label.
# -----------------------------------------------------------------------------
migrate_subdir() {
  local src="$1"
  local dst="$2"
  local label="$3"

  if [[ ! -d "$src" ]]; then
    echo "[migrate] skip $label — source missing ($src)"
    return
  fi

  if [[ ! -d "$dst" ]]; then
    if [[ -n "$DRY_RUN_FLAG" ]]; then
      echo "[migrate] would create $label target dir: $dst"
    else
      echo "[migrate] creating $label target dir: $dst"
      mkdir -p "$dst"
      chown www-data:www-data "$dst"
    fi
  fi

  local before
  before=$(find "$dst" -type f 2>/dev/null | wc -l)

  # `rsync -av --ignore-existing` copies new files only; preserves perms.
  # Trailing slashes on src/dst matter — they keep us from creating a
  # nested duplicate dir.
  rsync -av $DRY_RUN_FLAG --ignore-existing "$src/" "$dst/" | tail -8

  local after
  after=$(find "$dst" -type f 2>/dev/null | wc -l)
  local copied=$((after - before))
  echo "[migrate] $label: $copied new file(s) copied (existing files on prod were left untouched)"
  echo ""
}

# -----------------------------------------------------------------------------
# 1. PhoneBurner PATs
# -----------------------------------------------------------------------------
migrate_subdir "$DEV_TOKENS/pb" "$PROD_TOKENS/pb" "PhoneBurner PATs"

# -----------------------------------------------------------------------------
# 2. HubSpot OAuth tokens
#    Same OAuth app on both envs (PB-portal HubSpot app supports both
#    dev + prod redirect URIs). Tokens are interchangeable.
# -----------------------------------------------------------------------------
migrate_subdir "$DEV_TOKENS/hubspot" "$PROD_TOKENS/hubspot" "HubSpot OAuth tokens"

# -----------------------------------------------------------------------------
# 3. Apollo OAuth tokens
#    Same OAuth app on both envs (single Apollo app with comma-separated
#    redirect URIs). Tokens are interchangeable.
# -----------------------------------------------------------------------------
migrate_subdir "$DEV_TOKENS/apollo" "$PROD_TOKENS/apollo" "Apollo OAuth tokens"

# -----------------------------------------------------------------------------
# 4. User settings (per-user preferences)
#    e.g., HubSpot preferred phone field, follow-widget auto-collapse.
# -----------------------------------------------------------------------------
migrate_subdir "$DEV_REPO/server/public/user_settings" \
               "$PROD_REPO/server/public/user_settings" \
               "User settings"

# -----------------------------------------------------------------------------
# 5. Daily stats
#    Per-user operational metrics. Preserves the customer-visible
#    stats continuity in the dashboard rather than reset-to-zero on cutover.
# -----------------------------------------------------------------------------
migrate_subdir "$DEV_REPO/server/public/daily_stats" \
               "$PROD_REPO/server/public/daily_stats" \
               "Daily stats"

# -----------------------------------------------------------------------------
# Reset ownership across all destination dirs to guarantee www-data
# owns everything (rsync should preserve, but explicit is safer when
# the chown might be wrong on dev for historical reasons).
# -----------------------------------------------------------------------------
if [[ -z "$DRY_RUN_FLAG" ]]; then
  for path in \
    "$PROD_TOKENS/pb" \
    "$PROD_TOKENS/hubspot" \
    "$PROD_TOKENS/apollo" \
    "$PROD_REPO/server/public/user_settings" \
    "$PROD_REPO/server/public/daily_stats"; do
    [[ -d "$path" ]] && chown -R www-data:www-data "$path"
  done
  echo "[migrate] ownership reset to www-data:www-data across all destination dirs"
fi

echo ""
echo "[migrate] done$DRY_RUN_LABEL"
echo "[migrate]"
echo "[migrate] Close tokens were intentionally NOT migrated. Customers using"
echo "[migrate] Close will reconnect once via OAuth against the new public prod"
echo "[migrate] Close app — that's the only customer-facing re-auth the cutover"
echo "[migrate] requires."
