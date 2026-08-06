#!/usr/bin/env bash
set -euo pipefail

# Build and start the PRODUCTION stack (docker-compose.prod.yml): MySQL, the
# prod PHP image, and nginx serving the built SPA with /api same-origin.
# Configuration comes from .env.prod -- see .env.prod.example and
# docs/docker-production.md.
#
# Idempotent and safe to re-run: it rebuilds what changed, re-applies
# migrations, and never deletes data. Re-running it is also the update step
# after checking out a newer release, and the way to switch to TLS after
# dropping certificates into docker/certs-prod/.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

if [ ! -f "${ENV_PROD_FILE}" ]; then
  die "No .env.prod found. Copy .env.prod.example to .env.prod and fill it in -- see docs/docker-production.md. (scripts/install.sh does this for you.)"
fi

missing=$(env_prod_missing)
if [ -n "${missing}" ]; then
  warn 'These required values in .env.prod are still empty:'
  while IFS= read -r name; do
    printf '    %s\n' "${name}" >&2
  done <<< "${missing}"
  die 'Fill them in: run ./scripts/prod-configure.sh, or edit .env.prod (see .env.prod.example), then re-run.'
fi

# The browser-based first-admin setup needs a secret only the operator holds;
# generate one when it is still empty so a fresh install can be finished
# without a shell round-trip. The summary prints it while no admin exists.
ensure_admin_setup_secret
ensure_ai_key_secret

mode=$(prod_web_mode)
if [ "${mode}" = tls ]; then
  if prod_certs_present; then
    say 'TLS mode: certificates found in docker/certs-prod/.'
  else
    warn 'WEB_MODE=tls but no certificates in docker/certs-prod/ -- nginx will refuse to start.'
    warn 'Add fullchain.pem and privkey.pem to docker/certs-prod/, or set WEB_MODE=auto/http.'
  fi
else
  say 'HTTP mode: serving plain HTTP.'
  if ! prod_certs_present; then
    say 'Either put a TLS reverse proxy in front, or add fullchain.pem and'
    say 'privkey.pem to docker/certs-prod/ and re-run this script.'
  fi
fi

http_port=$(env_prod_get WEB_HTTP_PORT); http_port=${http_port:-80}
tls_port=$(env_prod_get WEB_TLS_PORT); tls_port=${tls_port:-443}
if ! check_ports_free "${http_port}" "${tls_port}"; then
  warn 'Both ports are published regardless of mode, so docker will fail to publish them while busy.'
fi

say 'Building and starting the production stack (the first build takes a few minutes) ...'
prod_compose up -d --build

# The web entrypoint picks HTTP or TLS mode by checking docker/certs-prod/
# once, at container start. That directory is a bind mount, so dropping
# certificates in (or removing them) changes the host files but not the
# compose service definition -- `up -d --build` above sees nothing to
# recreate and leaves the container serving whatever mode it already picked.
# A restart re-runs /docker-entrypoint.d/ (including the mode-selection
# script) exactly like a recreate would, without destroying and rebuilding
# the container object on every routine run -- the lighter primitive so this
# script is really "the way to switch to TLS" the comment at the top of this
# file promises.
prod_compose restart web

wait_for_php_ready

say 'Ensuring JWT signing keys exist ...'
prod_compose exec -T -u www-data php bin/console lexik:jwt:generate-keypair --skip-if-exists

say 'Applying database migrations ...'
prod_compose exec -T -u www-data php bin/console doctrine:migrations:migrate --no-interaction

if wait_for_health "$(prod_base_url)/api/health"; then
  print_prod_summary
else
  warn 'The API did not report healthy in time. It may still be starting.'
  warn 'Check the logs with:  docker compose -p simple-feed-reader-prod logs -f php web'
fi
