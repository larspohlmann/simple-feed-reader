#!/usr/bin/env bash
set -euo pipefail

# Stop the production stack. Containers are removed; the data volumes (MySQL,
# logs and cache pools, JWT keys) are kept. Start again with prod-start.sh.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

if [ ! -f "${ENV_PROD_FILE}" ]; then
  die 'No .env.prod found -- there is no production stack to stop here.'
fi

say 'Stopping the production stack ...'
prod_compose down

ok 'Production stack stopped. Your data is kept.'
say 'Start it again with:  ./scripts/prod-start.sh'
