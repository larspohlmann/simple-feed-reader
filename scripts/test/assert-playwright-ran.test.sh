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

# --- malformed JSON file (Playwright crashed mid-write) -----------------------
# If Playwright crashes during the test run (OOM, timeout, SIGKILL), the report
# file may exist but be truncated or invalid JSON. That is as bad as no file.
malformed="${work}/malformed.json"
echo 'not json at all' > "${malformed}"
if "${guard}" "${malformed}" > /dev/null 2>&1; then
  fail 'a malformed JSON report must be rejected'
fi

# Verify it exits with exactly 1, not some other jq error code.
exit_code=0
"${guard}" "${malformed}" > /dev/null 2>&1 || exit_code=$?
if [ "${exit_code}" -ne 1 ]; then
  fail "malformed JSON must exit 1, got ${exit_code}"
fi

# Capture first, then grep. The rejection must explain the malformed state.
rejection=$("${guard}" "${malformed}" 2>&1 || true)
if ! printf '%s\n' "${rejection}" | grep -q 'malformed'; then
  fail 'the rejection must explain the malformed JSON'
fi

# --- zero-byte report (Playwright crashed before writing anything) -----------
# A 0-byte file is one of the most plausible crash modes (OOM, timeout, signal).
# The guard must reject it, not report success.
zerobyte="${work}/zerobyte.json"
touch "${zerobyte}"
if "${guard}" "${zerobyte}" > /dev/null 2>&1; then
  fail 'a zero-byte report must be rejected'
fi

# Verify it exits with exactly 1, not some other code.
exit_code=0
"${guard}" "${zerobyte}" > /dev/null 2>&1 || exit_code=$?
if [ "${exit_code}" -ne 1 ]; then
  fail "zero-byte report must exit 1, got ${exit_code}"
fi

# --- valid JSON but no stats key (report structure is wrong) -------------------
# A report that parses but has no stats object is as bad as no report: there is
# no evidence the run proved anything. The guard must reject it, not default to
# 0 passed and 0 skipped.
nostats="${work}/nostats.json"
cat > "${nostats}" <<'JSON'
{
  "suites": [
    { "title": "reader-smoke.spec.ts", "specs": [] }
  ]
}
JSON

if "${guard}" "${nostats}" > /dev/null 2>&1; then
  fail 'a report with no stats must be rejected'
fi

# Verify it exits with exactly 1.
exit_code=0
"${guard}" "${nostats}" > /dev/null 2>&1 || exit_code=$?
if [ "${exit_code}" -ne 1 ]; then
  fail "report with no stats must exit 1, got ${exit_code}"
fi

# --- usage -------------------------------------------------------------------
if "${guard}" > /dev/null 2>&1; then
  fail 'no argument must be a usage error'
fi

printf 'PASS: assert-playwright-ran.sh\n'
