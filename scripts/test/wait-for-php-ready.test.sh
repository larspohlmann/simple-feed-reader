#!/usr/bin/env bash
set -euo pipefail

# Unit test for wait_for_php_ready (#842). A prod update must never leave newer
# code running against an un-migrated schema. The readiness probe used to `die`
# on timeout, which aborted prod-start.sh before the migration step -- so a
# slow-but-healthy container stranded the deploy. The probe must now WARN and
# RETURN a non-zero status, letting the caller continue to the migration (which
# fails loudly and correctly on its own if the container really is down).

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

notes_start

# --- ready flag present: reports ready and returns 0 --------------------------
prod_compose() { return 0; }               # `test -f var/.ready` succeeds
result=0
wait_for_php_ready >/dev/null 2>&1 || result=$?
[ "${result}" -eq 0 ] || fail "a ready container did not return 0: ${result}"

# --- timeout: warns and RETURNS non-zero, never exits the shell ---------------
# The zero-length timeout skips the poll loop and takes the deadline path at
# once. `reached_here` proves the function returned instead of calling exit.
prod_compose() { return 1; }               # `test -f var/.ready` always fails
: > "${SFR_NOTES_FILE}"
result=0
SFR_PHP_READY_TIMEOUT=0 wait_for_php_ready >/dev/null 2>&1 || result=$?
reached_here=1

[ "${reached_here}" -eq 1 ] || fail 'wait_for_php_ready exited the shell on timeout'
[ "${result}" -ne 0 ] || fail 'a timed-out probe returned 0 (should be non-zero)'
grep -q . "${SFR_NOTES_FILE}" || fail 'a timed-out probe recorded no warning note'

echo 'wait-for-php-ready: OK'
