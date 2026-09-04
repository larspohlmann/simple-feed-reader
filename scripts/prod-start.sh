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

usage() {
  cat <<'EOF'
Usage: prod-start.sh

Build and start the PRODUCTION stack (docker-compose.prod.yml): MySQL, the
production PHP image, and nginx serving the built app. Reads configuration from
.env.prod. Idempotent and safe to re-run: it rebuilds what changed, re-applies
migrations, and never deletes data. Re-running it is the update step after a new
release, and the way to switch to TLS after dropping certificates into
docker/certs-prod/.

Options:
  -h, --help              Show this help and exit.
EOF
}
handle_help_request "$@"

# Collect warnings for the closing block. A no-op when a caller (the installer,
# prod-configure.sh) already opened the collection -- the file is inherited.
notes_start

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
carry_over_renamed_env_vars
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

# Stamp the real release version into the images this build produces, so the
# sidebar and /api/version report it instead of the 'dev' placeholder (#500).
export_build_version_args

run_step 'Building and starting the production stack (the first build takes a few minutes)' \
  prod_compose up -d --build

# `up` only starts what is in the active profiles; it never stops the search
# engine's container when the operator has just declined it, so this runs on
# every start to keep the container in step with MEILISEARCH_URL -- see
# stop_disabled_search_engine_container in lib.sh.
stop_disabled_search_engine_container

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
prod_compose restart web >/dev/null

wait_for_php_ready

run_step 'Ensuring JWT signing keys exist' \
  prod_compose exec -T -u www-data php bin/console lexik:jwt:generate-keypair --skip-if-exists

run_step 'Applying database migrations' \
  prod_compose exec -T -u www-data php bin/console doctrine:migrations:migrate --no-interaction
# It may have started before the schema existed (first install).
prod_compose restart worker >/dev/null

if ! wait_for_health "$(prod_base_url)/api/health"; then
  warn 'The API did not report healthy in time. It may still be starting.'
  warn 'Check the logs with:  docker compose -p simple-feed-reader-prod logs -f php web worker'
fi

# A caller that still has work to do (the installer seeds the catalog and
# offers the mail check, prod-configure.sh asks its questions) sets
# SFR_DEFER_SUMMARY and prints the block itself, at the very end. Run on its
# own, this script is the end of the run and prints it here.
if [ -z "${SFR_DEFER_SUMMARY:-}" ]; then
  print_prod_summary
fi
