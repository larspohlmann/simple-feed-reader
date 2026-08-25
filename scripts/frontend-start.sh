#!/usr/bin/env bash
set -euo pipefail

# Start the development frontend (Angular dev server with live reload) at
# http://localhost:4200. Runs from any directory.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

usage() {
  cat <<'EOF'
Usage: frontend-start.sh

Start the development frontend (Angular dev server with live reload) at
http://localhost:4200. Runs from any directory.

Options:
  -h, --help              Show this help and exit.
EOF
}
handle_help_request "$@"

ensure_docker

say 'Starting the development frontend ...'
compose up -d frontend

ok 'Development frontend is starting at http://localhost:4200'
say 'The first run installs npm packages into a volume and can take a few minutes.'
say 'Watch the progress with:  docker compose logs -f frontend'
