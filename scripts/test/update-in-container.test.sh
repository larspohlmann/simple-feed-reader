#!/usr/bin/env bash
set -euo pipefail

# Proves update.sh can run from a container on the production stack's network
# (#1948): with SFR_UPDATE_IN_PROD_STACK set, prod_base_url names the compose
# service instead of localhost, the development stack is left alone, and the
# run never warns about the stack's own ports.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

printf 'WEB_MODE=http\nWEB_HTTP_PORT=8080\n' > "${ENV_PROD_FILE}"
[ "$(SFR_UPDATE_IN_PROD_STACK=1 prod_base_url)" = 'http://web' ] \
  || fail 'inside the network the health URL names the service on its container port'
[ "$(prod_base_url)" = 'http://localhost:8080' ] \
  || fail 'on the host the health URL keeps the published port'

printf 'WEB_MODE=tls\n' > "${ENV_PROD_FILE}"
[ "$(SFR_UPDATE_IN_PROD_STACK=1 prod_base_url)" = 'https://web' ] \
  || fail 'in tls mode the internal URL is https'

compose() { [ "$*" = 'ps -aq php' ] && printf 'a1b2c3\n'; }
dev_stack_to_update || fail 'on the host a dev php container marks a dev stack to update'
if SFR_UPDATE_IN_PROD_STACK=1 dev_stack_to_update; then
  fail 'inside the run the development stack is never updated'
fi
compose() { :; }
if dev_stack_to_update; then
  fail 'without a dev php container there is no dev stack to update'
fi
checked_ports="${work}/checked-ports"
check_ports_free() { printf '%s\n' "$*" > "${checked_ports}"; return 1; }
printf 'WEB_HTTP_PORT=8080\nWEB_TLS_PORT=8443\n' > "${ENV_PROD_FILE}"
warnings=$(SFR_UPDATE_IN_PROD_STACK=1 warn_if_prod_ports_busy 2>&1)
[ ! -e "${checked_ports}" ] && [ -z "${warnings}" ] \
  || fail 'inside the run the busy ports of the running stack are never reported'
warnings=$(warn_if_prod_ports_busy 2>&1)
[ "$(cat "${checked_ports}")" = '8080 8443' ] || fail 'on the host the published ports are checked'
case "${warnings}" in
  *'docker will fail to publish them'*) ;;
  *) fail 'on the host busy ports are reported' ;;
esac
printf 'ok\n'
