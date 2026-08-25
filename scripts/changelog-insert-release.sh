#!/usr/bin/env bash
set -euo pipefail

# Insert a released version section into CHANGELOG.md.
#
#   scripts/changelog-insert-release.sh <version-tag> <iso-date> < notes.md
#
# The release notes body is read from stdin and placed under a new
#
#   ## [<tag>] - <date>
#
# heading, inserted immediately after the "## [Unreleased]" line. The release
# workflow runs this and commits CHANGELOG.md to main, so the output shape is a
# tested contract (see scripts/test/changelog-insert-release.test.sh).

usage() { printf 'usage: %s <version-tag> <iso-date> < notes\n' "$0" >&2; exit 2; }

version=${1:-}
date=${2:-}
if [ -z "${version}" ] || [ -z "${date}" ]; then
  usage
fi

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
changelog="${CHANGELOG_FILE:-${_dir}/../CHANGELOG.md}"

[ -f "${changelog}" ] || { printf 'error: no changelog at %s\n' "${changelog}" >&2; exit 1; }

anchor='## [Unreleased]'
grep -qF "${anchor}" "${changelog}" \
  || { printf "error: no '%s' line in %s\n" "${anchor}" "${changelog}" >&2; exit 1; }

# Idempotence: never insert the same version twice.
if grep -qF "## [${version}]" "${changelog}"; then
  printf 'error: %s already has a section for %s\n' "${changelog}" "${version}" >&2
  exit 1
fi

section=$(mktemp)
highlights=$(mktemp)
body=$(mktemp)
trap 'rm -f "${section}" "${highlights}" "${body}"' EXIT

# Lift a "### Highlights" block out of the Unreleased section into its own file,
# and write the changelog without it to ${body}. The block runs from its heading
# up to the next heading of level 2 or 3. A changelog with no such block leaves
# ${highlights} empty and ${body} byte-identical to the input.
awk -v anchor="${anchor}" -v highlights="${highlights}" '
  BEGIN { in_unreleased = 0; in_highlights = 0 }
  {
    if ($0 == anchor) { print; in_unreleased = 1; next }
    if (in_unreleased && in_highlights) {
      if ($0 ~ /^##+ /) { in_highlights = 0 }
      else { print > highlights; next }
    }
    if (in_unreleased && !in_highlights) {
      if ($0 ~ /^### Highlights[ \t]*$/) { in_highlights = 1; print > highlights; next }
      if ($0 ~ /^## /) { in_unreleased = 0 }
    }
    print
  }
' "${changelog}" > "${body}"

# The version section: the heading, then the promoted highlights (trailing blank
# lines trimmed, one blank line added back), then the generated notes on stdin.
{
  printf '\n## [%s] - %s\n\n' "${version}" "${date}"
  if [ -s "${highlights}" ]; then
    awk 'NF { last = NR } { line[NR] = $0 } END { for (i = 1; i <= last; i++) print line[i] }' "${highlights}"
    printf '\n'
  fi
  cat
} > "${section}"

# Print every line; right after the Unreleased anchor, splice in the section.
awk -v anchor="${anchor}" -v section="${section}" '
  { print }
  $0 == anchor && !spliced {
    while ((getline line < section) > 0) print line
    close(section)
    spliced = 1
  }
' "${body}" > "${changelog}.tmp"
mv "${changelog}.tmp" "${changelog}"
