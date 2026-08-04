# shellcheck shell=bash
#
# Shared helpers for the simple-feed-reader helper scripts.
#
# This file is sourced, never run directly: by the two frontend start/stop
# scripts, the three prod scripts (prod-start.sh, prod-stop.sh,
# prod-configure.sh), update.sh, and by install.sh and install-dev.sh once
# each has cloned the repository. Every function is safe to call from any
# working directory -- REPO_ROOT is derived from this file's own location,
# and compose() always runs from there.

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

# The dev stack's health probe; prod callers pass their own URL. Exported so
# lint does not flag it unused here -- every reference lives in a script that
# only sources this file (install-dev.sh, update.sh).
export DEV_HEALTH_URL='https://localhost:8443/api/health'

# Poll the API health endpoint until it reports ok, or time out after two
# minutes. Uses curl -k on purpose: this is a liveness probe, so whether curl's
# CA bundle trusts the mkcert certificate is irrelevant, and -k avoids a
# confusing TLS error while the stack is still warming up.
wait_for_health() {
  local url="$1"
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

# The compose project the prod stack runs under. Docker stamps it on every
# container, volume and network the stack creates, which is what makes an
# earlier install on this machine findable.
PROD_PROJECT_NAME='simple-feed-reader-prod'

prod_compose() {
  ( cd -- "${REPO_ROOT}" \
      && docker compose -p "${PROD_PROJECT_NAME}" -f docker-compose.prod.yml \
           --env-file .env.prod "$@" )
}

# --- what an earlier production install leaves behind -----------------------
# Stopping the stack keeps its named volumes. That is deliberate -- the data
# survives a restart -- but it turns a SECOND install into a trap. MySQL
# creates its user only while it initialises an EMPTY data directory, so a
# surviving mysql-data still holds the password of the first install while a
# new install writes a freshly generated one into .env.prod. Nothing connects
# after that, and the first sign of it is "Access denied for user" at the
# migration step (issue #272).
#
# The lists are printed one name per line, and empty when the machine is
# clean. Docker is queried by project label rather than by name, so the answer
# stays right if a volume is ever added or renamed.
prod_project_volumes() {
  docker volume ls -q \
    --filter "label=com.docker.compose.project=${PROD_PROJECT_NAME}" 2>/dev/null || true
}

prod_project_containers() {
  docker ps -aq \
    --filter "label=com.docker.compose.project=${PROD_PROJECT_NAME}" 2>/dev/null || true
}

# Delete everything the production project still owns here. Containers go
# first: docker refuses to remove a volume that any container still claims,
# including a stopped one.
remove_previous_prod_install() {
  local containers volumes
  containers=$(prod_project_containers)
  volumes=$(prod_project_volumes)
  say 'Removing the previous production install ...'
  if [ -n "${containers}" ]; then
    # Both lists are deliberately split into separate arguments.
    # shellcheck disable=SC2086
    docker rm -f ${containers} >/dev/null
  fi
  if [ -n "${volumes}" ]; then
    # shellcheck disable=SC2086
    docker volume rm ${volumes} >/dev/null
  fi
  ok 'The previous production install is gone. Installing into a clean machine.'
}

report_previous_prod_install() {
  local volume
  warn 'A previous production install is still on this machine.'
  say 'Docker kept its data when its containers went away:'
  while IFS= read -r volume; do
    printf '    %s\n' "${volume}"
  done <<< "$1"
  say 'A new install cannot use those volumes. MySQL keeps the password it was'
  say 'first created with, and this installer generates a new one, so the app'
  say 'would be refused with "Access denied for user" at its first query.'
}

# The two ways out that keep the data, printed before the install stops. This
# is also the path a terminal-less install takes, so it never guesses.
abort_for_previous_prod_install() {
  local volumes_on_one_line
  volumes_on_one_line=$(printf '%s' "$1" | tr '\n' ' ')
  say 'Nothing was removed. To KEEP that data, copy the .env.prod of the previous'
  say 'install into this directory and run ./scripts/prod-start.sh -- the passwords'
  say 'in that file are the only ones those volumes accept.'
  say 'To START OVER, stop the old stack if it still runs, then remove its data:'
  printf '    docker volume rm %s\n' "${volumes_on_one_line}"
  die 'Stopped. A previous production install is in the way.'
}

# Called by the installer before it generates a single secret. It either
# leaves the machine clean and returns, or it stops the install -- it never
# installs over volumes whose passwords it does not have.
handle_previous_prod_install() {
  local volumes
  volumes=$(prod_project_volumes)
  if [ -z "${volumes}" ]; then
    return 0
  fi
  report_previous_prod_install "${volumes}"
  if ! can_prompt; then
    warn 'This install has no terminal to ask on.'
    abort_for_previous_prod_install "${volumes}"
  fi
  if ! prompt_confirm 'Remove that install now? This DELETES its database.'; then
    abort_for_previous_prod_install "${volumes}"
  fi
  remove_previous_prod_install
}

# The value of KEY in .env.prod, '' when absent. Surrounding double quotes are
# stripped, matching how docker compose reads the file.
env_prod_get() {
  local line
  line=$(grep -E "^$1=" "${ENV_PROD_FILE}" 2>/dev/null | tail -n 1 || true)
  line=${line#*=}
  # A CRLF-edited .env.prod leaves a trailing \r on every value, which is
  # non-empty and would slip a blank-looking value past env_prod_missing.
  line=${line%$'\r'}
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

# Make the web first-admin setup usable out of the box: generate
# ADMIN_SETUP_SECRET when it is still empty, so a fresh install can be
# finished in the browser without a shell round-trip. Carrying the secret
# after the first admin exists is inert -- the setup endpoint self-disables
# on hasAnyAdmin -- and print_prod_summary only shows it while the API
# still reports that setup is needed.
ensure_admin_setup_secret() {
  if [ -z "$(env_prod_get ADMIN_SETUP_SECRET)" ]; then
    env_prod_set ADMIN_SETUP_SECRET "$(generate_secret)"
  fi
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

# Whether there is a terminal to ask a question on. The single place that
# decides it, so a caller that must behave differently without one asks the
# same question the prompts themselves ask.
can_prompt() {
  [ -r /dev/tty ]
}

prompt_with_default() {
  local question=$1 default=$2 answer
  if ! can_prompt; then
    printf '%s' "${default}"
    return 0
  fi
  printf '%s [%s]: ' "${question}" "${default}" >/dev/tty
  IFS= read -r answer </dev/tty || answer=''
  printf '%s' "${answer:-${default}}"
}

prompt_value() {
  local question=$1 answer
  if ! can_prompt; then
    return 0
  fi
  printf '%s: ' "${question}" >/dev/tty
  IFS= read -r answer </dev/tty || answer=''
  printf '%s' "${answer}"
}

# Prose that belongs to a question rather than to the script's output. It goes
# to the terminal, so it never lands in the stdout that a caller may be
# capturing with $( ), and it is dropped when there is no terminal -- a piped
# install has nobody to read it.
tell() {
  { printf '%s\n' "$*" >/dev/tty; } 2>/dev/null || true
}

prompt_secret_value() {
  local question=$1 answer
  if ! can_prompt; then
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

# The mode the web container will actually select: WEB_MODE override first,
# certificate presence otherwise. Mirrors docker/web/10-select-mode.sh.
prod_web_mode() {
  local mode
  mode=$(env_prod_get WEB_MODE)
  case "${mode}" in
    tls | http) printf '%s' "${mode}" ;;
    *) if prod_certs_present; then printf 'tls'; else printf 'http'; fi ;;
  esac
}

# The local base URL of the running prod stack (for probes and the summary).
# This is the LOCAL view; the public origin is PUBLIC_URL and may differ.
prod_base_url() {
  local port
  if [ "$(prod_web_mode)" = tls ]; then
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

# Fill the onboarding catalog of a NEW instance, and fetch the icons that go
# with it. The installer calls this, and nothing else does: from the first
# start onwards the catalog belongs to the admin, so an update must never
# re-apply the shipped document over what they have made of it (#272).
#
# Neither step is worth failing an install that is already up and serving --
# both are recoverable from the admin area with one click -- so a failure here
# warns and returns.
seed_catalog() {
  say 'Importing the bundled catalog ...'
  # --if-empty guards the case this script cannot see: an install pointed at a
  # database that already holds a catalog.
  if ! prod_compose exec -T -u www-data php bin/console app:catalog:import --if-empty; then
    warn 'The bundled catalog was not imported. Import it in the admin area under Catalog.'
    return 0
  fi
  # Minutes: one request per catalog feed, and the shipped document holds over
  # a hundred. The icons are cached in the database, so this is paid once and
  # survives every later update.
  say 'Fetching the catalog favicons (this takes a few minutes) ...'
  if ! prod_compose exec -T -u www-data php bin/console app:catalog:warm-favicons; then
    warn 'Some catalog favicons are missing. The admin area can fetch them again later.'
  fi
}

print_prod_summary() {
  local base_url public_url setup_status admin_setup_secret
  base_url=$(prod_base_url)
  public_url=$(env_prod_get PUBLIC_URL)
  printf '\n%s\n\n' "${_c_bold}simple-feed-reader (production) is running${_c_reset}"
  printf '  Public URL ........  %s\n' "${public_url}"
  printf '  Local health ......  %s/api/health\n' "${base_url}"
  printf '\n'
  # Show the setup secret ONLY while the instance still has no administrator
  # (the API is authoritative); printing it on every later run would put a
  # live-looking secret into scrollback for no benefit. If the status probe
  # fails the block is skipped -- the console command below always works.
  setup_status=$(curl -fsk --max-time 5 "${base_url}/api/setup/status" 2>/dev/null || true)
  admin_setup_secret=$(env_prod_get ADMIN_SETUP_SECRET)
  if [ -n "${admin_setup_secret}" ]; then
    case "${setup_status}" in
      *'"needsSetup":true'*)
        printf '  No administrator exists yet. Create the first one in the browser:\n'
        printf '    1. Open %s -- the one-time setup screen appears instead of login.\n' "${public_url}"
        printf '    2. Enter your email, a password, and this setup secret:\n'
        printf '         %s\n' "${admin_setup_secret}"
        printf '    3. Afterwards, remove ADMIN_SETUP_SECRET from .env.prod.\n'
        printf '\n'
        ;;
    esac
  fi
  printf '  Create the first admin over the shell instead (docs/first-run-setup.md):\n'
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
  if ! can_prompt; then
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

# The public origin is three independent decisions, so it is three questions:
# how users reach the instance, under which hostname, on which port. They were
# one "Public URL" prompt until issue #252, and packing them together meant a
# typo in any of them aborted the installer after the clone, an https:// answer
# with no certificate on disk produced an instance that served plain HTTP, and
# the reverse-proxy case -- the normal one on a host that already publishes
# port 80 -- could not be answered at all.
configure_public_url() {
  local topology hostname port
  topology=$(prompt_topology)
  hostname=$(prompt_hostname)
  port=$(prompt_port "${topology}")
  apply_public_origin "${topology}" "${hostname}" "${port}"
}

# --- question 1: how users reach the instance -------------------------------
# The topology .env.prod currently describes, so a re-run can offer it back. It
# is not a stored value: a loopback bind address only ever comes from the proxy
# answer, and the scheme decides the rest.
current_topology() {
  if [ "$(env_prod_get WEB_BIND_ADDRESS)" = '127.0.0.1' ]; then
    printf 'proxy'
    return 0
  fi
  case "$(env_prod_get PUBLIC_URL)" in
    https://*) printf 'tls' ;;
    *) printf 'http' ;;
  esac
}

topology_choice() {
  case "$1" in
    tls) printf '2' ;;
    proxy) printf '3' ;;
    *) printf '1' ;;
  esac
}

prompt_topology() {
  local answer
  tell ''
  tell 'How do users reach this instance?'
  tell '  1) Plain HTTP, direct -- a private instance, or a LAN'
  tell '  2) HTTPS, this stack serves the certificate'
  tell '  3) HTTPS, a reverse proxy in front terminates TLS (Caddy, Traefik, nginx)'
  while :; do
    answer=$(prompt_with_default 'Choice' "$(topology_choice "$(current_topology)")")
    case "${answer}" in
      1) printf 'http' ; return 0 ;;
      2) printf 'tls' ; return 0 ;;
      3) printf 'proxy' ; return 0 ;;
    esac
    tell 'Answer 1, 2 or 3.'
  done
}

# --- question 2: the hostname -----------------------------------------------
# The bare host of a URL: scheme, path and port removed.
host_from_url() {
  local value=$1
  value=${value#*://}
  value=${value%%/*}
  value=${value%%:*}
  printf '%s' "${value}"
}

# The question this replaced asked for a whole URL, so a pasted scheme, path or
# port is the expected answer here, not a reason to stop. Reduce it instead:
# question 1 owns the scheme and question 3 owns the port.
prompt_hostname() {
  local current answer
  current=$(host_from_url "$(env_prod_get PUBLIC_URL)")
  while :; do
    answer=$(host_from_url "$(prompt_with_default 'Hostname users type' "${current:-localhost}")")
    if [ -n "${answer}" ]; then
      printf '%s' "${answer}"
      return 0
    fi
    tell 'That is not a hostname. Enter a name such as reader.example.org, or localhost.'
  done
}

# --- question 3: the port ---------------------------------------------------
# The port behind a proxy is the one the proxy connects to, never the one users
# type, so the question says so.
port_question() {
  if [ "$1" = 'proxy' ]; then
    printf 'Port this stack listens on for the proxy (not the public port)'
    return 0
  fi
  printf 'Port users connect to'
}

# The stored port is a sensible default only while the topology is unchanged:
# a fresh .env.prod carries WEB_HTTP_PORT=80, and 80 is the wrong offer to make
# to someone who just chose to put a proxy in front of it.
default_port_for() {
  local topology=$1 stored=''
  if [ "${topology}" = "$(current_topology)" ]; then
    case "${topology}" in
      tls) stored=$(env_prod_get WEB_TLS_PORT) ;;
      *) stored=$(env_prod_get WEB_HTTP_PORT) ;;
    esac
  fi
  if [ -n "${stored}" ]; then
    printf '%s' "${stored}"
    return 0
  fi
  case "${topology}" in
    tls) printf '443' ;;
    proxy) printf '8080' ;;
    *) printf '80' ;;
  esac
}

is_port() {
  case "$1" in
    '' | *[!0-9]*) return 1 ;;
  esac
  [ "$1" -ge 1 ] && [ "$1" -le 65535 ]
}

# One host address publishes a port once, so on a machine that already runs
# other web apps a busy port is the normal outcome, not an exotic one. Ask
# again -- but accept the answer after three rounds, because check_ports_free
# is a best-effort probe (it is a silent no-op without lsof) and docker gives
# the authoritative error anyway.
prompt_port() {
  local topology=$1 default answer rounds=0
  default=$(default_port_for "${topology}")
  while :; do
    answer=$(prompt_with_default "$(port_question "${topology}")" "${default}")
    if ! is_port "${answer}"; then
      tell 'A port is a number from 1 to 65535.'
      continue
    fi
    rounds=$(( rounds + 1 ))
    if check_ports_free "${answer}" || [ "${rounds}" -ge 3 ]; then
      printf '%s' "${answer}"
      return 0
    fi
    tell 'Choose a free port, or stop what is using that one.'
  done
}

# --- applying the three answers ---------------------------------------------
apply_public_origin() {
  local topology=$1 hostname=$2 port=$3
  case "${topology}" in
    proxy)
      apply_proxy_origin "${hostname}" "${port}"
      ;;
    tls)
      apply_direct_origin https "${hostname}" "${port}"
      ensure_prod_certificate "${hostname}"
      ;;
    *)
      apply_direct_origin http "${hostname}" "${port}"
      ;;
  esac
}

# A URL that omits the scheme's own port. The spelling matters: an OAuth
# redirect URI is compared as an exact string, so https://host and
# https://host:443 are two different registrations, and no provider holds the
# second one.
public_url_for() {
  local scheme=$1 hostname=$2 port=$3
  if { [ "${scheme}" = 'http' ] && [ "${port}" = '80' ]; } \
    || { [ "${scheme}" = 'https' ] && [ "${port}" = '443' ]; }; then
    printf '%s://%s' "${scheme}" "${hostname}"
    return 0
  fi
  printf '%s://%s:%s' "${scheme}" "${hostname}" "${port}"
}

# This stack faces the browser, so the port answered is both the published port
# and the port in the URL. The bind address is reset because it may carry the
# loopback value a previous proxy answer wrote, which would leave the app
# reachable from nowhere.
apply_direct_origin() {
  local scheme=$1 hostname=$2 port=$3
  env_prod_set PUBLIC_URL "$(public_url_for "${scheme}" "${hostname}" "${port}")"
  if [ "${scheme}" = 'https' ]; then
    env_prod_set WEB_TLS_PORT "${port}"
  else
    env_prod_set WEB_HTTP_PORT "${port}"
  fi
  env_prod_set WEB_BIND_ADDRESS '0.0.0.0'
  say "The app will serve on port ${port}."
}

# The port the proxy connects to stays out of PUBLIC_URL -- users type the
# proxy's port. WEB_TLS_PORT has to move off 443 as well: the compose file
# publishes both ports in every mode, and 443 belongs to the proxy.
apply_proxy_origin() {
  local hostname=$1 port=$2 tls_port
  env_prod_set PUBLIC_URL "https://${hostname}"
  env_prod_set WEB_HTTP_PORT "${port}"
  env_prod_set WEB_BIND_ADDRESS '127.0.0.1'
  tls_port=$(env_prod_get WEB_TLS_PORT)
  if [ "${tls_port}" = '443' ] || [ "${tls_port}" = "${port}" ]; then
    if [ "${port}" = '8443' ]; then
      env_prod_set WEB_TLS_PORT '8444'
    else
      env_prod_set WEB_TLS_PORT '8443'
    fi
  fi
  say "This stack listens on 127.0.0.1:${port}, so only the proxy on this machine reaches it."
  say 'Point the proxy at it. A Caddyfile needs two lines:'
  # Printed bare, without the say() prefix: this is a snippet to copy.
  printf '\n    %s {\n        reverse_proxy 127.0.0.1:%s\n    }\n\n' "${hostname}" "${port}"
  say 'Runs the proxy on another machine? Set WEB_BIND_ADDRESS=0.0.0.0 in .env.prod.'
}

# --- the certificate for choice 2 -------------------------------------------
# Choice 2 only serves HTTPS once a certificate is on disk: the web container
# picks its mode from these two files (WEB_MODE=auto), so an HTTPS PUBLIC_URL
# over an empty docker/certs-prod/ serves plain HTTP and still looks
# configured. Say so, and offer mkcert where mkcert is the right tool.
ensure_prod_certificate() {
  local hostname=$1
  if prod_certs_present; then
    ok 'Certificate found in docker/certs-prod/.'
    return 0
  fi
  if command -v mkcert >/dev/null 2>&1; then
    tell ''
    tell "mkcert can issue a certificate for ${hostname} right now. Only machines"
    tell 'holding your mkcert root CA trust it: everyone else gets a browser warning,'
    tell 'and OAuth providers refuse it. That fits a private instance. A public one'
    tell 'needs a real certificate, or choice 3.'
    if prompt_confirm "Generate a locally trusted certificate for ${hostname}?"; then
      generate_prod_certificate "${hostname}"
      ok 'Certificate written to docker/certs-prod/.'
      return 0
    fi
  fi
  warn 'No certificate in docker/certs-prod/ yet, so the stack serves plain HTTP.'
  say 'Put these two files there, then run ./scripts/prod-start.sh again:'
  say '    docker/certs-prod/fullchain.pem'
  say '    docker/certs-prod/privkey.pem'
}

# The prod counterpart of generate_certificate: the names nginx expects, in the
# directory the prod compose file mounts, for the hostname just answered.
generate_prod_certificate() {
  mkdir -p "${REPO_ROOT}/docker/certs-prod"
  ( cd -- "${REPO_ROOT}" && mkcert \
      -cert-file docker/certs-prod/fullchain.pem \
      -key-file docker/certs-prod/privkey.pem \
      "$1" )
}

# The host part of PUBLIC_URL -- the default mail domain both the transport
# branches and the no-mail placeholder derive MAIL_FROM from.
mail_host_from_public_url() {
  host_from_url "$(env_prod_get PUBLIC_URL)"
}

# Which transport the last configure_mail round set: 1 relay, 2 host MTA,
# empty = left as it was. offer_mail_check reads it.
CONFIGURED_MAIL_CHOICE=''

configure_mail() {
  local current_dsn choice smtp_host smtp_port smtp_user smtp_password
  local mail_host current_from mail_from
  CONFIGURED_MAIL_CHOICE=''
  if ! can_prompt; then
    return 0
  fi
  current_dsn=$(env_prod_get MAILER_DSN)
  say 'How should the app send mail? Registration and password reset depend on it.'
  if [ -n "${current_dsn}" ]; then
    say "Currently: $(mask_dsn_password "${current_dsn}")"
  fi
  tell '  1) An SMTP relay (your mail provider): host, port, user, password'
  tell "  2) This server's own MTA (postfix/exim listening on localhost:25)"
  tell '  3) Later: finish mail by hand, or re-run ./scripts/prod-configure.sh'
  tell '  4) No mail: run without outgoing mail (registration/reset email off)'
  # No mail is the default because it is the answer that always works: a
  # private instance needs no relay, and an operator who has one can say so.
  # The opposite default asks four questions before the first start and turns
  # a wrong relay password into a broken instance.
  choice=$(prompt_with_default 'Choice' '4')
  case "${choice}" in
    1)
      env_prod_set MAIL_DISABLED ''
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
      env_prod_set MAIL_DISABLED ''
      env_prod_set MAILER_DSN 'smtp://host.docker.internal:25'
      CONFIGURED_MAIL_CHOICE=2
      say 'Using the MTA on this machine. Delivery is only as good as its setup'
      say '(SPF, DKIM, reverse DNS) -- watch the first real mail.'
      ;;
    4)
      env_prod_set MAIL_DISABLED 1
      env_prod_set MAILER_DSN 'null://null'
      if [ -z "$(env_prod_get MAIL_FROM)" ]; then
        mail_host=$(mail_host_from_public_url)
        env_prod_set MAIL_FROM "simple-feed-reader@${mail_host}"
        say "Mail is disabled -- set a placeholder From address (never sent): simple-feed-reader@${mail_host}"
      fi
      say 'Running without mail. Email confirmation and password-reset email are off.'
      say 'Recover a password with: docker compose ... exec php bin/console app:user:reset-password <email> --generate'
      ;;
    *)
      : # keep the current transport
      ;;
  esac
  if [ -n "${CONFIGURED_MAIL_CHOICE}" ]; then
    mail_host=$(mail_host_from_public_url)
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
