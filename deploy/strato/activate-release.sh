#!/usr/bin/env bash
# Activate an uploaded release. Runs ON the Strato host.
#
# The flip is the last thing that happens: migrations run against the new code
# while `current` still points at the old release, so a failure leaves the live
# site serving the previous version.
#
# Usage: activate-release.sh <deploy-root> <release-name>
set -euo pipefail

ROOT="${1:?usage: activate-release.sh <deploy-root> <release-name>}"
NAME="${2:?usage: activate-release.sh <deploy-root> <release-name>}"

# `current` is dereferenced from a symlink in a different directory (the
# portfolio docroot), so it has to point at an absolute path -- which means ROOT
# must be absolute before it is ever used to build one.
ROOT="$(cd "${ROOT}" && pwd)"

# A release name is one path segment. Anything else would let `..` walk the
# `rm -rf` below out of the release directory and aim the flip at a parent.
case "${NAME}" in
    */*|.|..) echo "release name must be a single path segment: ${NAME}"; exit 1 ;;
esac

RELEASE="${ROOT}/releases/${NAME}"
SHARED="${ROOT}/shared"

# The host's PHP binary is `php84` and its SAPI is cgi-fcgi, not cli. That
# changes how a command line has to be spelled, in three ways that are each
# silent or fatal if missed:
#
#   -q                      suppresses the HTTP headers the SAPI would
#                           otherwise print ahead of the command's output.
#   register_argc_argv      is what the php.ini PHP ships for production turns
#                           off, and it is the host's ini, not ours. Symfony's
#                           ArgvInput reads $_SERVER['argv'], so left off every
#                           command below degrades into `bin/console list` and
#                           exits 0 -- a deploy that reports success while
#                           having migrated nothing. Forced on rather than
#                           trusted, because the failure is silent.
#   --                      is mandatory. The SAPI keeps parsing options after
#                           the script name, so `-f bin/console cache:clear
#                           --no-interaction` aborts with "no argument for
#                           option -" before PHP ever starts.
#
# The path is absolute because this SAPI chdir()s to the script's own directory,
# so the shell's working directory says nothing about what `bin/console`
# resolves to.
console() {
    php84 -d register_argc_argv=1 -q -f "${RELEASE}/bin/console" -- "$@"
}

test -d "${RELEASE}" || { echo "no such release: ${RELEASE}"; exit 1; }
test -f "${SHARED}/.env.local" || { echo "missing ${SHARED}/.env.local"; exit 1; }
test -f "${SHARED}/config/jwt/private.pem" || { echo "missing shared JWT keys"; exit 1; }

echo "==> Linking shared state"
# Secrets. Never shipped in the release.
ln -sfn "${SHARED}/.env.local" "${RELEASE}/.env.local"

# JWT keys. Shared because regenerating them per release would invalidate
# every issued token and silently log everyone out on each deploy.
rm -rf "${RELEASE}/config/jwt"
ln -sfn "${SHARED}/config/jwt" "${RELEASE}/config/jwt"

# Logs outlive a release; the cache directory does not.
#
# cache-pools is the counterpart: the filesystem pools (rate limiter, ALTCHA
# replay, OAuth state and login codes) are pointed here by CACHE_DIRECTORY in
# shared/.env.local, which is what keeps `cache:clear` below -- it only wipes
# the release's own var/cache -- from resetting them on every deploy.
mkdir -p "${SHARED}/var/log" "${SHARED}/var/cache-pools"
mkdir -p "${RELEASE}/var"
ln -sfn "${SHARED}/var/log" "${RELEASE}/var/log"

echo "==> Warming the cache"
# Clear first so a re-run against a release whose previous activation died
# mid-warmup cannot build on top of a half-written cache.
console cache:clear --no-interaction
console cache:warmup --no-interaction

echo "==> Running migrations"
# Before the flip on purpose: if this fails, the old release is still live.
console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "==> Flipping current"
ln -sfn "${RELEASE}" "${ROOT}/current"

echo "==> Active release is now ${NAME}"
