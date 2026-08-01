#!/usr/bin/env bash
set -euo pipefail

# One-line PRODUCTION installer for simple-feed-reader.
#
#   curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
#
# It clones the repository, checks out the latest release, writes .env.prod
# with freshly generated secrets, asks for the few values only you know (the
# public URL and how to send mail), and starts the production stack: MySQL,
# the production PHP image, and nginx serving the built app. Nothing here
# deletes data.
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

# Ask a yes/no question. Reads from the terminal, not stdin, because stdin is
# the script itself when this runs through `curl | bash`.
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

# --- 4. write .env.prod -----------------------------------------------------
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

# --- 5. the values only the operator knows ----------------------------------
public_url=$(prompt_with_default 'Public URL of this instance (as users will reach it)' 'http://localhost')
public_url=${public_url%/}
env_prod_set PUBLIC_URL "${public_url}"

# Derive a plausible From: domain from the public URL for the prompt default.
mail_host=${public_url#*://}
mail_host=${mail_host%%/*}
mail_host=${mail_host%%:*}

mail_choice='3'
if [ -r /dev/tty ]; then
  say 'How should the app send mail? Registration and password reset depend on it.'
  printf '  1) An SMTP relay (your mail provider): host, port, user, password\n' >/dev/tty
  printf "  2) This server's own MTA (postfix/exim listening on localhost:25)\n" >/dev/tty
  printf '  3) Later: I will edit .env.prod myself\n' >/dev/tty
  mail_choice=$(prompt_with_default 'Choice' '1')
fi

case "${mail_choice}" in
  1)
    smtp_host=$(prompt_value 'SMTP host (e.g. smtp.example.org)')
    smtp_port=$(prompt_with_default 'SMTP port' '587')
    smtp_user=$(prompt_value 'SMTP username')
    smtp_password=$(prompt_secret_value 'SMTP password (not echoed)')
    if [ -n "${smtp_host}" ] && [ -n "${smtp_user}" ] && [ -n "${smtp_password}" ]; then
      env_prod_set MAILER_DSN "smtp://$(url_encode "${smtp_user}"):$(url_encode "${smtp_password}")@${smtp_host}:${smtp_port}"
    else
      warn 'Incomplete SMTP details -- leaving MAILER_DSN for you to fill in.'
    fi
    ;;
  2)
    env_prod_set MAILER_DSN 'smtp://host.docker.internal:25'
    say 'Using the MTA on this machine. Delivery is only as good as its setup'
    say '(SPF, DKIM, reverse DNS) -- watch the first real mail.'
    ;;
  *)
    : # configure later -- the two-step fallback below handles it
    ;;
esac

if [ "${mail_choice}" = "1" ] || [ "${mail_choice}" = "2" ]; then
  mail_from=$(prompt_with_default 'From: address for account mail' "simple-feed-reader@${mail_host}")
  if [ -n "${mail_from}" ]; then
    env_prod_set MAIL_FROM "${mail_from}"
  fi
fi

# --- 6. start, or explain how to --------------------------------------------
missing=$(env_prod_missing)
if [ -n "${missing}" ]; then
  warn 'These required values in .env.prod are still empty:'
  while IFS= read -r name; do
    printf '    %s\n' "${name}" >&2
  done <<< "${missing}"
  say "Finish the setup in two steps:"
  say "  1. Edit ${TARGET_DIR}/.env.prod (the comments explain every value)."
  say "  2. Run:  cd ${TARGET_DIR} && ./scripts/prod-start.sh"
  exit 0
fi

"${REPO_ROOT}/scripts/prod-start.sh"

# --- 7. verify mail delivery ------------------------------------------------
# A wrong relay password should surface NOW, not at the first lost
# registration. mailer:test uses the real configured transport.
if [ "${mail_choice}" = "1" ] || [ "${mail_choice}" = "2" ]; then
  if confirm 'Send a test mail now to verify delivery?'; then
    recipient=$(prompt_value 'Recipient address')
    if [ -n "${recipient}" ]; then
      prod_compose exec -T -u www-data php bin/console mailer:test "${recipient}"
      ok "Test mail handed to the transport. Check the ${recipient} inbox (and its spam folder)."
    fi
  fi
fi
