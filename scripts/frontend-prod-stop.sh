#!/usr/bin/env bash
set -euo pipefail

# Stop the production preview (frontend-prod). The rest of the stack and your
# data keep running. Runs from any directory.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

say 'Stopping the production preview ...'
compose --profile prod stop frontend-prod

ok 'Production preview stopped. The API and your data keep running.'
say 'Start it again with:  ./scripts/frontend-prod-start.sh'
