#!/usr/bin/env bash
set -euo pipefail

# One-line installer for simple-feed-reader.
#
#   curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
#
# It clones the repository, checks out the latest release, and brings the whole
# Docker stack up: MySQL, the PHP API, nginx with a locally trusted certificate,
# Mailpit, and the Angular dev server. Nothing here deletes data.
#
# Optional: pass a target directory. Default is ./simple-feed-reader.
#   curl -fsSL <url> | bash -s -- my-folder

REPO_URL='https://github.com/larspohlmann/simple-feed-reader.git'
TARGET_DIR="${1:-simple-feed-reader}"

# Minimal output helpers for the bootstrap phase, before lib.sh is available.
# Once the repository is cloned we source lib.sh, which defines the full set.
if [ -t 1 ]; then
  _b_blue=$'\033[34m' _b_red=$'\033[31m' _b_reset=$'\033[0m'
else
  _b_blue='' _b_red='' _b_reset=''
fi
say() { printf '%s\n' "${_b_blue}==>${_b_reset} $*"; }
die() { printf '%s\n' "${_b_red}error:${_b_reset} $*" >&2; exit 1; }

# Ask a yes/no question. Reads from the terminal, not stdin, because stdin is the
# script itself when this runs through `curl | bash`.
confirm() {
  local prompt="$1" answer
  if [ ! -r /dev/tty ]; then
    return 1
  fi
  printf '%s [y/N] ' "${prompt}" >/dev/tty
  read -r answer </dev/tty || return 1
  case "${answer}" in
    [yY] | [yY][eE][sS]) return 0 ;;
    *) return 1 ;;
  esac
}

# --- 1. prerequisites -------------------------------------------------------
say 'Checking prerequisites ...'

command -v git >/dev/null 2>&1 \
  || die 'git is not installed. Install it from https://git-scm.com/downloads and try again.'

command -v docker >/dev/null 2>&1 \
  || die 'Docker is not installed. Install Docker Desktop: https://docs.docker.com/get-docker/'
docker compose version >/dev/null 2>&1 \
  || die 'The Docker Compose plugin is missing. Update Docker Desktop, or install the compose plugin.'
docker info >/dev/null 2>&1 \
  || die 'Docker is installed but not running. Start Docker Desktop (or the Docker daemon) and try again.'

if ! command -v mkcert >/dev/null 2>&1; then
  case "$(uname -s)" in
    Darwin) die 'mkcert is not installed. Install it with:  brew install mkcert' ;;
    Linux)  die 'mkcert is not installed. Install it with your package manager, e.g.  sudo apt install mkcert' ;;
    *)      die 'mkcert is not installed. See https://github.com/FiloSottile/mkcert#installation' ;;
  esac
fi

# --- 2. clone ---------------------------------------------------------------
if [ -e "${TARGET_DIR}" ]; then
  die "The directory '${TARGET_DIR}' already exists. To update an existing install, run:  cd ${TARGET_DIR} && ./scripts/update.sh"
fi

say "Cloning simple-feed-reader into ./${TARGET_DIR} ..."
git clone --quiet "${REPO_URL}" "${TARGET_DIR}"
cd "${TARGET_DIR}"

# From here on the repository is present, so use its shared helpers.
# shellcheck source=scripts/lib.sh
source scripts/lib.sh

# --- 3. check out the latest release ----------------------------------------
release_tag=$(latest_release_tag)
[ -n "${release_tag}" ] \
  || die 'No release tag (vX.Y.Z) exists on main yet. See docs/releasing.md, then re-run.'

say "Checking out the latest release: ${release_tag}"
git -C "${REPO_ROOT}" checkout --quiet "${release_tag}"

# --- 4. local certificate authority -----------------------------------------
# Generating the certificate never touches the system trust store. Installing
# the mkcert CA does, so ask first.
caroot=$(mkcert -CAROOT 2>/dev/null || true)
if [ -n "${caroot}" ] && [ -f "${caroot}/rootCA.pem" ]; then
  ok "mkcert local CA already present (${caroot})."
else
  say 'mkcert can install a local certificate authority into your system trust store.'
  say 'That is what makes your browser trust https://localhost with no warning.'
  if confirm 'Install the mkcert local CA now?'; then
    mkcert -install
  else
    warn 'Skipped. The stack still runs, but your browser will warn about the certificate.'
  fi
fi

say 'Generating the local TLS certificate ...'
generate_certificate

# --- 5. bring the stack up --------------------------------------------------
check_ports_free 4200 8080 8443 8025 \
  || warn 'Free the ports listed above (or stop what is using them); docker will fail otherwise.'

bring_up_stack

# --- 6. verify and report ---------------------------------------------------
if wait_for_health; then
  ok "Installed ${release_tag}."
else
  warn 'The API did not report healthy in time. It may still be starting.'
  warn 'Check the logs with:  docker compose logs -f php nginx'
fi

print_summary
