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
[ -n "${version}" ] && [ -n "${date}" ] || usage

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
trap 'rm -f "${section}"' EXIT
{
  printf '\n## [%s] - %s\n\n' "${version}" "${date}"
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
' "${changelog}" > "${changelog}.tmp"
mv "${changelog}.tmp" "${changelog}"
