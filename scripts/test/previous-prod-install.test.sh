#!/usr/bin/env bash
set -euo pipefail

# Unit test for what the production installer does when an EARLIER production
# install is still on the machine (issue #272).
#
# The case is not exotic: `docker compose down` and prod-stop.sh both keep the
# named volumes on purpose, so a second install meets the first install's
# mysql-data. MySQL creates its user only while it initialises an empty data
# directory, so that volume still holds the first install's password while the
# installer has just generated a new one -- the install then dies at the first
# query with "Access denied for user". The installer must therefore either
# clear the machine or stop, and it must never continue over those volumes.
#
# docker is stubbed. What the real daemon holds is a property of the machine
# the test runs on, not of the functions under test; the stub also records
# every call, because WHICH docker command runs (and in which order) is the
# behaviour being tested.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

# --- stubs ------------------------------------------------------------------
# Both files are read and written by subshells ($( ) around the volume list),
# so the state lives on disk rather than in a shell variable.
volumes_file="${work}/volumes"
containers_file="${work}/containers"
calls="${work}/calls"

: > "${volumes_file}"
: > "${containers_file}"
: > "${calls}"

docker() {
  printf '%s\n' "$*" >> "${calls}"
  case "$1 $2" in
    'volume ls') cat "${volumes_file}" ;;
    'ps -aq') cat "${containers_file}" ;;
    'volume rm') : ;;
    'rm -f') : ;;
    *) fail "the stub was asked for an unexpected docker command: $*" ;;
  esac
}

machine_holds() {
  printf '%s\n' "$1" > "${volumes_file}"
  printf '%s\n' "$2" > "${containers_file}"
  : > "${calls}"
}

# The answer to the removal question, and whether there is a terminal to ask
# it on -- the two inputs that decide which path is taken.
answer_is_yes() { prompt_confirm() { return 0; }; can_prompt() { return 0; }; }
answer_is_no()  { prompt_confirm() { return 1; }; can_prompt() { return 0; }; }
no_terminal()   { prompt_confirm() { return 1; }; can_prompt() { return 1; }; }

told="${work}/told"

# The function stops the install with die() on two of its three paths, so it
# runs in a subshell and its exit status is the assertion.
run_handler() {
  ( handle_previous_prod_install ) > "${told}" 2>&1
}

assert_told() {
  grep -q -- "$1" "${told}" || fail "the operator was not told about '$1'"
}

assert_not_called() {
  ! grep -q -- "$1" "${calls}" || fail "docker should not have been asked to '$1'"
}

# --- 1. a clean machine installs, and is not asked anything ------------------
machine_holds '' ''
answer_is_no
run_handler || fail 'a clean machine must not stop the install'
assert_not_called 'volume rm'
[ ! -s "${told}" ] || fail 'a clean machine must say nothing at all'

# --- 2. yes: the containers go first, then the volumes -----------------------
# docker refuses to remove a volume any container still claims, even a stopped
# one -- and a stopped one is exactly what a previous install leaves.
machine_holds 'simple-feed-reader-prod_mysql-data' 'c0ffee'
answer_is_yes
run_handler || fail 'after removing the leftovers the install must continue'
grep -q 'rm -f c0ffee' "${calls}" || fail 'the old containers were not removed'
grep -q 'volume rm simple-feed-reader-prod_mysql-data' "${calls}" \
  || fail 'the old volumes were not removed'
[ "$(grep -n 'rm -f c0ffee' "${calls}" | cut -d: -f1)" \
  -lt "$(grep -n 'volume rm' "${calls}" | cut -d: -f1)" ] \
  || fail 'the containers must be removed before the volumes'

# --- 3. no: the install stops, with both ways forward spelled out ------------
# Continuing would build the images, generate the secrets and only then fail
# at the migration step, which is the bug this whole check exists to prevent.
machine_holds 'simple-feed-reader-prod_mysql-data' ''
answer_is_no
! run_handler || fail 'declining the removal must stop the install'
assert_not_called 'volume rm'
assert_told 'Access denied'
assert_told '.env.prod of the previous'
assert_told 'docker volume rm simple-feed-reader-prod_mysql-data'

# --- 4. no terminal: stop, never guess ---------------------------------------
# A piped install (`curl ... | bash` without a tty) cannot be asked, and
# removing a database nobody confirmed is not an option.
machine_holds 'simple-feed-reader-prod_mysql-data' ''
no_terminal
! run_handler || fail 'without a terminal the install must stop'
assert_not_called 'volume rm'
assert_told 'no terminal'

# --- 5. every volume of the project is listed and removed --------------------
# Docker is asked by project label, not by name, so a volume added to the
# compose file later is covered without touching this code.
machine_holds 'simple-feed-reader-prod_mysql-data
simple-feed-reader-prod_php-var
simple-feed-reader-prod_jwt-keys' ''
answer_is_yes
run_handler || fail 'the install must continue'
grep -q 'volume rm simple-feed-reader-prod_mysql-data simple-feed-reader-prod_php-var simple-feed-reader-prod_jwt-keys' "${calls}" \
  || fail 'all three volumes should have been removed in one call'
grep -q 'label=com.docker.compose.project=simple-feed-reader-prod' "${calls}" \
  || fail 'the leftovers must be found by project label'

printf 'ok: handle_previous_prod_install\n'
