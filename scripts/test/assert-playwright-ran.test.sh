#!/usr/bin/env bash
set -euo pipefail

# Unit test for the all-skip guard. Issue #96 records the failure mode it
# exists to prevent: every Playwright spec calls test.skip() when the seeded
# admin is missing, so a run whose fixture setup silently failed reports
# success having verified nothing. A green suite that proves nothing is worse
# than a red one, because nobody looks at it.
#
# The guard reads Playwright's JSON reporter output, so the fixtures here are
# trimmed copies of that shape: only the fields the guard reads.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
guard="${_dir}/../assert-playwright-ran.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

write_report() {
  local path=$1 expected=$2 skipped=$3 title=$4 status=$5
  cat > "${path}" <<JSON
{
  "stats": { "expected": ${expected}, "skipped": ${skipped}, "unexpected": 0, "flaky": 0 },
  "suites": [
    {
      "title": "reader-smoke.spec.ts",
      "specs": [
        { "title": "${title}", "tests": [ { "status": "${status}" } ] }
      ]
    }
  ]
}
JSON
}

# --- a healthy run passes ----------------------------------------------------
healthy="${work}/healthy.json"
write_report "${healthy}" 36 0 'the reader shell renders' expected
if ! "${guard}" "${healthy}" > /dev/null; then
  fail 'a run with 36 passed and 0 skipped must be accepted'
fi

# --- the trap itself: everything skipped, nothing verified -------------------
all_skipped="${work}/all-skipped.json"
write_report "${all_skipped}" 0 36 'the reader shell renders' skipped
if "${guard}" "${all_skipped}" > /dev/null 2>&1; then
  fail 'a run where every spec skipped must be rejected'
fi

# The operator has to know WHICH specs skipped, or the red run is a dead end.
#
# Capture first, then grep. Piping the guard straight into grep would report
# the GUARD's exit status under `set -o pipefail`, so the assertion would
# invert: a correct rejection would read as a test failure.
rejection=$("${guard}" "${all_skipped}" 2>&1 || true)
if ! printf '%s\n' "${rejection}" | grep -q 'the reader shell renders'; then
  fail 'the rejection must name the skipped specs'
fi

# --- one skip among many passes is still a rejection -------------------------
# Partial skipping means part of the suite verified nothing, which is the same
# defect in a smaller dose. There is no legitimate skip in frontend/e2e/.
partial="${work}/partial.json"
write_report "${partial}" 35 1 'the tag dialog opens' skipped
if "${guard}" "${partial}" > /dev/null 2>&1; then
  fail 'a single skipped spec must be rejected'
fi

# --- no report at all --------------------------------------------------------
# Playwright crashing before it writes the report must not read as success.
if "${guard}" "${work}/absent.json" > /dev/null 2>&1; then
  fail 'a missing report must be rejected'
fi

# --- usage -------------------------------------------------------------------
if "${guard}" > /dev/null 2>&1; then
  fail 'no argument must be a usage error'
fi

printf 'PASS: assert-playwright-ran.sh\n'
