#!/bin/bash
# scripts/deploy-on-server.sh
#
# Runs on the production VPS to pull the latest main and refresh the live site.
# Invoked by .github/workflows/deploy-prod.yml via SSH after a merge to main.
#
# Handles known quirks:
#   - version.php always shows as modified after deploy (regenerated at runtime),
#     so it must be reverted before `git pull` to avoid merge conflicts.
#   - config.php is locked with `chattr +i`, so `git pull` cannot touch it
#     (this is intentional — server-specific config is not in version control).

set -euo pipefail

REPO_DIR="${REPO_DIR:-/opt/pb-extension-dev}"
BRANCH="${BRANCH:-main}"

cd "$REPO_DIR"

echo "[deploy] $(date -u +%FT%TZ) starting deploy of $BRANCH"
echo "[deploy] HEAD before: $(git rev-parse --short HEAD)"

# Revert version.php — it's regenerated at runtime and always shows as modified
git checkout -- server/public/version.php 2>/dev/null || true

# Fast-forward only — fails loudly if the server has diverged from origin,
# rather than silently merging or rewriting history.
git pull --ff-only origin "$BRANCH"

echo "[deploy] HEAD after:  $(git rev-parse --short HEAD)"
echo "[deploy] done"
