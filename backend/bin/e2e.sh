#!/usr/bin/env bash
# One-command e2e run. Executed from the host; drives the running Docker stack.
#
#   composer e2e            # from backend/
#
# Steps: verify the stack is up, seed the fixtures admin, reset the per-IP
# limiter and ALTCHA-replay pools (so repeated runs do not trip rate limits),
# then run the e2e testsuite from the host against the public TLS endpoint.
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

echo "==> Seeding fixtures admin ..."
docker compose -f "$REPO_ROOT/docker-compose.yml" exec -T php bin/console app:e2e:seed-admin

echo "==> Resetting rate-limiter and ALTCHA-replay pools ..."
docker compose -f "$REPO_ROOT/docker-compose.yml" exec -T php \
  bin/console cache:pool:clear cache.rate_limiter altcha.replay.cache

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
  trap 'rm -f "$CA_BUNDLE"' EXIT
  if [ -n "$BASE_BUNDLE" ] && [ -f "$BASE_BUNDLE" ]; then
    cat "$BASE_BUNDLE" "$CA_ROOT_DIR/rootCA.pem" > "$CA_BUNDLE"
  else
    cat "$CA_ROOT_DIR/rootCA.pem" > "$CA_BUNDLE"
  fi
  PHP_TLS_OPTS=(-d "curl.cainfo=$CA_BUNDLE" -d "openssl.cafile=$CA_BUNDLE")
fi

echo "==> Running e2e suite ..."
cd "$BACKEND_DIR"
# ${PHP_TLS_OPTS[@]+"…"} guards the empty-array case under `set -u` on bash 3.2
# (the default /bin/bash on macOS), where a bare "${PHP_TLS_OPTS[@]}" would abort.
exec php ${PHP_TLS_OPTS[@]+"${PHP_TLS_OPTS[@]}"} vendor/bin/phpunit -c phpunit-e2e.xml.dist "$@"
