#!/usr/bin/env bash
set -euo pipefail

# Golden-file test for changelog-highlights.sh. The release workflow leads the
# GitHub Release body with this script's output, so its exact shape is a
# contract, not an implementation detail.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
script="${_dir}/../changelog-highlights.sh"
fixtures="${_dir}/fixtures"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

# Case 1: a "### Highlights" block under Unreleased is printed, trailing blank
# lines trimmed, and nothing from the following version sections.
got=$(CHANGELOG_FILE="${fixtures}/changelog-highlights-before.md" "${script}")
want=$(cat "${fixtures}/highlights-only-expected.md")
[ "${got}" = "${want}" ] || fail 'highlights block does not match the golden file'

# Case 2: a changelog with no highlights under Unreleased prints nothing.
got=$(CHANGELOG_FILE="${fixtures}/changelog-before.md" "${script}")
[ -z "${got}" ] || fail 'a changelog with no highlights should print nothing'

# Case 3: a missing changelog file is refused.
if CHANGELOG_FILE="${_dir}/does-not-exist.md" "${script}" 2>/dev/null; then
  fail 'a missing changelog should exit non-zero'
fi

printf 'ok: changelog-highlights.sh\n'
