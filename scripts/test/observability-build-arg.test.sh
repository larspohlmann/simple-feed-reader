#!/usr/bin/env bash
set -euo pipefail

# Unit test for export_observability_build_arg in lib.sh (#1044). The prod
# image compiles the opentelemetry and excimer extensions only when the
# operator runs the observability stack, so prod-start.sh must export the
# WITH_OBSERVABILITY build arg from the same GRAFANA_LOKI_PUSH_URL signal
# prod_uses_grafana reads. A wrong mapping either bakes two unused extensions
# into every image or drops them from an install that wants tracing -- both
# invisible until a build, which is why the mapping is proven here.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

assert_arg() {
  local want=$1
  export_observability_build_arg
  [ "${SFR_WITH_OBSERVABILITY}" = "${want}" ] \
    || fail "SFR_WITH_OBSERVABILITY: want '${want}', got '${SFR_WITH_OBSERVABILITY}'"
}

# --- observability off: no push URL -> extensions skipped -------------------
printf 'GRAFANA_LOKI_PUSH_URL=\n' > "${ENV_PROD_FILE}"
assert_arg 0

# --- observability on: a push URL -> extensions compiled --------------------
printf 'GRAFANA_LOKI_PUSH_URL=http://loki:3100/loki/api/v1/push\n' > "${ENV_PROD_FILE}"
assert_arg 1

# --- whitespace-only push URL counts as off (trim_whitespace) ---------------
printf 'GRAFANA_LOKI_PUSH_URL=   \n' > "${ENV_PROD_FILE}"
assert_arg 0

# --- the key absent entirely counts as off ----------------------------------
printf 'DATABASE_URL=\n' > "${ENV_PROD_FILE}"
assert_arg 0

printf 'PASS: observability-build-arg\n'
