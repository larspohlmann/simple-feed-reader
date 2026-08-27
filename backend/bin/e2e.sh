#!/usr/bin/env bash
# One-command e2e run. Executed from the host; drives the running Docker stack.
#
#   composer e2e            # from backend/
#
# Steps: verify the stack is up, purge the throwaway accounts a previous run
# left behind, seed the fixtures admin and give it a subscription (so the reader
# shell renders instead of redirecting to onboarding — #222), reset the per-IP
# limiter and ALTCHA-replay pools (so repeated runs do not trip rate limits),
# pin the registration gates the account-lifecycle tests assert on (and put them
# back afterwards), then run the e2e testsuite from the host against the public
# TLS endpoint.
#
# The purge runs before the suite, not after: an interrupted or failed run then
# still gets cleaned up on the next one, and a failed run leaves its data in
# place for inspection.
set -euo pipefail

# Resolve repo root so docker compose finds compose.yml regardless of CWD.
BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$BACKEND_DIR/.." && pwd)"
BASE_URL="${E2E_BASE_URL:-https://localhost:8443}"

echo "==> Checking the stack is up ($BASE_URL) ..."
if ! curl -fsS -o /dev/null "$BASE_URL/api/health"; then
  echo "ERROR: $BASE_URL/api/health is not reachable." >&2
  echo "Start the stack first:  (cd '$REPO_ROOT' && docker compose up -d)" >&2
  exit 1
fi

# #615: the stack is up, but is it OUR stack? The project name is pinned, so a
# stack started from another checkout (a worktree) answers the same :8443 and
# the same `docker compose exec`. Fail fast rather than silently seed and test
# the other checkout's database.
# shellcheck source=e2e-preflight.sh
source "$BACKEND_DIR/bin/e2e-preflight.sh"
echo "==> Verifying this checkout owns the running stack ..."
assert_stack_owns_checkout "$REPO_ROOT"

echo "==> Purging leftover e2e fixture accounts ..."
docker compose -f "$REPO_ROOT/docker-compose.yml" exec -T php bin/console app:e2e:purge-users

echo "==> Seeding fixtures admin ..."
docker compose -f "$REPO_ROOT/docker-compose.yml" exec -T php bin/console app:e2e:seed-admin

echo "==> Ensuring the fixtures admin has a subscription ..."
docker compose -f "$REPO_ROOT/docker-compose.yml" exec -T php bin/console app:e2e:seed-admin-subscription

echo "==> Resetting rate-limiter and ALTCHA-replay pools ..."
docker compose -f "$REPO_ROOT/docker-compose.yml" exec -T php \
  bin/console cache:pool:clear cache.rate_limiter altcha.replay.cache

# Everything this run has to undo, in one function: bash keeps a SINGLE EXIT
# trap, so a second `trap … EXIT` further down would silently replace the first
# and leak whatever the first one was cleaning up.
CA_BUNDLE=""
GATES_TO_RESTORE=""
# shellcheck disable=SC2329  # reached through the EXIT trap below, never by name.
cleanup() {
  if [ -n "$CA_BUNDLE" ]; then
    rm -f "$CA_BUNDLE"
  fi
  if [ -n "$GATES_TO_RESTORE" ]; then
    echo "==> Restoring the instance registration gates ..."
    if ! put_registration_gates "$GATES_TO_RESTORE"; then
      echo "WARNING: could not restore the registration gates to $GATES_TO_RESTORE." >&2
      echo "WARNING: set them by hand under Settings → Admin → Registration." >&2
    fi
  fi
}
trap cleanup EXIT

# Read one field off a JSON body on stdin. php rather than jq: this script
# already depends on a php binary, and jq is not guaranteed on a developer's
# machine. Two readers, because a bare token and a JSON `true` need opposite
# quoting and one formatter would get one of them wrong.
json_string() {
  # shellcheck disable=SC2016  # single quotes are the point: this is PHP source, not shell.
  php -r '$b = json_decode(stream_get_contents(STDIN), true); echo is_array($b) && isset($b[$argv[1]]) && is_string($b[$argv[1]]) ? $b[$argv[1]] : "";' "$1"
}

json_bool() {
  # shellcheck disable=SC2016  # ditto: $b and $argv belong to PHP.
  php -r '$b = json_decode(stream_get_contents(STDIN), true); echo is_array($b) && !empty($b[$argv[1]]) ? "true" : "false";' "$1"
}

get_registration_gates() {
  local settings
  settings="$(curl -fsS "$BASE_URL/api/admin/settings" -H "Authorization: Bearer $ADMIN_TOKEN")"
  printf '{"requireEmailConfirmation":%s,"requireApproval":%s}' \
    "$(printf '%s' "$settings" | json_bool requireEmailConfirmation)" \
    "$(printf '%s' "$settings" | json_bool requireApproval)"
}

put_registration_gates() {
  curl -fsS -o /dev/null -X PUT "$BASE_URL/api/admin/settings" \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$1"
}

# Email confirmation and admin approval are RUNTIME instance settings, not
# deploy constants, and the account-lifecycle tests assert the gated journey:
# register → verify → approve → login. A developer who switched either gate off
# in the admin UI therefore failed three tests for a reason that has nothing to
# do with the code — the suite was reading state it did not own. It now pins
# both gates for the duration of the run and puts them back afterwards, in the
# trap above, so an interrupted run does not leave the instance rewired.
echo "==> Pinning the registration gates the suite asserts on ..."
ADMIN_TOKEN="$(curl -fsS -X POST "$BASE_URL/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"e2e-admin@example.com","password":"e2e-admin-password-123"}' \
  | json_string token)"
if [ -z "$ADMIN_TOKEN" ]; then
  echo "ERROR: could not sign in as the fixtures admin to read the instance settings." >&2
  exit 1
fi
GATES_TO_RESTORE="$(get_registration_gates)"
put_registration_gates '{"requireEmailConfirmation":true,"requireApproval":true}'

# Make PHP CLI trust the mkcert root even where it keeps its own CA bundle.
# `mkcert -install` trusts the root at the SYSTEM level (system curl verifies
# fine), but Homebrew PHP on macOS pins openssl.cafile to its own static bundle
# and never consults the keychain — so PHP's HttpClient cannot verify the local
# TLS cert. We concatenate (system CAs + mkcert root) into a temp bundle and
# point php's curl/openssl at it for THIS run only, via -d. This keeps FULL
# verification: verify_peer is never disabled, so a genuinely bad cert still
# fails the suite. On a host without mkcert, PHP_TLS_OPTS stays empty.
PHP_TLS_OPTS=()
CA_ROOT_DIR="$(mkcert -CAROOT 2>/dev/null || true)"
if [ -n "$CA_ROOT_DIR" ] && [ -f "$CA_ROOT_DIR/rootCA.pem" ]; then
  BASE_BUNDLE="$(php -r 'echo ini_get("openssl.cafile") ?: "";')"
  CA_BUNDLE="$(mktemp)"
  if [ -n "$BASE_BUNDLE" ] && [ -f "$BASE_BUNDLE" ]; then
    cat "$BASE_BUNDLE" "$CA_ROOT_DIR/rootCA.pem" > "$CA_BUNDLE"
  else
    cat "$CA_ROOT_DIR/rootCA.pem" > "$CA_BUNDLE"
  fi
  PHP_TLS_OPTS=(-d "curl.cainfo=$CA_BUNDLE" -d "openssl.cafile=$CA_BUNDLE")
fi

echo "==> Running e2e suite ..."
cd "$BACKEND_DIR"
# Run (not exec) so the EXIT trap above still fires and removes the temp CA
# bundle — `exec` would replace this shell and the trap would never run.
# ${PHP_TLS_OPTS[@]+"…"} guards the empty-array case under `set -u` on bash 3.2
# (the default /bin/bash on macOS), where a bare "${PHP_TLS_OPTS[@]}" would abort.
# `|| status=$?` keeps `set -e` from aborting before we can propagate phpunit's
# real exit code (the trap cleans up regardless of pass/fail).
status=0
php ${PHP_TLS_OPTS[@]+"${PHP_TLS_OPTS[@]}"} vendor/bin/phpunit -c phpunit-e2e.xml.dist "$@" || status=$?
exit "$status"
