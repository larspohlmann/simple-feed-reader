#!/bin/sh
# The single source of truth for how a build records its release version.
#
# Both the Docker production image builds (docker/web/Dockerfile and
# docker/php/Dockerfile, through the APP_VERSION/APP_COMMIT/APP_BUILT_AT build
# args) and the Strato release build (deploy/strato/build-release.sh) call it,
# so the sed that rewrites the SPA's version.ts placeholder and the shape of the
# backend's version.json live in ONE place instead of being copied per
# deployment path (issue #500).
#
# POSIX sh, no bashisms on purpose: `apply` runs inside the images too, and the
# php image is Alpine (busybox ash) while the web image is Debian (dash).
#
# Two modes:
#
#   stamp-version.sh derive [preferred-ref]
#       Print the checked-out tree's version, commit and build time as three
#       lines (in that order), for a caller to read into build args. A
#       tag-shaped preferred-ref (v1.2.3, what a tag push leaves in
#       GITHUB_REF_NAME) is taken verbatim; anything else -- a branch name, an
#       empty value -- falls through to `git describe`, which names the commit
#       honestly as N commits past the last tag. Host only: it reads git.
#
#   stamp-version.sh apply --version V --commit C --built-at T \
#                    [--version-ts PATH] [--version-json PATH]
#       Rewrite the SPA's committed version.ts placeholder and/or write the
#       backend's version.json. Each caller passes only the target it owns. An
#       EMPTY --version is a deliberate no-op: a local or manual image build
#       supplies none, and then both halves must keep reporting the development
#       build rather than a broken half-stamped one.
set -eu

die() { echo "stamp-version: $*" >&2; exit 1; }

derive() {
    preferred=${1:-}
    root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd -P)
    case "${preferred}" in
        v[0-9]*) version=${preferred} ;;
        *) version=$(git -C "${root}" describe --tags --always 2>/dev/null || echo dev) ;;
    esac
    commit=$(git -C "${root}" rev-parse --short HEAD 2>/dev/null || echo unknown)
    built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    printf '%s\n%s\n%s\n' "${version}" "${commit}" "${built_at}"
}

# Rewrite the three committed placeholder lines in place. version.ts is checked
# in with placeholders so a fresh clone builds with no generation step; here it
# gets the real values.
stamp_version_ts() {
    file=$1 version=$2 commit=$3 built_at=$4
    [ -f "${file}" ] || die "${file}: no such file to stamp"
    tmp="${file}.tmp"
    sed -e "s|^  version: '.*',$|  version: '${version}',|" \
        -e "s|^  commit: '.*',$|  commit: '${commit}',|" \
        -e "s|^  builtAt: '.*',$|  builtAt: '${built_at}',|" \
        "${file}" > "${tmp}"
    # A silent no-match would ship the 'dev' placeholder that looks like a
    # working build, so the substitution is verified rather than assumed.
    if ! grep -qF "version: '${version}'" "${tmp}"; then
        rm -f "${tmp}"
        die "could not write the version into ${file}: its shape changed"
    fi
    mv "${tmp}" "${file}"
}

# At the project root, never under public/, so the file itself is never
# web-served -- only /api/version exposes what it holds, and that route is
# authenticated. FileReleaseVersionReader rejects an empty field, so this is
# only ever reached with the real values derive() always produces.
write_version_json() {
    file=$1 version=$2 commit=$3 built_at=$4
    cat > "${file}" <<JSON
{
  "version": "${version}",
  "commit": "${commit}",
  "builtAt": "${built_at}"
}
JSON
}

apply() {
    version='' commit='' built_at='' version_ts='' version_json=''
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --version) version=$2; shift 2 ;;
            --commit) commit=$2; shift 2 ;;
            --built-at) built_at=$2; shift 2 ;;
            --version-ts) version_ts=$2; shift 2 ;;
            --version-json) version_json=$2; shift 2 ;;
            *) die "unknown apply option: $1" ;;
        esac
    done
    # No build-time version supplied: leave the committed placeholder and write
    # no version.json, so the checkout keeps reporting the development build.
    [ -n "${version}" ] || return 0
    [ -n "${commit}" ] || die 'apply needs --commit when --version is set'
    [ -n "${built_at}" ] || die 'apply needs --built-at when --version is set'
    [ -n "${version_ts}" ] || [ -n "${version_json}" ] \
        || die 'apply needs --version-ts and/or --version-json'
    [ -z "${version_ts}" ] || stamp_version_ts "${version_ts}" "${version}" "${commit}" "${built_at}"
    [ -z "${version_json}" ] || write_version_json "${version_json}" "${version}" "${commit}" "${built_at}"
}

mode=${1:-}
[ "$#" -eq 0 ] || shift
case "${mode}" in
    derive) derive "$@" ;;
    apply) apply "$@" ;;
    *) die 'usage: stamp-version.sh derive [preferred-ref] | apply --version V --commit C --built-at T [--version-ts PATH] [--version-json PATH]' ;;
esac
