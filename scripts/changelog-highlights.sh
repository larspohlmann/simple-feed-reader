#!/usr/bin/env bash
set -euo pipefail

# Print the "### Highlights" block from under "## [Unreleased]" in CHANGELOG.md.
#
#   scripts/changelog-highlights.sh > highlights.md
#
# The block runs from its heading up to the next heading of level 2 or 3, with
# trailing blank lines trimmed. A changelog with no such block prints nothing
# and exits 0.
#
# The release workflow leads the GitHub Release body with this output, so the
# Release page carries the same highlights that changelog-insert-release.sh
# promotes into the changelog's version section. The output shape is a tested
# contract (see scripts/test/changelog-highlights.test.sh).

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
changelog="${CHANGELOG_FILE:-${_dir}/../CHANGELOG.md}"

[ -f "${changelog}" ] || { printf 'error: no changelog at %s\n' "${changelog}" >&2; exit 1; }

awk '
  BEGIN { in_unreleased = 0; in_highlights = 0 }
  {
    if ($0 == "## [Unreleased]") { in_unreleased = 1; next }
    if (in_unreleased && in_highlights) {
      if ($0 ~ /^##+ /) { exit }
      print; next
    }
    if (in_unreleased) {
      if ($0 ~ /^### Highlights[ \t]*$/) { in_highlights = 1; print; next }
      if ($0 ~ /^## /) { exit }
    }
  }
' "${changelog}" | awk 'NF { last = NR } { line[NR] = $0 } END { for (i = 1; i <= last; i++) print line[i] }'
