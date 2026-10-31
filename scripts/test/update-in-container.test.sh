#!/usr/bin/env bash
set -euo pipefail

# Proves update.sh can run from a container on the production stack's network
# (#1948): prod_base_url names the compose service instead of localhost when
# SFR_PROD_INTERNAL_HOST is set, and SFR_SKIP_DEV_STACK keeps update.sh off
# the development stack.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

printf 'WEB_MODE=http\nWEB_HTTP_PORT=8080\n' > "${ENV_PROD_FILE}"
[ "$(SFR_PROD_INTERNAL_HOST=web prod_base_url)" = 'http://web' ] \
  || fail 'inside the network the health URL names the service on its container port'
[ "$(prod_base_url)" = 'http://localhost:8080' ] \
  || fail 'on the host the health URL keeps the published port'

printf 'WEB_MODE=tls\n' > "${ENV_PROD_FILE}"
[ "$(SFR_PROD_INTERNAL_HOST=web prod_base_url)" = 'https://web' ] \
  || fail 'in tls mode the internal URL is https'

grep -q 'SFR_SKIP_DEV_STACK' "${_dir}/../update.sh" \
  || fail 'update.sh lets an in-stack run skip the development stack'
printf 'ok\n'
