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
  local url="${1:-https://localhost:8443/api/health}"
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
    ./scripts/prod-start.sh            run the production stack (docs/docker-production.md)
    ./scripts/update.sh                update to the latest release
    docker compose logs -f frontend    watch the first-run npm install
    docker compose down                stop everything (your data is kept)

SUMMARY
}

# --- production stack -------------------------------------------------------
# The prod stack is a standalone compose file under its own project name, so
# it can never collide with (or inherit from) the dev stack. All three flags
# travel together -- always go through prod_compose.
ENV_PROD_FILE="${REPO_ROOT}/.env.prod"

prod_compose() {
  ( cd -- "${REPO_ROOT}" \
      && docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml \
           --env-file .env.prod "$@" )
}

# The value of KEY in .env.prod, '' when absent. Surrounding double quotes are
# stripped, matching how docker compose reads the file.
env_prod_get() {
  local line
  line=$(grep -E "^$1=" "${ENV_PROD_FILE}" 2>/dev/null | tail -n 1 || true)
  line=${line#*=}
  line=${line#\"}
  line=${line%\"}
  printf '%s' "${line}"
}

# Set KEY to VALUE in .env.prod, replacing the existing line or appending.
# Pure shell on purpose: sed -i differs between BSD and GNU, and awk -v
# mangles backslashes in values.
env_prod_set() {
  local key=$1 value=$2 tmp replaced=0 line
  tmp=$(mktemp)
  # The `|| [ -n "${line}" ]` keeps a final line that has no trailing newline:
  # plain `while read` drops it, since read fails (empty $line) at EOF.
  while IFS= read -r line || [ -n "${line}" ]; do
    case "${line}" in
      "${key}="*)
        printf '%s=%s\n' "${key}" "${value}"
        replaced=1
        ;;
      *)
        printf '%s\n' "${line}"
        ;;
    esac
  done < "${ENV_PROD_FILE}" > "${tmp}"
  if [ "${replaced}" -eq 0 ]; then
    printf '%s=%s\n' "${key}" "${value}" >> "${tmp}"
  fi
  mv "${tmp}" "${ENV_PROD_FILE}"
}

# The .env.prod values docker-compose.prod.yml refuses to start without.
# Keep in step with the ${VAR:?} interpolations there.
ENV_PROD_REQUIRED='PUBLIC_URL MAILER_DSN MAIL_FROM MYSQL_ROOT_PASSWORD MYSQL_PASSWORD APP_SECRET ALTCHA_HMAC_KEY JWT_PASSPHRASE'

# Names of required values that are still empty, one per line. Empty output
# means the file is complete.
env_prod_missing() {
  local key
  for key in ${ENV_PROD_REQUIRED}; do
    if [ -z "$(env_prod_get "${key}")" ]; then
      printf '%s\n' "${key}"
    fi
  done
}

# 64 hex characters of real randomness -- the same shape `openssl rand -hex 32`
# produces everywhere else in this project's docs.
generate_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 32
    return 0
  fi
  od -An -tx1 -N32 /dev/urandom | tr -d ' \n'
  printf '\n'
}

# Percent-encode for safe embedding in a DSN: RFC 3986 unreserved characters
# pass through, everything else becomes %XX. A raw '#' or '@' in a hand-typed
# DSN truncates it silently, which is why the installer never asks for one.
url_encode() {
  local raw=$1 out='' ch i
  # Force the C locale for this function: with a multibyte locale, ${#raw} and
  # ${raw:i:1} index by glyph instead of byte, and bracket ranges like [a-z]
  # match by collation instead of byte value -- both let non-ASCII bytes slip
  # through unescaped. Under C, indexing is per-byte and the range is literal.
  local LC_ALL=C
  for (( i = 0; i < ${#raw}; i++ )); do
    ch=${raw:i:1}
    case "${ch}" in
      [a-zA-Z0-9.~_-]) out="${out}${ch}" ;;
      # "'${ch}" yields the byte's ordinal, sign-extended to a negative number
      # for bytes >= 0x80 (signed char); masking with 255 folds it back into
      # an unsigned byte before formatting.
      *) out="${out}$(printf '%%%02X' "$(( $(printf '%d' "'${ch}") & 255 ))")" ;;
    esac
  done
  printf '%s' "${out}"
}

# Interactive prompts. All read from /dev/tty, never stdin -- stdin is the
# script itself under `curl | bash`. Without a terminal they return the
# default (or nothing), so callers degrade to the two-step flow.
prompt_with_default() {
  local question=$1 default=$2 answer
  if [ ! -r /dev/tty ]; then
    printf '%s' "${default}"
    return 0
  fi
  printf '%s [%s]: ' "${question}" "${default}" >/dev/tty
  IFS= read -r answer </dev/tty || answer=''
  printf '%s' "${answer:-${default}}"
}

prompt_value() {
  local question=$1 answer
  if [ ! -r /dev/tty ]; then
    return 0
  fi
  printf '%s: ' "${question}" >/dev/tty
  IFS= read -r answer </dev/tty || answer=''
  printf '%s' "${answer}"
}

prompt_secret_value() {
  local question=$1 answer
  if [ ! -r /dev/tty ]; then
    return 0
  fi
  printf '%s: ' "${question}" >/dev/tty
  IFS= read -rs answer </dev/tty || answer=''
  printf '\n' >/dev/tty
  printf '%s' "${answer}"
}

prod_certs_present() {
  [ -f "${REPO_ROOT}/docker/certs-prod/fullchain.pem" ] \
    && [ -f "${REPO_ROOT}/docker/certs-prod/privkey.pem" ]
}

# The local base URL of the running prod stack (for probes and the summary).
# This is the LOCAL view; the public origin is PUBLIC_URL and may differ.
prod_base_url() {
  local port
  if prod_certs_present; then
    port=$(env_prod_get WEB_TLS_PORT)
    port=${port:-443}
    if [ "${port}" = "443" ]; then
      printf 'https://localhost'
    else
      printf 'https://localhost:%s' "${port}"
    fi
    return 0
  fi
  port=$(env_prod_get WEB_HTTP_PORT)
  port=${port:-80}
  if [ "${port}" = "80" ]; then
    printf 'http://localhost'
  else
    printf 'http://localhost:%s' "${port}"
  fi
}

# The prod php entrypoint writes var/.ready after the cache warmup; console
# commands (migrations, key generation) must not race it.
wait_for_php_ready() {
  local deadline=$(( SECONDS + 180 ))
  say 'Waiting for the PHP runtime ...'
  while [ "${SECONDS}" -lt "${deadline}" ]; do
    if prod_compose exec -T php test -f var/.ready 2>/dev/null; then
      ok 'PHP runtime is ready.'
      return 0
    fi
    sleep 2
  done
  die 'The PHP container did not become ready. Check:  docker compose -p simple-feed-reader-prod logs php'
}

print_prod_summary() {
  local base_url public_url
  base_url=$(prod_base_url)
  public_url=$(env_prod_get PUBLIC_URL)
  printf '\n%s\n\n' "${_c_bold}simple-feed-reader (production) is running${_c_reset}"
  printf '  Public URL ........  %s\n' "${public_url}"
  printf '  Local health ......  %s/api/health\n' "${base_url}"
  printf '\n'
  printf '  Create the first admin (docs/first-run-setup.md):\n'
  printf '    docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \\\n'
  printf '      exec -u www-data php bin/console app:admin:create you@example.com\n'
  printf '\n'
  printf '  Verify mail delivery (docs/docker-production.md):\n'
  printf '    docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \\\n'
  printf '      exec -u www-data php bin/console mailer:test you@example.com\n'
  printf '\n'
  printf '  Stop the stack (data is kept):  ./scripts/prod-stop.sh\n'
  printf '  Update to a new release:        see docs/docker-production.md\n'
  printf '\n'
}

# Ask a yes/no question on the terminal. Named distinctly from the
# installers' own bootstrap confirm() (defined before lib.sh is available,
# and still the only definition install-dev.sh has post-source) so sourcing
# this file never shadows theirs -- a same-named redefinition would leave
# their local confirm() dead code, and install-dev.sh is a byte-copy that
# cannot be edited to react to it.
prompt_confirm() {
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

# A DSN safe to print: the password between the userinfo ':' and the '@' is
# replaced with ***. DSNs without credentials pass through unchanged.
mask_dsn_password() {
  local dsn=$1 scheme rest userinfo hostpart
  case "${dsn}" in
    *://*@*)
      scheme=${dsn%%://*}
      rest=${dsn#*://}
      userinfo=${rest%@*}
      hostpart=${rest##*@}
      case "${userinfo}" in
        *:*) userinfo="${userinfo%%:*}:***" ;;
      esac
      printf '%s://%s@%s' "${scheme}" "${userinfo}" "${hostpart}"
      ;;
    *)
      printf '%s' "${dsn}"
      ;;
  esac
}

# The interactive configuration the installer and prod-configure.sh share.
# Each question defaults to the CURRENT .env.prod value where one exists and
# writes the answer back with env_prod_set. Without a terminal the prompts
# degrade to their defaults (configure_mail changes nothing at all), so the
# installer's two-step fallback keeps working.

configure_public_url() {
  local current public_url scheme hostport derived_port
  current=$(env_prod_get PUBLIC_URL)
  public_url=$(prompt_with_default 'Public URL of this instance (as users will reach it)' "${current:-http://localhost}")
  public_url=${public_url%/}
  env_prod_set PUBLIC_URL "${public_url}"

  # The port in that URL is the port the browser uses, so it is also the
  # port this stack publishes. A reverse proxy in between is the exception,
  # and there WEB_HTTP_PORT / WEB_BIND_ADDRESS stay a hand edit in .env.prod.
  scheme=${public_url%%://*}
  hostport=${public_url#*://}
  hostport=${hostport%%/*}
  derived_port=''
  case "${hostport}" in
    *:*) derived_port=${hostport##*:} ;;
  esac
  case "${derived_port}" in
    '' | *[!0-9]*)
      if [ "${scheme}" = "https" ]; then
        derived_port=443
      else
        derived_port=80
      fi
      ;;
  esac
  if [ "${scheme}" = "https" ]; then
    env_prod_set WEB_TLS_PORT "${derived_port}"
  else
    env_prod_set WEB_HTTP_PORT "${derived_port}"
  fi
  say "The app will serve on port ${derived_port} (taken from that URL)."
  say 'Behind a reverse proxy, set WEB_HTTP_PORT and WEB_BIND_ADDRESS in .env.prod by hand instead.'
}

# Which transport the last configure_mail round set: 1 relay, 2 host MTA,
# empty = left as it was. offer_mail_check reads it.
CONFIGURED_MAIL_CHOICE=''

configure_mail() {
  local current_dsn choice smtp_host smtp_port smtp_user smtp_password
  local public_url mail_host current_from mail_from
  CONFIGURED_MAIL_CHOICE=''
  if [ ! -r /dev/tty ]; then
    return 0
  fi
  current_dsn=$(env_prod_get MAILER_DSN)
  say 'How should the app send mail? Registration and password reset depend on it.'
  if [ -n "${current_dsn}" ]; then
    say "Currently: $(mask_dsn_password "${current_dsn}")"
  fi
  printf '  1) An SMTP relay (your mail provider): host, port, user, password\n' >/dev/tty
  printf "  2) This server's own MTA (postfix/exim listening on localhost:25)\n" >/dev/tty
  printf '  3) Skip: leave the mail transport as it is\n' >/dev/tty
  choice=$(prompt_with_default 'Choice' '1')
  case "${choice}" in
    1)
      smtp_host=$(prompt_value 'SMTP host (e.g. smtp.example.org)')
      smtp_port=$(prompt_with_default 'SMTP port' '587')
      smtp_user=$(prompt_value 'SMTP username')
      smtp_password=$(prompt_secret_value 'SMTP password (not echoed)')
      if [ -n "${smtp_host}" ] && [ -n "${smtp_user}" ] && [ -n "${smtp_password}" ]; then
        env_prod_set MAILER_DSN "smtp://$(url_encode "${smtp_user}"):$(url_encode "${smtp_password}")@${smtp_host}:${smtp_port}"
        CONFIGURED_MAIL_CHOICE=1
      else
        warn 'Incomplete SMTP details -- leaving MAILER_DSN unchanged.'
      fi
      ;;
    2)
      env_prod_set MAILER_DSN 'smtp://host.docker.internal:25'
      CONFIGURED_MAIL_CHOICE=2
      say 'Using the MTA on this machine. Delivery is only as good as its setup'
      say '(SPF, DKIM, reverse DNS) -- watch the first real mail.'
      ;;
    *)
      : # keep the current transport
      ;;
  esac
  if [ -n "${CONFIGURED_MAIL_CHOICE}" ]; then
    public_url=$(env_prod_get PUBLIC_URL)
    mail_host=${public_url#*://}
    mail_host=${mail_host%%/*}
    mail_host=${mail_host%%:*}
    current_from=$(env_prod_get MAIL_FROM)
    mail_from=$(prompt_with_default 'From: address for account mail' "${current_from:-simple-feed-reader@${mail_host}}")
    if [ -n "${mail_from}" ]; then
      env_prod_set MAIL_FROM "${mail_from}"
    fi
  fi
}

# Offer a live delivery check when configure_mail just set a transport. A
# wrong relay password should surface NOW, not at the first lost mail.
offer_mail_check() {
  local recipient
  if [ -z "${CONFIGURED_MAIL_CHOICE}" ]; then
    return 0
  fi
  if ! prompt_confirm 'Send a test mail now to verify delivery?'; then
    return 0
  fi
  recipient=$(prompt_value 'Recipient address')
  if [ -n "${recipient}" ]; then
    prod_compose exec -T -u www-data php bin/console mailer:test "${recipient}"
    ok "Test mail handed to the transport. Check the ${recipient} inbox (and its spam folder)."
  fi
}
