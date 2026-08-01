# shellcheck shell=bash
#
# Shared helpers for the simple-feed-reader helper scripts.
#
# This file is sourced, never run directly: by scripts/update.sh and the four
# frontend start/stop scripts, and by scripts/install.sh once it has cloned the
# repository. Every function is safe to call from any working directory —
# REPO_ROOT is derived from this file's own location, and compose() always runs
# from there.

# --- coloured output --------------------------------------------------------
# Colour only when stdout is a terminal, so piped and CI output stay plain.
if [ -t 1 ]; then
  _c_reset=$'\033[0m'
  _c_blue=$'\033[34m'
  _c_green=$'\033[32m'
  _c_yellow=$'\033[33m'
  _c_red=$'\033[31m'
  _c_bold=$'\033[1m'
else
  _c_reset='' _c_blue='' _c_green='' _c_yellow='' _c_red='' _c_bold=''
fi

say()  { printf '%s\n' "${_c_blue}==>${_c_reset} $*"; }
ok()   { printf '%s\n' "${_c_green}OK${_c_reset}  $*"; }
warn() { printf '%s\n' "${_c_yellow}warning:${_c_reset} $*" >&2; }
die()  { printf '%s\n' "${_c_red}error:${_c_reset} $*" >&2; exit 1; }

# --- repository location ----------------------------------------------------
# The repository root is the parent of the directory that holds this file. This
# resolves symlinks and does not depend on the caller's working directory.
_lib_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
REPO_ROOT=$(CDPATH='' cd -- "${_lib_dir}/.." && pwd -P)

# Run docker compose from the repository root, whatever the caller's CWD is.
# The subshell keeps the caller's working directory unchanged.
compose() {
  ( cd -- "${REPO_ROOT}" && docker compose "$@" )
}

# --- environment checks -----------------------------------------------------
ensure_docker() {
  command -v docker >/dev/null 2>&1 \
    || die "Docker is not installed. Install Docker Desktop: https://docs.docker.com/get-docker/"
  docker compose version >/dev/null 2>&1 \
    || die "The Docker Compose plugin is missing. Update Docker Desktop, or install the compose plugin."
  docker info >/dev/null 2>&1 \
    || die "Docker is installed but not running. Start Docker Desktop (or the Docker daemon) and try again."
}

# True when the named compose service has a running container.
service_running() {
  [ -n "$(compose ps --status running -q "$1" 2>/dev/null)" ]
}

# Best-effort warning about host ports the stack needs. Returns non-zero when at
# least one is occupied. Silent no-op when lsof is unavailable — docker compose
# gives the authoritative error either way.
check_ports_free() {
  command -v lsof >/dev/null 2>&1 || return 0
  local port occupied=0
  for port in "$@"; do
    if lsof -iTCP:"${port}" -sTCP:LISTEN -n -P >/dev/null 2>&1; then
      warn "Port ${port} is already in use — the stack needs it free."
      occupied=1
    fi
  done
  return "${occupied}"
}

# --- release tags -----------------------------------------------------------
# The highest plain-semver tag (vX.Y.Z) reachable from origin/main. The pattern
# filter is essential: once develop is merged into main, the vX.Y.Z-dev.N deploy
# tags become reachable from main too, and must never be chosen as a release.
# Prints nothing (and still succeeds) when no release tag exists yet.
latest_release_tag() {
  git -C "${REPO_ROOT}" tag --merged origin/main --list 'v*' 2>/dev/null \
    | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' \
    | sort -V \
    | tail -n 1 \
    || true
}

# The tag currently checked out, or a placeholder when the checkout is not on a
# release tag (a branch, or a commit between tags).
current_version() {
  git -C "${REPO_ROOT}" describe --tags --exact-match 2>/dev/null || echo '(unreleased)'
}

# The git blob id of the frontend lockfile at HEAD — a cheap change detector
# that needs no external hashing tool.
lockfile_blob() {
  git -C "${REPO_ROOT}" rev-parse 'HEAD:frontend/package-lock.json' 2>/dev/null || echo none
}

# --- TLS certificate --------------------------------------------------------
# Create the locally trusted certificate nginx serves. Idempotent: mkcert
# overwrites in place. Installing the mkcert CA into the system trust store is a
# separate step, handled by the caller with confirmation.
generate_certificate() {
  mkdir -p "${REPO_ROOT}/docker/certs"
  ( cd -- "${REPO_ROOT}" && mkcert \
      -cert-file docker/certs/localhost.pem \
      -key-file docker/certs/localhost-key.pem \
      localhost 127.0.0.1 ::1 )
}

# --- stack lifecycle --------------------------------------------------------
# Start every service and bring the backend to a ready state: dependencies
# installed and the database migrated. Safe to re-run.
bring_up_stack() {
  say "Starting the Docker stack ..."
  compose up -d
  say "Installing backend dependencies (the first run downloads them) ..."
  compose exec -T php composer install --no-interaction
  say "Applying database migrations ..."
  compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
}

# Poll the API health endpoint until it reports ok, or time out after two
# minutes. Uses curl -k on purpose: this is a liveness probe, so whether curl's
# CA bundle trusts the mkcert certificate is irrelevant, and -k avoids a
# confusing TLS error while the stack is still warming up.
wait_for_health() {
  local url='https://localhost:8443/api/health'
  local deadline=$(( SECONDS + 120 ))
  say "Waiting for the API at ${url} ..."
  while [ "${SECONDS}" -lt "${deadline}" ]; do
    if curl -fsk "${url}" 2>/dev/null | grep -q '"status":"ok"'; then
      ok "API is healthy."
      return 0
    fi
    sleep 3
  done
  return 1
}

# --- summary ----------------------------------------------------------------
print_summary() {
  printf '\n%s\n' "${_c_bold}simple-feed-reader is running${_c_reset}"
  cat <<'SUMMARY'

  Frontend (dev server) .....  http://localhost:4200
  API .......................  https://localhost:8443/api/health
  Mailpit inbox .............  http://localhost:8025
  MySQL .....................  127.0.0.1:33306  (user/pass: feedreader/feedreader)

  Everyday commands (run from the simple-feed-reader directory):
    ./scripts/frontend-stop.sh         stop the dev frontend
    ./scripts/frontend-prod-start.sh   build & start the production preview (:8444)
    ./scripts/update.sh                update to the latest release
    docker compose logs -f frontend    watch the first-run npm install
    docker compose down                stop everything (your data is kept)

SUMMARY
}
