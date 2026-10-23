#!/usr/bin/env bash
set -euo pipefail

# Unit test for #1752: a release checkout pulls its published images, anything
# else builds. prod_compose is stubbed and records every call.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

REPO_ROOT="${work}/repo"
git init --quiet "${REPO_ROOT}"
git -C "${REPO_ROOT}" -c user.name=test -c user.email=test@example.invalid \
  commit --quiet --allow-empty -m 'release'

calls="${work}/calls"
# shellcheck disable=SC2317,SC2329 # prepare_prod_images calls it by name
prod_compose() { printf '%s\n' "$*" >> "${calls}"; }

: > "${calls}"
prepare_prod_images >/dev/null 2>&1
[ -z "${APP_IMAGE_TAG}" ] || fail "untagged: APP_IMAGE_TAG='${APP_IMAGE_TAG}', expected none"
[ "${PROD_BUILD_OPTION}" = --build ] || fail "unreleased: option '${PROD_BUILD_OPTION}'"
if grep -qx 'pull --quiet' "${calls}"; then fail 'an unreleased checkout pulled release images'; fi
grep -q '^build --pull' "${calls}" || fail "an unreleased checkout was not built: $(cat "${calls}")"

git -C "${REPO_ROOT}" tag v1.2.3-dev.4
git -C "${REPO_ROOT}" tag v1.2.3

: > "${calls}"
prepare_prod_images >/dev/null 2>&1
[ "${APP_IMAGE_TAG}" = v1.2.3 ] || fail "a dev tag beside the release won: '${APP_IMAGE_TAG}'"
[ "${PROD_BUILD_OPTION}" = --no-build ] || fail "pulled release: option '${PROD_BUILD_OPTION}'"
grep -qx 'pull --quiet' "${calls}" || fail "release images not pulled: $(cat "${calls}")"
if grep -q '^build' "${calls}"; then fail "a pulled release was built: $(cat "${calls}")"; fi

# shellcheck disable=SC2317,SC2329
prod_compose() {
  printf '%s\n' "$*" >> "${calls}"
  [ "$*" != 'pull --quiet' ]
}
: > "${calls}"
prepare_prod_images >/dev/null 2>&1
[ "${PROD_BUILD_OPTION}" = --build ] || fail "failed pull: option '${PROD_BUILD_OPTION}'"
grep -q '^build --pull' "${calls}" || fail "a failed pull skipped the build: $(cat "${calls}")"

echo 'release-images: OK'
