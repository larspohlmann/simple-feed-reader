#!/usr/bin/env bash
set -euo pipefail

# Golden-file test for changelog-insert-release.sh. The script's output is
# auto-committed to main by the release workflow, so its exact shape is a
# contract, not an implementation detail.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
script="${_dir}/../changelog-insert-release.sh"
fixtures="${_dir}/fixtures"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

# Case 1: a normal insert matches the golden file exactly.
work=$(mktemp)
trap 'rm -f "${work}"' EXIT
cp "${fixtures}/changelog-before.md" "${work}"
CHANGELOG_FILE="${work}" "${script}" v1.0.0 2026-01-02 < "${fixtures}/notes.md"
diff -u "${fixtures}/changelog-expected.md" "${work}" || fail 'output does not match the golden file'

# Case 2: inserting the same version twice is refused (idempotence guard).
if CHANGELOG_FILE="${work}" "${script}" v1.0.0 2026-01-02 < "${fixtures}/notes.md" 2>/dev/null; then
  fail 'a duplicate version insert should exit non-zero'
fi

# Case 4: a "### Highlights" block under Unreleased moves into the new version
# section, above the generated notes, and leaves Unreleased with no highlights.
hl=$(mktemp)
cp "${fixtures}/changelog-highlights-before.md" "${hl}"
CHANGELOG_FILE="${hl}" "${script}" v1.0.0 2026-01-02 < "${fixtures}/notes.md"
diff -u "${fixtures}/changelog-highlights-expected.md" "${hl}" \
  || { rm -f "${hl}"; fail 'highlights block was not promoted into the version section'; }
rm -f "${hl}"

# Case 3: a changelog with no Unreleased anchor is refused.
no_anchor=$(mktemp)
printf '# Changelog\n\nNothing here.\n' > "${no_anchor}"
if CHANGELOG_FILE="${no_anchor}" "${script}" v1.0.0 2026-01-02 < "${fixtures}/notes.md" 2>/dev/null; then
  rm -f "${no_anchor}"; fail 'a missing Unreleased anchor should exit non-zero'
fi
rm -f "${no_anchor}"

printf 'ok: changelog-insert-release.sh\n'
