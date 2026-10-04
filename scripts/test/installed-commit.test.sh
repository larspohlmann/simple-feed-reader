#!/usr/bin/env bash
set -euo pipefail

# Unit test for the record of what the stacks run (#1378). update.sh checks the
# release out before it rebuilds the stacks, so a failed build left the
# checkout on the new tag and the next run said "Nothing to do". The decision
# must come from the record a finished update writes, never from the checkout.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

REPO_ROOT="${work}/repo"
git init --quiet "${REPO_ROOT}"
commit() {
  git -C "${REPO_ROOT}" -c user.name=test -c user.email=test@example.invalid \
    commit --quiet --allow-empty -m "$1"
}
commit 'first release'
git -C "${REPO_ROOT}" tag v1.0.0
commit 'second release'
git -C "${REPO_ROOT}" tag v1.1.0
commit 'after the release'
untagged=$(git -C "${REPO_ROOT}" rev-parse HEAD)

expect_version() {
  local actual
  actual=$(installed_version)
  [ "${actual}" = "$1" ] || fail "$2: installed_version said '${actual}', expected '$1'"
}

# --- no record: an install from before #1378 is not known to be current ------
git -C "${REPO_ROOT}" checkout --quiet v1.0.0
if runs_ref v1.1.0; then fail 'no record, yet v1.1.0 counted as running'; fi
expect_version '(unknown)' 'no record'

# --- the failed update: checkout on the new tag, nothing recorded ------------
git -C "${REPO_ROOT}" checkout --quiet v1.1.0
if runs_ref v1.1.0; then fail 'a checkout alone made v1.1.0 count as running'; fi

# --- recorded on v1.0.0, then the checkout moved on --------------------------
git -C "${REPO_ROOT}" checkout --quiet v1.0.0
record_installed_commit
git -C "${REPO_ROOT}" checkout --quiet v1.1.0
if runs_ref v1.1.0; then fail 'the v1.0.0 record made v1.1.0 count as running'; fi
expect_version 'v1.0.0' 'record on v1.0.0, checkout on v1.1.0'

# --- recorded on v1.1.0 -------------------------------------------------------
record_installed_commit
runs_ref v1.1.0 || fail 'the v1.1.0 record did not make v1.1.0 count as running'
expect_version 'v1.1.0' 'record on v1.1.0'

# --- recorded off a tag -------------------------------------------------------
git -C "${REPO_ROOT}" checkout --quiet "${untagged}"
record_installed_commit
expect_version '(unreleased)' 'record on an untagged commit'

# --- a ref that does not exist never counts, and does not exit the shell -----
if runs_ref no-such-ref; then fail 'a missing ref counted as running'; fi

printf 'installed-commit: all checks passed\n'
