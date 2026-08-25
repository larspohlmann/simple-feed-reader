#!/usr/bin/env bash
set -euo pipefail

# Unit test for what the installers put on the screen and for the options they
# accept (issue #430):
#
#   - the default port each topology offers, which is a contract: it becomes
#     the published container port and the port in every account-mail link;
#   - --ref parsing, on entry points that normally run through `curl | bash`,
#     where a silent argument break is invisible until an install goes wrong;
#   - --help, which every operator script prints and exits 0 for, before it
#     touches docker or the network;
#   - the warnings collected during a run and repeated in the closing block,
#     which is the whole reason the block is printed last.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

assert_equals() {
  local expected=$1 actual=$2 what=$3
  [ "${expected}" = "${actual}" ] || fail "${what}: expected '${expected}', got '${actual}'"
}

assert_contains() {
  case "$2" in
    *"$1"*) return 0 ;;
  esac
  fail "$3: '$1' is missing from: $2"
}

assert_missing() {
  case "$2" in
    *"$1"*) fail "$3: '$1' should not appear in: $2" ;;
  esac
}

# --- the default port each topology offers ----------------------------------
# 3333 is "FEED" on a phone keypad. It replaced 80 (plain) and 8080 (proxy):
# both are the ports a machine that already serves something has taken.
: > "${ENV_PROD_FILE}"
assert_equals 3333 "$(default_port_for http)" 'plain HTTP default'
assert_equals 3333 "$(default_port_for proxy)" 'reverse-proxy default'
# TLS keeps 443: there the answer IS the port users type.
assert_equals 443 "$(default_port_for tls)" 'TLS default'

# The case a live install caught and an empty file hides: the installer copies
# .env.prod.example and asks straight afterwards, so the SHIPPED file is the
# "stored" value the question reads back. While it said 80, the question
# offered 80 and 3333 never reached anybody.
cp "${_dir}/../../.env.prod.example" "${ENV_PROD_FILE}"
assert_equals 3333 "$(default_port_for http)" 'a fresh .env.prod offers 3333'
assert_equals 3333 "$(default_port_for proxy)" 'a fresh .env.prod offers 3333 behind a proxy'
assert_equals 443 "$(default_port_for tls)" 'a fresh .env.prod offers 443 for TLS'

# Choosing TLS moves the plain port back to 80: there it is the redirect
# listener, and a redirect on 3333 catches nobody typing http://host.
apply_direct_origin https reader.example.org 443 >/dev/null
assert_equals 80 "$(env_prod_get WEB_HTTP_PORT)" 'TLS keeps the redirect listener on 80'

# An install that already answered keeps its own port, so re-running
# prod-configure.sh and pressing return changes nothing.
printf 'PUBLIC_URL=http://reader.example.org:8080\nWEB_HTTP_PORT=8080\nWEB_TLS_PORT=8443\nWEB_BIND_ADDRESS=0.0.0.0\n' > "${ENV_PROD_FILE}"
assert_equals 8080 "$(default_port_for http)" 'stored port wins for the current topology'
# ... but not for a topology it was never chosen for.
assert_equals 3333 "$(default_port_for proxy)" 'stored port is not offered for another topology'

# --- parse_ref_args ---------------------------------------------------------
run_parse() {
  REF='' TARGET_DIR=''
  parse_ref_args "$@"
}

run_parse
assert_equals '' "${REF}" 'no arguments leaves the ref empty'
assert_equals '' "${TARGET_DIR}" 'no arguments leaves the directory empty'

run_parse --ref feature/430-installer-output
assert_equals 'feature/430-installer-output' "${REF}" '--ref <value>'

run_parse --ref=v1.2.3
assert_equals 'v1.2.3' "${REF}" '--ref=<value>'

run_parse --ref develop my-folder
assert_equals 'develop' "${REF}" '--ref beside a directory'
assert_equals 'my-folder' "${TARGET_DIR}" 'directory beside --ref'

run_parse my-folder
assert_equals 'my-folder' "${TARGET_DIR}" 'bare directory argument'

# The environment is the fallback, and an explicit flag beats it.
SFR_REF=from-env run_parse
assert_equals 'from-env' "${REF}" 'SFR_REF is the fallback'
SFR_REF=from-env run_parse --ref from-flag
assert_equals 'from-flag' "${REF}" '--ref beats SFR_REF'

# die() exits, so the failing cases run in a subshell.
( run_parse --ref ) >/dev/null 2>&1 && fail '--ref without a value must not be accepted'
( run_parse --nonsense ) >/dev/null 2>&1 && fail 'an unknown option must not be accepted'

# --- the installers parse the same way --------------------------------------
# Each installer repeats the loop, because lib.sh only exists after the clone
# these arguments decide. A copy that drifts is the failure this catches: both
# reject a bad option BEFORE cloning anything, so the check needs no network,
# no docker and no target directory.
for installer in install.sh install-dev.sh; do
  output=$(bash "${_dir}/../${installer}" --nonsense 2>&1) && fail "${installer} accepted an unknown option"
  assert_contains 'Unknown option' "${output}" "${installer} rejects an unknown option"
  output=$(bash "${_dir}/../${installer}" --ref 2>&1) && fail "${installer} accepted --ref without a value"
  assert_contains 'needs a branch or a tag name' "${output}" "${installer} rejects a valueless --ref"
  # --help exits 0 and prints the usage, before any prerequisite check or clone,
  # so it needs neither docker nor a network. Its own copy of the help flag,
  # because lib.sh's handle_help_request is not on disk until the clone.
  output=$(bash "${_dir}/../${installer}" --help 2>&1) || fail "${installer} --help must exit 0"
  assert_contains 'Usage:' "${output}" "${installer} --help prints the usage"
done

# --- --help on the lib.sh scripts -------------------------------------------
# The scripts that source lib.sh answer --help through handle_help_request,
# before ensure_docker, so it works with no daemon running. update.sh is the
# representative: it also runs through the shared parser, and --help must win
# over it (parse_ref_args would otherwise reject the flag as unknown).
output=$(bash "${_dir}/../update.sh" --help 2>&1) || fail 'update.sh --help must exit 0'
assert_contains 'Usage: update.sh' "${output}" 'update.sh --help prints the usage'
assert_contains '--ref' "${output}" 'update.sh --help lists its option'

# --- warnings are collected for the closing block ---------------------------
notes_start
[ -n "${SFR_NOTES_FILE}" ] || fail 'notes_start did not open a collection'

warn 'The bundled catalog was not imported.' 2>/dev/null
warn 'Some catalog favicons are missing.' 2>/dev/null

notes=$(print_notes)
assert_contains 'Things to check' "${notes}" 'the notes heading'
assert_contains 'The bundled catalog was not imported.' "${notes}" 'the first warning'
assert_contains 'Some catalog favicons are missing.' "${notes}" 'the second warning'

# Printing empties the collection: update.sh prints one block per updated
# stack, and the warnings belong under the first one only.
assert_equals '' "$(print_notes)" 'notes are printed once'

# --- the ref row the closing block carries ----------------------------------
record_installed_release v9.9.9
assert_contains 'v9.9.9' "$(print_installed_ref_row)" 'a release is named'
assert_missing 'not a release' "$(print_installed_ref_row)" 'a release is not flagged'

# Not $( ): the function records what it announces, and a subshell would keep
# that recording to itself -- exactly the trap print_installed_ref_row exists
# to survive.
note_unreleased_ref 'feature/430-installer-output' > "${work}/announcement"
announcement=$(cat "${work}/announcement")
assert_contains 'feature/430-installer-output' "${announcement}" 'the unreleased ref is announced'
assert_contains 'not a release' "${announcement}" 'the announcement says it is not a release'

unreleased_row=$(print_installed_ref_row)
assert_contains 'feature/430-installer-output' "${unreleased_row}" 'an unreleased ref is named'
assert_contains 'not a release' "${unreleased_row}" 'an unreleased ref is flagged in the block'

# The announcement is not a warning: it belongs in the ref row, not under
# "Things to check", which is for what went wrong.
assert_equals '' "$(print_notes)" 'an unreleased ref is not a note'

# --- run_step ---------------------------------------------------------------
# The output of a long phase is indented under its header, and the phase's own
# exit status survives the pipe it is printed through.
output=$(run_step 'Doing the thing' printf 'first\nsecond\n')
assert_contains 'Doing the thing' "${output}" 'the phase header'
assert_contains '    first' "${output}" 'the first output line is indented'
assert_contains '    second' "${output}" 'the second output line is indented'

run_step 'Failing on purpose' false >/dev/null 2>&1 \
  && fail 'run_step must report the failure of the command it runs'

status=0
run_step 'Reporting an exact code' bash -c 'exit 42' >/dev/null 2>&1 || status=$?
assert_equals 42 "${status}" 'run_step passes the exit code through'

# A phase that writes to stderr is captured too -- a failure explains itself
# inside the block it belongs to, not somewhere above it.
output=$(run_step 'Complaining' bash -c 'echo trouble >&2' 2>&1)
assert_contains '    trouble' "${output}" 'stderr is indented as well'

printf 'installer-output: all assertions passed\n'
