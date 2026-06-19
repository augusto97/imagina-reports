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

BASE_PATH="${BASE_PATH:-$(cd "$(dirname "$0")/.." && pwd)}"
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
php "${CURRENT}/artisan" queue:restart

echo "✓ Deployed ${RELEASE_DIR}"
