#!/usr/bin/env bash
set -euo pipefail

# One-line PRODUCTION installer for simple-feed-reader.
#
#   curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
#
# It clones the repository, checks out the latest release, writes .env.prod
# with freshly generated secrets, asks for the few values only you know (the
# public origin, which database, and how to send mail), and starts the
# production stack: the production PHP image, nginx serving the built app, and
# MySQL unless you answer SQLite.
#
# It deletes data in exactly one case, and only after you say yes to it: an
# earlier production install whose Docker volumes are still on this machine.
# Those volumes hold passwords this installer cannot reproduce, so it offers
# to remove them and otherwise stops (issue #272).
#
# Without a terminal (or when you skip the mail question) it stops after
# writing .env.prod and tells you exactly how to finish: edit the file, then
# run ./scripts/prod-start.sh.
#
# Looking for the DEVELOPMENT stack (live reload, xdebug, Mailpit)? Use
# scripts/install-dev.sh instead.
#
# Optional: pass a target directory. Default is ./simple-feed-reader.
#   curl -fsSL <url> | bash -s -- my-folder

REPO_URL="${SFR_REPO_URL:-https://github.com/larspohlmann/simple-feed-reader.git}"
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

# --- 1. prerequisites -------------------------------------------------------
say 'Checking prerequisites ...'

command -v git >/dev/null 2>&1 \
  || die 'git is not installed. Install it from https://git-scm.com/downloads and try again.'

command -v docker >/dev/null 2>&1 \
  || die 'Docker is not installed. Install Docker: https://docs.docker.com/get-docker/'
docker compose version >/dev/null 2>&1 \
  || die 'The Docker Compose plugin is missing. Update Docker, or install the compose plugin.'
docker info >/dev/null 2>&1 \
  || die 'Docker is installed but not running. Start the Docker daemon and try again.'

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

[ -f "${REPO_ROOT}/.env.prod.example" ] \
  || die "Release ${release_tag} predates the Docker production path -- update the release, or use scripts/install-dev.sh."

# --- 4. an earlier install on this machine ----------------------------------
# Before any secret is generated: the volumes of an earlier install outlive
# its containers, and the secrets written below would not fit them (#272).
handle_previous_prod_install

# --- 5. write .env.prod -----------------------------------------------------
if [ -f "${ENV_PROD_FILE}" ]; then
  die '.env.prod already exists in the fresh clone -- refusing to overwrite it.'
fi

say 'Writing .env.prod with freshly generated secrets ...'
cp "${REPO_ROOT}/.env.prod.example" "${ENV_PROD_FILE}"
env_prod_set APP_SECRET "$(generate_secret)"
env_prod_set ALTCHA_HMAC_KEY "$(generate_secret)"
env_prod_set JWT_PASSPHRASE "$(generate_secret)"
env_prod_set MYSQL_ROOT_PASSWORD "$(generate_secret)"
env_prod_set MYSQL_PASSWORD "$(generate_secret)"

# --- 6. the values only the operator knows ----------------------------------
configure_public_url
configure_database
configure_mail

# --- 7. start, or explain how to --------------------------------------------
missing=$(env_prod_missing)
if [ -n "${missing}" ]; then
  warn 'These required values in .env.prod are still empty:'
  while IFS= read -r name; do
    printf '    %s\n' "${name}" >&2
  done <<< "${missing}"
  say "Finish the setup in two steps:"
  say "  1. Run:  cd ${TARGET_DIR} && ./scripts/prod-configure.sh   (asks again, then starts)"
  say "     or edit ${TARGET_DIR}/.env.prod by hand (the comments explain every value)."
  say "  2. Hand-edited? Then run:  cd ${TARGET_DIR} && ./scripts/prod-start.sh"
  # Step 8 below is what fills the onboarding catalog, and this path never
  # reaches it. The admin area does the same thing with one click.
  say '  3. Fill the onboarding catalog in the admin area, under Catalog.'
  exit 0
fi

"${REPO_ROOT}/scripts/prod-start.sh"

# --- 8. fill the onboarding catalog -----------------------------------------
# A new instance has an empty catalog, and an empty catalog makes the picker
# the first user meets an empty screen. Seed it from the document this release
# ships, then fetch the icons that go with it. Only the installer does this:
# once an instance is running, the catalog is the admin's -- what they edit,
# add and delete stays that way.
seed_catalog

# --- 9. verify mail delivery ------------------------------------------------
offer_mail_check
