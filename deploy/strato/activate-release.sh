#!/usr/bin/env bash
# Activate an uploaded release. Runs ON the Strato host.
#
# The flip is the last thing that happens: migrations run against the new code
# while `current` still points at the old release, so a failure leaves the live
# site serving the previous version.
#
# Usage: activate-release.sh <deploy-root> <release-name>
set -euo pipefail

die() { echo "$*" >&2; exit 1; }

ROOT="${1:?usage: activate-release.sh <deploy-root> <release-name>}"
NAME="${2:?usage: activate-release.sh <deploy-root> <release-name>}"

# `ln` writes ${RELEASE} into `current` verbatim, and that string is resolved
# later, relative to the directory the link lives in -- the deploy root. A
# relative ROOT would therefore produce a dangling `current` and an instantly
# dead site, so normalize it before anything is built out of it.
ROOT="$(cd "${ROOT}" 2>/dev/null && pwd -P)" || die "no such deploy root: ${1}"

# A release name is one path segment. Anything else would let `..` walk the
# `rm -rf` below out of the release directory and aim the flip at a parent.
case "${NAME}" in
    */*|.|..) die "release name must be a single path segment: ${NAME}" ;;
esac

RELEASE="${ROOT}/releases/${NAME}"
SHARED="${ROOT}/shared"

# An absolute vendor path, not the `php84` on PATH, because the two are not the
# same SAPI. `php84` is a symlink to the cgi-fcgi binary: it has to be spelled
# `-q -f <script> -- <args>` (the -q suppresses HTTP headers, the -- stops the
# SAPI swallowing the first dash-prefixed argument), it chdir()s into the
# script's directory, and its ini caps max_execution_time at 240 seconds. None
# of that is wanted here -- least of all the ceiling, which is enough to kill a
# migration on a table with real data.
#
# /opt/RZphp84/bin/php-cli is the same PHP 8.4.22 build, differing only in SAPI.
# Measured on the host 2026-07-25: max_execution_time 0 (no ceiling), memory
# limit 512M as with cgi-fcgi, every extension this app requires present, empty
# disable_functions, date.timezone UTC, ordinary argument passing, and exit
# codes propagate. The path shape is a Strato convention rather than a one-off:
# php-cli exists identically under /opt/RZphp82, RZphp83, RZphp84 and RZphp85.
# It is simply not on PATH, which is the only reason it looks odd here.
#
# ${RELEASE}/bin/console stays absolute, for a different reason than before: the
# CLI does *not* chdir, it stays in the directory it was invoked from, and an
# SSH command starts in $HOME. ${RELEASE} is absolute anyway, by the
# normalization above.
PHP=/opt/RZphp84/bin/php-cli

console() { "${PHP}" "${RELEASE}/bin/console" "$@"; }

test -x "${PHP}" || die "no PHP CLI at ${PHP} -- if Strato has reorganised /opt, look for the new path with 'ls -d /opt/RZphp*/bin/php-cli' and update this script. The cgi-fcgi binary on PATH still works as a fallback, spelled: php84 -q -f ${RELEASE}/bin/console -- <command>"
test -d "${RELEASE}" || die "no such release: ${RELEASE}"
test -f "${SHARED}/.env.local" || die "missing ${SHARED}/.env.local"
test -f "${SHARED}/config/jwt/private.pem" || die "missing shared JWT key: ${SHARED}/config/jwt/private.pem"
test -f "${SHARED}/config/jwt/public.pem" || die "missing shared JWT key: ${SHARED}/config/jwt/public.pem"

# shared/.env.local is hand-written on the server, so its *contents* are as
# unverified as its existence. Two omissions there are silent and expensive,
# because backend/.env ships a working default for each and the deploy then
# succeeds with it.
#
# APP_ENV: the committed default is `dev`. Missing here, the cache is warmed for
# the dev environment and the site goes live in debug mode, serving stack traces
# -- with the database credentials in them -- to the public internet.
grep -Eq "^APP_ENV=['\"]?prod['\"]?[[:space:]]*$" "${SHARED}/.env.local" \
    || die "${SHARED}/.env.local does not set APP_ENV=prod -- the deploy would warm a dev cache and put the site live in debug mode, serving stack traces to the public"
# CACHE_DIRECTORY: the committed default is kernel-relative, so it resolves
# inside the release's own var/cache -- which `cache:clear` below wipes. Missing
# (or left kernel-relative) here, every deploy silently resets the filesystem
# pools: rate-limit counters, spent ALTCHA solutions, pending OAuth states and
# login codes. Requiring an absolute path is what rules out the kernel-relative
# default; that it points into shared/ is the operator's job.
grep -Eq "^CACHE_DIRECTORY=['\"]?/" "${SHARED}/.env.local" \
    || die "${SHARED}/.env.local does not set CACHE_DIRECTORY to an absolute path -- the filesystem cache pools would live inside the release, so every deploy would reset the rate-limit counters and the record of spent ALTCHA solutions (point it at ${SHARED}/var/cache-pools)"

echo "==> Linking shared state"
# Secrets. Never shipped in the release. Unguarded on purpose, unlike the two
# links below: `ln -sfn` over an existing *regular file* does the right thing,
# because -f unlinks it before creating the link. The trap is specific to the
# target already being a real directory.
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
# `rm -rf` first for the same reason as config/jwt above. If ${RELEASE}/var/log
# already exists as a real directory, `ln -sfn` puts the link *inside* it --
# var/log/log -> shared/var/log -- and exits 0, and the release then writes its
# logs to a directory that vanishes with it. Nothing about that is visible in
# the deploy output. That the release currently arrives without var/ at all is
# a property of build-release.sh's copy list, not something this script may
# assume. Re-running is safe: `rm -rf` on a symlink removes the link, not the
# shared directory it points at.
rm -rf "${RELEASE}/var/log"
ln -sfn "${SHARED}/var/log" "${RELEASE}/var/log"

echo "==> Warming the cache"
# Clear first so a re-run against a release whose previous activation died
# mid-warmup cannot build on top of a half-written cache. One command, not two:
# cache:clear warms the cache itself unless --no-warmup is passed (Symfony 7.4,
# CacheClearCommand), so a following cache:warmup would buy a second container
# compile and nothing else.
console cache:clear --no-interaction

echo "==> Running migrations"
# Before the flip on purpose: if this fails, the old release is still live.
#
# It can fail halfway, and that is the expensive case. MySQL 8 commits
# implicitly on DDL, so Doctrine's per-migration transaction does not protect a
# migration that dies partway through: a statement the server rejects, a
# connection lost to the shared MySQL host, or the job being killed leaves a
# half-changed schema with no row in doctrine_migration_versions, and a blind
# retry then fails on something like "Duplicate column name".
#
# What is *no longer* one of those causes is a timeout. Under the CLI above
# max_execution_time is 0; the 240s ceiling belongs to the cgi-fcgi SAPI this
# script deliberately does not use. The implicit-commit hazard is unchanged --
# this script cannot make DDL transactional; all it can do is refuse to fail
# mutely.
migrate_status=0
console doctrine:migrations:migrate --no-interaction --allow-no-migration || migrate_status=$?
if [ "${migrate_status}" -ne 0 ]; then
    {
        echo "!!! Migrations failed (exit ${migrate_status})."
        echo "!!! current was NOT flipped: ${ROOT}/current still points at the previous"
        echo "!!! release, and that release is still serving the site."
        echo "!!! The schema may be partially migrated -- MySQL commits DDL implicitly, so"
        echo "!!! statements from a migration that died partway through can be applied"
        echo "!!! without the migration being recorded as executed."
        echo "!!! Check what actually ran before retrying the deploy:"
        echo "!!!   ${PHP} ${RELEASE}/bin/console doctrine:migrations:status"
    } >&2
    exit "${migrate_status}"
fi

echo "==> Flipping current"
# Two steps, because `ln -sfn` over an existing link is unlink() then symlink():
# for the instant in between, `current` does not exist and in-flight requests
# resolve a dangling path. Creating the link beside it and renaming it over the
# old one is a single rename(2), which is atomic -- there is no moment at which
# `current` is missing. -T also makes this fail loudly rather than nest a link
# inside it, should `current` ever be a real directory.
ln -sfn "${RELEASE}" "${ROOT}/current.tmp"
mv -Tf "${ROOT}/current.tmp" "${ROOT}/current"

echo "==> Backfilling missing user preferences"
# Deliberately AFTER the flip, for the same reason as the favicon warm below:
# migrations run against the new schema while `current` still served the OLD
# code (see the migrations comment above), and the old User::__construct never
# created a Preferences row. An account created in that window is otherwise
# broken forever -- the migration's own backfill already ran and will not run
# again, so this is its only remaining chance to heal. Non-fatal: a healing
# step must never take a live, already-flipped site down.
if ! console app:preferences:backfill; then
    echo "!!! Preferences backfill failed; the release is live and serving." >&2
    echo "!!! Any account created during the migration/flip window may still" >&2
    echo "!!! be missing its preferences row until this is re-run by hand." >&2
fi

echo "==> Warming catalog favicons"
# A convenience for this server, not a requirement of the app: the admin UI warms
# icons after an import on any deployment, and a cold cache renders monograms,
# which is a working picker. Forks deploying some other way lose nothing.
#
# Deliberately AFTER the flip and deliberately non-fatal. The release is live at
# this point, and a publisher's icon host being down must not turn a good deploy
# red.
#
# Self-limiting: minutes on the first deploy against an empty cache, a no-op on
# every deploy after, because cached rows are neither missing nor stale.
if ! console app:catalog:warm-favicons; then
    echo "!!! Favicon warming failed; the release is live and serving." >&2
    echo "!!! Icons fall back to monograms until the next successful run." >&2
fi

echo "==> Active release is now ${NAME}"
