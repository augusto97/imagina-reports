#!/usr/bin/env bash
#
# deploy.sh — mirrors UpdateManager steps 5–9 (CLAUDE.md §12.3/§12.5) for the
# operator's own pushes via ServerAvatar Git deploy. Atomic: builds the new
# release beside the old and flips the `current` symlink. Node is NOT required on
# the server — assets are built in CI; here we only wire and cache.
#
# Usage (from the release directory that was just checked out):
#   RELEASE_DIR=/home/user/imagina-reports/releases/<ts> ./deploy.sh
#
set -euo pipefail

# The script lives at <base>/releases/<ts>_<ver>/deploy.sh, so the base (which
# holds releases/, shared/ and the current symlink) is TWO levels up. Callers
# (the in-app updater) pass BASE_PATH explicitly; this default is for standalone runs.
BASE_PATH="${BASE_PATH:-$(cd "$(dirname "$0")/../.." && pwd)}"
SHARED="${BASE_PATH}/shared"
CURRENT="${BASE_PATH}/current"
RELEASE_DIR="${RELEASE_DIR:?Set RELEASE_DIR to the new release directory}"

echo "→ Linking shared (.env, storage) into the release"
ln -sfn "${SHARED}/.env" "${RELEASE_DIR}/.env"
rm -rf "${RELEASE_DIR}/storage"
ln -sfn "${SHARED}/storage" "${RELEASE_DIR}/storage"

echo "→ Migrating"
php "${RELEASE_DIR}/artisan" migrate --force

echo "→ Linking public storage (white-label logos, uploads)"
php "${RELEASE_DIR}/artisan" storage:link || true

echo "→ Caching config/routes/views"
php "${RELEASE_DIR}/artisan" config:cache
php "${RELEASE_DIR}/artisan" route:cache
php "${RELEASE_DIR}/artisan" view:cache

echo "→ Flipping the current symlink (atomic)"
ln -sfn "${RELEASE_DIR}" "${CURRENT}"

echo "→ Restarting the queue worker (final step)"
# Horizon (CLAUDE.md §2) does NOT reload its workers' code on `queue:restart` — the
# long-running master keeps the old autoloader and respawns stale workers. Supervisor
# (ServerAvatar) restarts the master with the new release on `horizon:terminate`, so the
# code that runs report generation actually updates. `queue:restart` is the fallback for
# plain workers / when Horizon isn't running.
php "${CURRENT}/artisan" horizon:terminate || true
php "${CURRENT}/artisan" queue:restart

echo "✓ Deployed ${RELEASE_DIR}"
