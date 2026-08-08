#!/usr/bin/env bash
set -euo pipefail

# Update an existing simple-feed-reader install to the latest release.
#
#   ./scripts/update.sh
#
# It checks out the newest release tag, then updates the production stack, the
# development stack, or both -- whichever is installed. It never deletes data
# and never touches a working tree that has uncommitted changes.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

say 'Fetching release tags ...'
git -C "${REPO_ROOT}" fetch --tags --quiet origin

latest=$(latest_release_tag)
[ -n "${latest}" ] || die 'No release tag (vX.Y.Z) found on main. See docs/releasing.md.'

current=$(current_version)
if [ "${current}" = "${latest}" ]; then
  ok "Already on the latest release (${latest}). Nothing to do."
  exit 0
fi

# Never discard someone's edits. A dirty tree stops the update.
if [ -n "$(git -C "${REPO_ROOT}" status --porcelain)" ]; then
  die 'Your working tree has uncommitted changes. Commit, stash, or discard them, then re-run.'
fi

say "Updating ${current} -> ${latest} ..."
lockfile_before=$(lockfile_blob)
git -C "${REPO_ROOT}" checkout --quiet "${latest}"
lockfile_after=$(lockfile_blob)

updated_any=0

# --- production stack -------------------------------------------------------
# A .env.prod marks a production install; prod-start.sh is idempotent and is
# exactly the update procedure (rebuild, migrate, health check).
if [ -f "${ENV_PROD_FILE}" ]; then
  say 'Updating the production stack ...'
  "${REPO_ROOT}/scripts/prod-start.sh"
  updated_any=1
fi

# --- development stack ------------------------------------------------------
# Any php container (running or stopped) under the dev project marks a dev
# install. Both stacks can exist on a developer machine; update both.
if [ -n "$(compose ps -aq php 2>/dev/null)" ]; then
  say 'Updating the development stack ...'
  say 'Rebuilding images where their definitions changed ...'
  compose up -d --build

  # Reinstall the frontend packages only when the lockfile actually changed;
  # the install runs into a named volume and is the slow part of an update.
  if [ "${lockfile_before}" != "${lockfile_after}" ]; then
    say 'Frontend lockfile changed -- refreshing node_modules ...'
    compose run --rm frontend npm ci
  fi

  say 'Installing backend dependencies ...'
  compose exec -T php composer install --no-interaction
  say 'Applying database migrations ...'
  compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
  # It may have started before the schema existed (first install).
  compose restart worker

  if wait_for_health "${DEV_HEALTH_URL}"; then
    ok "Development stack updated."
  else
    warn 'The API did not report healthy in time. Check:  docker compose logs -f php nginx worker'
  fi
  print_summary
  updated_any=1
fi

if [ "${updated_any}" -eq 0 ]; then
  warn 'No installed stack found (no .env.prod, no dev containers).'
  say "The checkout is now on ${latest}."
  say 'Start a stack with ./scripts/prod-start.sh (production) or ./scripts/install-dev.sh (development).'
else
  ok "Updated ${current} -> ${latest}."
fi
