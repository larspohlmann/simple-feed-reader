#!/usr/bin/env bash
# Reject a Playwright run that verified nothing.
#
#   scripts/assert-playwright-ran.sh playwright-report.json
#
# Every spec in frontend/e2e/ calls test.skip() when the seeded admin is
# unavailable, so a run whose fixture setup failed exits 0 with every spec
# skipped. Playwright is right to report that as success -- nothing FAILED --
# but for a scheduled rot check it is the worst outcome: green, unattended,
# and proving nothing. #93 rotted for weeks behind exactly that kind of quiet.
#
# There is no legitimate skip in the Playwright suite, so the threshold is
# zero rather than a ratio.
set -euo pipefail

report="${1:-}"
if [ -z "${report}" ]; then
  echo "usage: $0 <playwright-json-report>" >&2
  exit 2
fi

if [ ! -f "${report}" ]; then
  echo "ERROR: no Playwright JSON report at '${report}'." >&2
  echo "ERROR: Playwright did not get far enough to write one." >&2
  exit 1
fi

expected=$(jq -r '.stats.expected // 0' "${report}")
skipped=$(jq -r '.stats.skipped // 0' "${report}")

echo "==> Playwright: ${expected} passed, ${skipped} skipped."

status=0

if [ "${expected}" -eq 0 ]; then
  echo "ERROR: no Playwright spec passed. The suite proved nothing." >&2
  status=1
fi

if [ "${skipped}" -ne 0 ]; then
  echo "ERROR: ${skipped} spec(s) skipped. In this suite a skip means the" >&2
  echo "ERROR: fixture admin was missing, so those assertions never ran:" >&2
  jq -r '
    [ .. | objects | select(has("specs")) | .specs[]
      | select(any(.tests[]?; .status == "skipped"))
      | .title
    ] | unique | .[] | "ERROR:   - " + .
  ' "${report}" >&2
  status=1
fi

exit "${status}"
