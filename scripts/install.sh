#!/usr/bin/env bash
set -euo pipefail

# One-line PRODUCTION installer for simple-feed-reader.
#
#   curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
#
# It clones the repository, checks out the latest release, writes .env.prod
# with freshly generated secrets, asks for the few values only you know (which
# package to install, the public origin, and how to send mail), and starts the
# production stack: the production PHP image, nginx serving the built app, the
# worker, and whatever the package adds beside them.
#
# The package is the first question, and the only one that says what the answer
# costs: S, M and L each print what they run and how much memory the stack
# needs. S is SQLite in a file, with no container besides the app's own three;
# M adds MySQL; L adds MySQL and a Meilisearch container. Search works in all
# three -- S and M answer it from the database, matching titles and summaries,
# and L matches the full text of every article as well.
#
# Two more keys decide how much you are asked. Q -- the default -- is the quick
# install: the S stack, and no other question at all, because it answers the
# rest for you (http://localhost:3333, and no mail; both changeable later with
# ./scripts/prod-configure.sh). C is the opposite: every question, the database
# and the search engine included.
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
#
# Optional: --ref <branch-or-tag> (or SFR_REF) installs that ref instead of the
# latest release -- how a production install is tried before it is released.
#   curl -fsSL <url> | bash -s -- --ref feature/430-installer-output my-folder

REPO_URL="${SFR_REPO_URL:-https://github.com/larspohlmann/simple-feed-reader.git}"

# Minimal output helpers for the bootstrap phase, before lib.sh is available.
# Once the repository is cloned we source lib.sh, which defines the full set.
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
  _b_blue=$'\033[34m' _b_red=$'\033[31m' _b_reset=$'\033[0m'
else
  _b_blue='' _b_red='' _b_reset=''
fi
say() { printf '%s\n' "${_b_blue}==>${_b_reset} $*"; }
die() { printf '%s\n' "${_b_red}error:${_b_reset} $*" >&2; exit 1; }

# --- arguments --------------------------------------------------------------
# lib.sh holds the canonical parser (parse_ref_args), but it lives inside the
# clone these very arguments decide, so the loop is repeated here -- the same
# reason say() and die() above are repeated. Keep the two in step.
REF="${SFR_REF:-}"
TARGET_DIR=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --ref)
      [ "$#" -ge 2 ] || die 'Option --ref needs a branch or a tag name.'
      REF=$2
      shift 2
      ;;
    --ref=*) REF=${1#--ref=} ; shift ;;
    -*) die "Unknown option: $1  (usage: install.sh [--ref <branch-or-tag>] [target-directory])" ;;
    *) TARGET_DIR=$1 ; shift ;;
  esac
done
TARGET_DIR=${TARGET_DIR:-simple-feed-reader}

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

# From here on the repository is present, so use its shared helpers -- for now
# the ones the clone's default branch carries, because the checkout in step 3
# is what decides which version of everything this install really runs.
# shellcheck source=scripts/lib.sh
source scripts/lib.sh

# --- 3. check out what to install -------------------------------------------
# An explicit --ref is checked out verbatim and skips the release lookup
# entirely: installing a branch that has no release yet is the whole point of
# the option. Plain git, not a lib.sh helper: the ref being installed may well
# be the commit that ADDS the helper, and the file on disk right now is the
# one the clone's default branch carries.
if [ -n "${REF}" ]; then
  say "Checking out ${REF} ..."
  git -C "${REPO_ROOT}" checkout --quiet "${REF}" \
    || die "No branch or tag named '${REF}' in this repository."
else
  release_tag=$(latest_release_tag)
  [ -n "${release_tag}" ] \
    || die 'No release tag (vX.Y.Z) exists on main yet. See docs/releasing.md, then re-run.'

  say "Checking out the latest release: ${release_tag}"
  git -C "${REPO_ROOT}" checkout --quiet "${release_tag}"
fi

[ -f "${REPO_ROOT}/.env.prod.example" ] \
  || die 'That version predates the Docker production path -- use a newer ref, or scripts/install-dev.sh.'

# Source again, now that the checkout decided which helpers this install runs:
# installing a ref means running ITS lib.sh, not whichever version the clone's
# default branch happened to carry. Without this, the install is a mixture of
# two revisions.
# shellcheck source=scripts/lib.sh
source "${REPO_ROOT}/scripts/lib.sh"

if [ -n "${REF}" ]; then
  note_unreleased_ref "${REF}"
else
  record_installed_release "${release_tag}"
fi

# Collect every warning raised from here on, so the closing block repeats it.
notes_start

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
env_prod_set AI_KEY_SECRET "$(generate_secret)"
env_prod_set JWT_PASSPHRASE "$(generate_secret)"
env_prod_set MYSQL_ROOT_PASSWORD "$(generate_secret)"
env_prod_set MYSQL_PASSWORD "$(generate_secret)"

# --- 6. the values only the operator knows ----------------------------------
# The package comes first: it decides the database and the search engine
# together, and prints what each combination costs in memory. So the two
# questions below run only for the operator who answered C and wants to decide
# them one at a time.
configure_package
if quick_package_chosen; then
  # Q asked to be asked nothing more, so the two remaining questions are not
  # printed -- but their defaults are still WRITTEN, because a question nobody
  # asks leaves nobody to fill the value in, and the stack refuses to start
  # without it.
  apply_default_public_origin
  use_no_mail
else
  configure_public_url
  if custom_package_chosen; then
    configure_database
    # 'n', so that pressing return through the C path lands where the default
    # package S does. A fresh .env.prod has no engine decision on file to read
    # back -- an empty MEILISEARCH_URL here means "nobody has been asked", not
    # "declined" -- which is why this caller passes a literal at all. See
    # configure_search_engine's own comment for the prod-configure.sh contrast.
    configure_search_engine 'n'
  fi
  configure_mail
fi

# --- 7. start, or explain how to --------------------------------------------
missing=$(env_prod_missing)
if [ -n "${missing}" ]; then
  while IFS= read -r name; do
    warn "${name} in .env.prod is still empty."
  done <<< "${missing}"
  # This path never reaches step 8, so its closing block says who fills the
  # onboarding catalog instead: the admin area does it with one click.
  print_unfinished_summary "${TARGET_DIR}"
  exit 0
fi

# The stack prints no closing block of its own here: the install still has the
# catalog and the mail check to do, and the block a first-time operator reads
# must be the LAST thing on the screen (issue #430).
SFR_DEFER_SUMMARY=1 "${REPO_ROOT}/scripts/prod-start.sh"

# --- 8. fill the onboarding catalog -----------------------------------------
# A new instance has an empty catalog, and an empty catalog makes the picker
# the first user meets an empty screen. Seed it from the document this release
# ships, then fetch the icons that go with it. Only the installer does this:
# once an instance is running, the catalog is the admin's -- what they edit,
# add and delete stays that way.
seed_catalog

# --- 9. verify mail delivery ------------------------------------------------
offer_mail_check

# --- 10. what the operator needs ---------------------------------------------
print_prod_summary
