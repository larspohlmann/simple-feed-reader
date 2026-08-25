#!/usr/bin/env bash
set -euo pipefail

# Update an existing simple-feed-reader install to the latest release.
#
#   ./scripts/update.sh
#   ./scripts/update.sh --ref feature/430-installer-output
#
# It checks out the newest release tag -- or the ref given with --ref (or
# SFR_REF), which is how a branch is tried on a test instance before it is
# released -- then updates the production stack, the development stack, or
# both, whichever is installed. It never deletes data and never touches a
# working tree that has uncommitted changes.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

usage() {
  cat <<'EOF'
Usage: update.sh [--ref <branch-or-tag>]

Update an existing simple-feed-reader install and its stacks. It checks out the
newest release tag (or the ref given with --ref), then updates whichever stack
is installed -- production, development, or both. It never deletes data and
stops if the working tree has uncommitted changes.

Options:
  --ref <branch-or-tag>   Check out this ref instead of the newest release tag.
                          A pre-release tag (for example v0.6.2-dev.23) is not a
                          release tag, so pass it here to update to it.
  -h, --help              Show this help and exit.

Environment:
  SFR_REF                 Same as --ref.
EOF
}
handle_help_request "$@"

notes_start
parse_ref_args "$@"

ensure_docker

say 'Fetching from origin ...'
git -C "${REPO_ROOT}" fetch --tags --quiet origin

current=$(current_version)

# An explicit ref skips the release lookup: it may well have no release tag,
# which is the reason to ask for it.
if [ -n "${REF}" ]; then
  target="${REF}"
else
  target=$(latest_release_tag)
  [ -n "${target}" ] || die 'No release tag (vX.Y.Z) found on main. See docs/releasing.md.'
  if [ "${current}" = "${target}" ]; then
    ok "Already on the latest release (${target}). Nothing to do."
    exit 0
  fi
fi

# Never discard someone's edits. A dirty tree stops the update.
if [ -n "$(git -C "${REPO_ROOT}" status --porcelain)" ]; then
  die 'Your working tree has uncommitted changes. Commit, stash, or discard them, then re-run.'
fi

say "Updating ${current} -> ${target} ..."
lockfile_before=$(lockfile_blob)
if [ -n "${REF}" ]; then
  checkout_requested_ref "${REF}"
  # A branch moves on, so checking it out is not enough to be current. A tag
  # has no upstream and the merge is skipped, which is why the failure is
  # ignored rather than reported.
  git -C "${REPO_ROOT}" merge --ff-only --quiet "origin/${REF}" 2>/dev/null || true
else
  git -C "${REPO_ROOT}" checkout --quiet "${target}"
  record_installed_release "${target}"
fi
lockfile_after=$(lockfile_blob)

updated_prod=0
updated_dev=0

# --- production stack -------------------------------------------------------
# A .env.prod marks a production install; prod-start.sh is idempotent and is
# exactly the update procedure (rebuild, migrate, health check). Its closing
# block is deferred: the dev stack below may still have minutes of work, and
# the blocks belong at the end of the run.
if [ -f "${ENV_PROD_FILE}" ]; then
  say 'Updating the production stack ...'
  SFR_DEFER_SUMMARY=1 "${REPO_ROOT}/scripts/prod-start.sh"
  updated_prod=1
fi

# --- development stack ------------------------------------------------------
# Any php container (running or stopped) under the dev project marks a dev
# install. Both stacks can exist on a developer machine; update both.
if [ -n "$(compose ps -aq php 2>/dev/null)" ]; then
  say 'Updating the development stack ...'
  run_step 'Rebuilding images where their definitions changed' compose up -d --build

  # Reinstall the frontend packages only when the lockfile actually changed;
  # the install runs into a named volume and is the slow part of an update.
  if [ "${lockfile_before}" != "${lockfile_after}" ]; then
    run_step 'Frontend lockfile changed -- refreshing node_modules' \
      compose run --rm frontend npm ci
  fi

  run_step 'Installing backend dependencies' \
    compose exec -T php composer install --no-interaction
  run_step 'Applying database migrations' \
    compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
  # It may have started before the schema existed (first install).
  compose restart worker >/dev/null

  if ! wait_for_health "${DEV_HEALTH_URL}"; then
    warn 'The API did not report healthy in time. Check:  docker compose logs -f php nginx worker'
  fi
  updated_dev=1
fi

if [ "${updated_prod}" -eq 0 ] && [ "${updated_dev}" -eq 0 ]; then
  warn 'No installed stack found (no .env.prod, no dev containers).'
  say "The checkout is now on ${target}."
  say 'Start a stack with ./scripts/prod-start.sh (production) or ./scripts/install-dev.sh (development).'
  exit 0
fi

ok "Updated ${current} -> ${target}."

# The closing blocks, last: one per stack that was updated. print_notes empties
# the collection, so the warnings appear under the first block only.
if [ "${updated_prod}" -eq 1 ]; then
  print_prod_summary
fi
if [ "${updated_dev}" -eq 1 ]; then
  print_summary
fi
