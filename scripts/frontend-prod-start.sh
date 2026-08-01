#!/usr/bin/env bash
set -euo pipefail

# Build and start the production preview of the frontend: the compiled bundle
# served same-origin behind nginx at https://localhost:8444. This is the
# 'frontend-prod' service, which lives behind the compose 'prod' profile.
# Compose starts the API it depends on automatically. Runs from any directory.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

say 'Building and starting the production preview (this can take a minute) ...'
compose --profile prod up -d --build frontend-prod

ok 'Production preview is running at https://localhost:8444'
say 'OAuth note: the sign-in round-trip returns to :4200 unless you override'
say 'APP_FRONTEND_URL on the php service. Password and API flows work as-is.'
