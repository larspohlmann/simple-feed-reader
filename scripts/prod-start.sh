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
  die 'Fill them in (see the comments in .env.prod.example), then re-run.'
fi

if prod_certs_present; then
  say 'TLS mode: certificates found in docker/certs-prod/.'
else
  say 'HTTP mode: no certificates in docker/certs-prod/ -- serving plain HTTP.'
  say 'Either put a TLS reverse proxy in front, or add fullchain.pem and'
  say 'privkey.pem to docker/certs-prod/ and re-run this script.'
fi

say 'Building and starting the production stack (the first build takes a few minutes) ...'
prod_compose up -d --build

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
