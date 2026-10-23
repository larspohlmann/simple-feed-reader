#!/usr/bin/env bash
set -euo pipefail

# Unit test for #1750: `up --build` alone reuses the cached base images, so a
# patched PHP never reached an installed stack. compose is stubbed and records
# every call.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

calls=$(mktemp)
trap 'rm -f "${calls}"' EXIT

# shellcheck disable=SC2329 # refresh_images calls it by name
compose() { printf '%s\n' "$*" >> "${calls}"; }

refresh_images compose \
  || fail 'refresh_images failed although every compose call succeeded'
grep -q -e '^pull --ignore-buildable' "${calls}" \
  || fail "the image services were not pulled: $(cat "${calls}")"
grep -q -e '^build --pull' "${calls}" \
  || fail "the built images were not rebuilt on fresh bases: $(cat "${calls}")"

: > "${calls}"
compose() {
  printf '%s\n' "$*" >> "${calls}"
  [ "$1" != 'pull' ]
}

if refresh_images compose; then
  fail 'refresh_images reported success although the pull failed'
fi
grep -q -e '^build --pull' "${calls}" \
  || fail "a failed pull skipped the rebuild: $(cat "${calls}")"

echo 'update-pulls-base-images: OK'
