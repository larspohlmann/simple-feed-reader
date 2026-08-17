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
# Colour only when stdout is a terminal, so piped and CI output stay plain, and
# never when NO_COLOR carries a value (https://no-color.org/).
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
  _c_reset=$'\033[0m'
  _c_blue=$'\033[34m'
  _c_green=$'\033[32m'
  _c_yellow=$'\033[33m'
  _c_red=$'\033[31m'
  _c_cyan=$'\033[36m'
  _c_bold=$'\033[1m'
  _c_dim=$'\033[2m'
else
  _c_reset='' _c_blue='' _c_green='' _c_yellow='' _c_red='' _c_cyan='' _c_bold='' _c_dim=''
fi

say()  { printf '%s\n' "${_c_blue}==>${_c_reset} $*"; }
ok()   { printf '%s\n' "${_c_green}OK${_c_reset}  $*"; }
warn() { printf '%s\n' "${_c_yellow}warning:${_c_reset} $*" >&2; record_note "$*"; }
die()  { printf '%s\n' "${_c_red}error:${_c_reset} $*" >&2; exit 1; }

# --- notes for the closing block --------------------------------------------
# Every warning is also recorded, so the block printed at the very end can
# repeat it. Minutes of container output separate a warning from the end of an
# install, and "the catalog import failed" is the line that must not scroll
# away (issue #430).
#
# A file, not an array: the questions run inside $( ), so a warning raised
# there happens in a subshell that could never write back to a variable of this
# shell. The exported path makes every subshell append to the same file.
notes_start() {
  [ -z "${SFR_NOTES_FILE:-}" ] || return 0
  SFR_NOTES_FILE=$(mktemp "${TMPDIR:-/tmp}/sfr-notes.XXXXXX") || return 0
  export SFR_NOTES_FILE
  trap 'rm -f "${SFR_NOTES_FILE}"' EXIT
}

record_note() {
  [ -n "${SFR_NOTES_FILE:-}" ] || return 0
  printf '%s\n' "$*" >> "${SFR_NOTES_FILE}" 2>/dev/null || true
}

# Print what was recorded, then forget it. Forgetting matters where two
# summaries follow each other (update.sh updates both stacks): the notes belong
# under the first block, not under every block.
print_notes() {
  [ -n "${SFR_NOTES_FILE:-}" ] && [ -s "${SFR_NOTES_FILE}" ] || return 0
  printf '  %sThings to check%s\n' "${_c_yellow}${_c_bold}" "${_c_reset}"
  while IFS= read -r note; do
    printf '    - %s\n' "${note}"
  done < "${SFR_NOTES_FILE}"
  printf '\n'
  : > "${SFR_NOTES_FILE}"
}

# --- long phases ------------------------------------------------------------
# A phase whose output is worth watching but must not look like the point of
# the run: the header names it, its output is indented and dimmed underneath.
# Only for commands that never want the terminal -- a prompt inside one would
# be swallowed by the pipe.
run_step() {
  local label=$1 status=0
  shift
  printf '%s\n' "${_c_blue}==>${_c_reset} ${_c_bold}${label}${_c_reset}"
  # pipefail inside the subshell only: the status has to be the command's and
  # not the indenter's, while the caller's shell options stay as they were.
  ( set -o pipefail; "$@" 2>&1 | indent_output ) || status=$?
  return "${status}"
}

# The `|| [ -n "${line}" ]` keeps a last line that carries no newline.
indent_output() {
  local line
  while IFS= read -r line || [ -n "${line}" ]; do
    printf '    %s%s%s\n' "${_c_dim}" "${line}" "${_c_reset}"
    line=''
  done
}

# --- repository location ----------------------------------------------------
# The repository root is the parent of the directory that holds this file. This
# resolves symlinks and does not depend on the caller's working directory.
_lib_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
REPO_ROOT=$(CDPATH='' cd -- "${_lib_dir}/.." && pwd -P)

# Run docker compose from the repository root, whatever the caller's CWD is.
# The subshell keeps the caller's working directory unchanged.
#
# stdin is /dev/null for EVERY compose call, and that is load-bearing.
# `docker compose exec` attaches the caller's stdin and drains it whole, even
# when the command it runs never reads a byte. Under `curl ... | bash` the
# caller's stdin IS the installer script that bash is still reading, so the
# first exec swallowed the rest of it and bash exited 0 at the surprise EOF --
# silently skipping every step after the first exec (issue #275). Nothing here
# ever wants the caller's input: the prompts read /dev/tty.
compose() {
  ( cd -- "${REPO_ROOT}" && docker compose "$@" ) < /dev/null
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

# --- the ref this install runs on -------------------------------------------
# Both installers and update.sh take --ref <branch-or-tag>, so a production
# install can be tried from a branch before it is released (issue #430). The
# installers cannot use this parser -- lib.sh lives in the clone the arguments
# decide -- so each carries its own copy of the loop and says so.
#
# Sets REF and TARGET_DIR. Any non-option argument is the target directory,
# which update.sh never passes and simply ignores.
parse_ref_args() {
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
      -*) die "Unknown option: $1" ;;
      *) TARGET_DIR=$1 ; shift ;;
    esac
  done
  # Exported for the same reason DEV_HEALTH_URL is: every reader lives in a
  # script that only sources this file, so lint sees the writes and no reads.
  export REF TARGET_DIR
}

# Record an install that runs something other than a release, and say so once,
# where it happens. The installers call this AFTER their checkout, because the
# checkout is what puts this very function on disk.
note_unreleased_ref() {
  # Deliberately not warn(): the closing block carries this in a row of its
  # own, and "Things to check" is for what went wrong, not for what was asked
  # for.
  printf '%s\n' "${_c_yellow}note:${_c_reset} installing from '$1', which is not a release. Expect unreleased code."
  SFR_INSTALLED_REF="$1"
  SFR_INSTALLED_REF_IS_RELEASE=0
  export SFR_INSTALLED_REF SFR_INSTALLED_REF_IS_RELEASE
}

# The same for update.sh, which -- unlike an installer -- already runs the
# version of this file it will keep running, so it can check out from here.
checkout_requested_ref() {
  local ref=$1
  git -C "${REPO_ROOT}" checkout --quiet "${ref}" \
    || die "No branch or tag named '${ref}' in this repository."
  note_unreleased_ref "${ref}"
}

# The counterpart for the normal path, so both record what the summary prints.
record_installed_release() {
  SFR_INSTALLED_REF="$1"
  SFR_INSTALLED_REF_IS_RELEASE=1
  export SFR_INSTALLED_REF SFR_INSTALLED_REF_IS_RELEASE
}

# The summary row naming what is installed. Falls back to the checked-out tag,
# so prod-start.sh run on its own still answers the question.
print_installed_ref_row() {
  local ref="${SFR_INSTALLED_REF:-$(current_version)}"
  if [ "${SFR_INSTALLED_REF_IS_RELEASE:-1}" -eq 1 ]; then
    printf '  Version ...........  %s\n' "${ref}"
    return 0
  fi
  printf '  Version ...........  %s%s -- not a release%s\n' "${_c_yellow}" "${ref}" "${_c_reset}"
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
  run_step 'Starting the Docker stack' compose up -d
  run_step 'Installing backend dependencies (the first run downloads them)' \
    compose exec -T php composer install --no-interaction
  run_step 'Applying database migrations' \
    compose exec -T php bin/console doctrine:migrations:migrate --no-interaction
  # It may have started before the schema existed (first install).
  compose restart worker >/dev/null
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
# The closing block is the only thing a first-time operator has to read, so it
# comes last, after every long phase and every question, and it is set apart
# from the output above it by a rule (issue #430). The rule is ASCII: a box
# drawn in line characters breaks on a narrow terminal and in a non-UTF-8
# locale, and would buy nothing this does not.
print_rule() {
  printf '%s%s%s\n' "${_c_dim}" '------------------------------------------------------------' "${_c_reset}"
}

# The closing block for the path that stopped before the stack ever started:
# .env.prod still misses values only the operator knows. It gets the same frame
# as a running instance, because this is the path where finding the
# instructions matters most.
print_unfinished_summary() {
  local target_dir=$1
  printf '\n'
  print_rule
  printf '%s\n\n' "${_c_bold}${_c_yellow}simple-feed-reader is installed, but not started${_c_reset}"
  printf '  Finish the setup:\n'
  printf '    1. Run:  %s   (asks again, then starts)\n' "${_c_cyan}cd ${target_dir} && ./scripts/prod-configure.sh${_c_reset}"
  printf '       or edit %s/.env.prod by hand -- the comments explain every value.\n' "${target_dir}"
  printf '    2. Hand-edited? Then run:  %s\n' "${_c_cyan}cd ${target_dir} && ./scripts/prod-start.sh${_c_reset}"
  printf '    3. Fill the onboarding catalog in the admin area, under Catalog.\n'
  printf '\n'
  print_installed_ref_row
  printf '\n'
  print_notes
  print_rule
  printf '\n'
}

print_summary() {
  printf '\n'
  print_rule
  printf '%s\n\n' "${_c_bold}${_c_green}simple-feed-reader (development) is running${_c_reset}"
  printf '  Frontend (dev) ....  %s\n' "${_c_cyan}http://localhost:4200${_c_reset}"
  printf '  API ...............  %s\n' "${_c_cyan}https://localhost:8443/api/health${_c_reset}"
  printf '  Mailpit inbox .....  %s\n' "${_c_cyan}http://localhost:8025${_c_reset}"
  printf '  MySQL .............  127.0.0.1:33306  (user/pass: feedreader/feedreader)\n'
  print_installed_ref_row
  printf '\n'
  cat <<'SUMMARY'
  Everyday commands (run from the simple-feed-reader directory):
    ./scripts/frontend-stop.sh         stop the dev frontend
    ./scripts/prod-start.sh            run the production stack (docs/docker-production.md)
    ./scripts/update.sh                update to the latest release
    docker compose logs -f frontend    watch the first-run npm install
    docker compose down                stop everything (your data is kept)

SUMMARY
  print_notes
  print_rule
  printf '\n'
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

# The DSN a SQLite install runs on: one file on the php-var volume, which
# already outlives its container. Symfony resolves %kernel.project_dir%
# (doctrine.yaml reads DATABASE_URL through env(resolve:)), the same form
# backend/.env uses for the development database.
PROD_SQLITE_DATABASE_URL='sqlite:///%kernel.project_dir%/var/data.db'

# Whether the stack runs the bundled MySQL container. An EMPTY DATABASE_URL
# means it does -- that is what docker-compose.prod.yml falls back to, and what
# every .env.prod written before the SQLite option contains, so an existing
# install keeps its database without editing anything. Any other value points
# the app somewhere else (a SQLite file, an external server), and then the
# bundled container has no reason to start.
prod_uses_bundled_mysql() {
  [ -z "$(env_prod_get DATABASE_URL)" ]
}

# Trim leading and trailing whitespace -- POSIX character-class parameter
# expansion, no external process. Used below so the shell's idea of "empty"
# matches PHP's trim(), the way SearchEngineCapability::isConfigured() defines
# it (backend/src/Service/Search/SearchEngineCapability.php).
trim_whitespace() {
  local value=$1
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf '%s' "${value}"
}

# Whether the stack runs the bundled Meilisearch container. A non-empty
# MEILISEARCH_URL means it does -- docker-compose.prod.yml puts the
# meilisearch service behind that profile, the same way prod_uses_bundled_mysql
# reads DATABASE_URL above. Empty is the default and is a fully working state,
# not a degraded one: EntrySearchWithFallback answers every search from the
# database when no engine is configured (see backend/config/services.yaml).
#
# Trimmed before the emptiness check, matching
# SearchEngineCapability::isConfigured() on the PHP side: a whitespace-only
# value (only reachable by hand-editing .env.prod) must not read as configured
# here while the backend refuses to talk to it -- that mismatch would start a
# container the app never uses.
prod_uses_search_engine() {
  [ -n "$(trim_whitespace "$(env_prod_get MEILISEARCH_URL)")" ]
}

# The compose profiles the stack runs with: 'mysql' for the bundled database,
# 'meilisearch' for the bundled search engine, both comma-separated when both
# are on, nothing at all when neither is. docker-compose.prod.yml puts each
# service behind its own profile.
prod_compose_profiles() {
  local profiles=''
  if prod_uses_bundled_mysql; then
    profiles='mysql'
  fi
  if prod_uses_search_engine; then
    profiles="${profiles:+${profiles},}meilisearch"
  fi
  printf '%s' "${profiles}"
}

# stdin is /dev/null here for the same reason as in compose() above -- see the
# comment there before removing it.
prod_compose() {
  ( cd -- "${REPO_ROOT}" \
      && COMPOSE_PROFILES="$(prod_compose_profiles)" \
         docker compose -p "${PROD_PROJECT_NAME}" -f docker-compose.prod.yml \
           --env-file .env.prod "$@" ) < /dev/null
}

# `docker compose up -d` only STARTS services that are in the active
# profiles -- it never stops one that has just fallen OUT of them, and
# nothing else in this flow calls `down` or `rm`. Without this, declining the
# search engine on a re-configure leaves its container running (consuming
# memory and disk) while prod_search_engine_description tells the operator it
# is off. prod-start.sh calls this on every run, after `up`, so the container
# always matches the current MEILISEARCH_URL decision by the time the summary
# is printed.
#
# Naming the service explicitly targets it even though 'meilisearch' is not
# in COMPOSE_PROFILES for this call -- verified against a throwaway compose
# project before relying on it, since compose's profile rules are easy to
# get wrong. `rm -sf` stops it first, then removes the container; without
# `-v` it never removes a volume, named or anonymous, so meili-data survives
# whether or not the container existed. No container at all -- the common
# case, an install that never enabled the engine -- is a no-op that still
# exits 0, which `service_running`-style status checks would not be: it looks
# for a RUNNING container, and a stopped-but-not-removed one would slip past.
stop_disabled_search_engine_container() {
  if prod_uses_search_engine; then
    return 0
  fi
  if [ -z "$(prod_compose ps -aq meilisearch 2>/dev/null)" ]; then
    return 0
  fi
  say 'The search engine is disabled -- removing its container (the meili-data volume is kept) ...'
  prod_compose rm -sf meilisearch >/dev/null
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
# AI_KEY_SECRET is deliberately NOT in this list, same as ADMIN_SETUP_SECRET:
# it is machine-generated, never operator-supplied, so there is nothing for a
# human to "fill in". Listing it here would make env_prod_missing/die below
# abort an upgrading instance before ensure_ai_key_secret ever runs -- the
# exact outage that helper exists to prevent.

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

# Generate AI_KEY_SECRET when it is still empty. An instance installed before
# #305 has no such variable, and %env(AI_KEY_SECRET)% that cannot resolve fails
# the container build -- every route, not just the AI ones. Generating here
# keeps the upgrade uneventful. Never regenerate a value that exists: that
# would silently make every stored API key unreadable.
ensure_ai_key_secret() {
  if [ -z "$(env_prod_get AI_KEY_SECRET)" ]; then
    env_prod_set AI_KEY_SECRET "$(generate_secret)"
  fi
}

# Generate MEILISEARCH_KEY when it is still empty, never regenerate one that
# exists -- the same rule as ensure_ai_key_secret above, for the same reason
# (rotating it would make Meilisearch reject the app's own requests). Unlike
# AI_KEY_SECRET this is not called unconditionally from prod-start.sh: an
# empty MEILISEARCH_URL/KEY is the "no engine, database answers searches"
# state by design (see MeilisearchIndex and its services.yaml wiring), so an
# instance upgrading from before #432 needs nothing generated for it. This is
# called only from configure_search_engine's "yes" branch, where a key is
# actually about to be used.
ensure_meilisearch_key() {
  if [ -z "$(env_prod_get MEILISEARCH_KEY)" ]; then
    env_prod_set MEILISEARCH_KEY "$(generate_secret)"
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
  # --if-empty guards the case this script cannot see: an install pointed at a
  # database that already holds a catalog.
  if ! run_step 'Importing the bundled catalog' \
    prod_compose exec -T -u www-data php bin/console app:catalog:import --if-empty; then
    warn 'The bundled catalog was not imported. Import it in the admin area under Catalog.'
    return 0
  fi
  # Minutes: one request per catalog feed, and the shipped document holds over
  # a hundred. The icons are cached in the database, so this is paid once and
  # survives every later update.
  if ! run_step 'Fetching the catalog favicons (this takes a few minutes)' \
    prod_compose exec -T -u www-data php bin/console app:catalog:warm-favicons; then
    warn 'Some catalog favicons are missing. The admin area can fetch them again later.'
  fi
}

# What the summary says the instance stores its data in. A backup needs the
# right volume, so the answer names it.
prod_database_description() {
  if prod_uses_bundled_mysql; then
    printf 'MySQL, in the mysql-data volume'
    return 0
  fi
  printf 'SQLite, one file in the php-var volume'
}

# What the summary says search runs on. A backup needs to know the meili-data
# volume matters here; an operator who declined the engine needs the summary
# to say so plainly, since the database fallback is silent otherwise.
prod_search_engine_description() {
  if prod_uses_search_engine; then
    printf 'Meilisearch, in the meili-data volume'
    return 0
  fi
  printf 'the database (no engine configured)'
}

# Show the setup secret ONLY while the instance still has no administrator
# (the API is authoritative); printing it on every later run would put a
# live-looking secret into scrollback for no benefit. If the status probe
# fails the block is skipped -- the console command below always works.
print_first_admin_block() {
  local base_url=$1 public_url=$2 setup_status admin_setup_secret
  admin_setup_secret=$(env_prod_get ADMIN_SETUP_SECRET)
  [ -n "${admin_setup_secret}" ] || return 0
  setup_status=$(curl -fsk --max-time 5 "${base_url}/api/setup/status" 2>/dev/null || true)
  case "${setup_status}" in
    *'"needsSetup":true'*) ;;
    *) return 0 ;;
  esac
  printf '  %sNo administrator exists yet. Create the first one in the browser:%s\n' "${_c_bold}" "${_c_reset}"
  printf '    1. Open %s -- the one-time setup screen appears instead of login.\n' "${_c_cyan}${public_url}${_c_reset}"
  printf '    2. Enter your email, a password, and this setup secret:\n'
  printf '         %s\n' "${_c_bold}${admin_setup_secret}${_c_reset}"
  printf '    3. Afterwards, remove ADMIN_SETUP_SECRET from .env.prod.\n'
  printf '\n'
}

print_prod_summary() {
  local base_url public_url
  base_url=$(prod_base_url)
  public_url=$(env_prod_get PUBLIC_URL)
  printf '\n'
  print_rule
  printf '%s\n\n' "${_c_bold}${_c_green}simple-feed-reader (production) is running${_c_reset}"
  printf '  Public URL ........  %s\n' "${_c_cyan}${public_url}${_c_reset}"
  printf '  Local health ......  %s\n' "${_c_cyan}${base_url}/api/health${_c_reset}"
  printf '  Database ..........  %s\n' "$(prod_database_description)"
  printf '  Search ............  %s\n' "$(prod_search_engine_description)"
  print_installed_ref_row
  printf '\n'
  print_first_admin_block "${base_url}" "${public_url}"
  print_notes
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
  print_rule
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
# a fresh .env.prod carries the plain-HTTP default, which is the wrong offer to
# make to someone who just chose to put a proxy in front of it.
#
# The offered default for plain HTTP and for a proxy is 3333 -- "FEED" on a
# phone keypad. 80 and 8080 are the two ports a machine that already serves
# something is most likely to have taken, and a busy port is the one failure
# this script cannot fix for the operator (issue #430). TLS keeps 443: there
# the answer IS the port users type, and WEB_HTTP_PORT stays on 80 because in
# that topology it is the redirect listener browsers reach first.
PROD_DEFAULT_HTTP_PORT='3333'

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
    *) printf '%s' "${PROD_DEFAULT_HTTP_PORT}" ;;
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
    # The other port becomes the redirect listener, and a redirect only helps
    # where browsers look: someone typing http://host reaches port 80 or
    # nothing. This is why 3333 is the default for plain HTTP but never here.
    env_prod_set WEB_HTTP_PORT '80'
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

# --- which package the instance installs -------------------------------------
# The first question of a fresh install (#453). The database and the search
# engine used to be two questions, asked apart and neither saying what its
# answer costs -- so an operator had to answer two of them to settle the one
# thing they actually wanted to decide: how big this install is going to be.
# This question settles it once, and prints the measured memory figure of every
# combination.
#
# It is not a second code path. Each package selects between the appliers the
# two sub-questions already use, so the reason those write their value rather
# than leaving the file alone (see use_bundled_mysql_database and
# use_bundled_search_engine) holds here unchanged.
#
# Five keys, three of them stacks: S, M and L are what runs. Q and C are how
# much the operator wants to be asked -- Q nothing at all, C every question the
# installer has. Only C adds the database and the search-engine questions to
# the origin and mail ones every package asks, which makes it the only way to
# reach a combination the three stacks do not cover (SQLite with a search
# engine).
#
# prod-configure.sh does not ask it. A package implies a database, and
# switching databases needs a manual data move -- the same reason
# configure_database is never re-asked there.

# What pressing return selects: Q, the quick install -- the S stack with every
# remaining question answered from its own default. It is the answer the
# largest group of operators wants, it starts no container nobody asked for,
# and it is the only package that can promise a first start without a single
# further decision.
PACKAGE_DEFAULT='Q'

# What no terminal applies, which is deliberately NOT Q. Q's promise is about
# questions, and without a terminal there are none to skip: the installer
# writes .env.prod and stops for the values only a human knows (the mail
# transport), exactly as it documents. So the headless package is S -- the same
# stack Q installs, through the same appliers, without Q's promise that nothing
# else will be asked.
PACKAGE_HEADLESS='S'

# Which package the last configure_package round selected: S, M, L, Q or C.
# install.sh reads it through custom_package_chosen and quick_package_chosen.
CONFIGURED_PACKAGE=''

configure_package() {
  local choice
  if ! can_prompt; then
    apply_package "${PACKAGE_HEADLESS}"
    return 0
  fi
  say 'Which package should this instance install?'
  package_question_line S
  package_question_line M
  package_question_line L
  package_question_line Q
  package_question_line C
  package_question_follow_up
  while :; do
    choice=$(prompt_with_default 'Package' "${PACKAGE_DEFAULT}")
    case "${choice}" in
      [sS]) apply_package S ; return 0 ;;
      [mM]) apply_package M ; return 0 ;;
      [lL]) apply_package L ; return 0 ;;
      [qQ]) apply_package Q ; return 0 ;;
      [cC]) apply_package C ; return 0 ;;
    esac
    # Five keys are not a y/n question: apply_search_engine_choice can read
    # every non-'n' answer as yes, but here a typo would install a stack the
    # operator did not choose. So ask again instead.
    tell 'Answer S, M, L, Q or C.'
  done
}

# The one-line description of each package. Kept as data because README.md
# carries the same three lines -- an operator chooses the package while reading
# the README and answers it in the terminal, so the two texts must not
# disagree. scripts/test/configure-package.test.sh compares them.
package_description() {
  case "$1" in
    S) printf '%s' 'a personal instance. SQLite, title and summary search.' ;;
    M) printf '%s' 'several users. MySQL, title and summary search.' ;;
    L) printf '%s' 'like M, plus Meilisearch for full-content search.' ;;
    Q) printf '%s' 'quick: the S package, and nothing else to answer.' ;;
    C) printf '%s' 'choose everything yourself, database and engine included.' ;;
  esac
}

# The second line a package gets, where one line cannot carry it. Only the
# default has one: it is the answer most operators press return on, so what it
# decides on their behalf has to be on the screen before they do -- an install
# that silently picks an origin and a mail setting is the one thing a default
# must not be.
package_note() {
  case "$1" in
    Q) printf '%s' 'It answers the rest for you: http://localhost:3333, and no mail.' ;;
  esac
}

# What is still open after the answer. The five lines above say what runs; this
# says what the operator will get to decide, which is the other half of the
# choice and the reason C exists at all.
#
# Printed once rather than per line: it is the same sentence for the three
# stacks, and the two keys that differ are the ones it names. Q is not in it --
# its own note above says what it decides instead of asking.
package_question_follow_up() {
  tell ''
  tell '  S, M and L ask for the public URL and for mail next.'
  tell '  C asks for those two as well, plus the database and the search engine.'
}

# What the stack needs, measured and not estimated: read from an idle, healthy
# install carrying a real account of 107 feeds and 17,427 entries, with the
# kernel high-water mark of boot, restore, refresh and reindex as the upper
# bound (the figures are in issue #453). One figure for the whole stack rather
# than a per-container table, because php, worker and web cost the same 75-90
# MiB in all three packages -- only mysql and meilisearch separate them. C has
# no figure: it has no fixed set of containers. Q repeats S's, because Q runs
# S's containers and the line pressing return selects has to say what it costs
# without being read together with another one.
package_memory() {
  case "$1" in
    S | Q) printf '%s' 'Needs about 250 MB' ;;
    M) printf '%s' 'Needs about 1 GB' ;;
    L) printf '%s' 'Needs about 2.5 GB' ;;
  esac
}

# One line of the question. The key is bold cyan -- the colour every string
# these scripts want typed or opened already uses -- and the sentence is bold
# on the default package and dim on the other four, so the line pressing
# return selects is visible without reading them all.
#
# The colour comes from the script's own _c_* variables, which are empty unless
# stdout is a terminal, while tell() writes to /dev/tty. An install whose
# stdout is redirected therefore asks this question in plain text on the
# terminal. That is accepted rather than gated on /dev/tty separately: colour
# follows stdout everywhere else in these scripts, one mechanism keeps NO_COLOR
# working for free, and a test that greps this text never has to strip an
# escape.
package_question_line() {
  local key=$1 text style memory note
  style=${_c_dim}
  if [ "${key}" = "${PACKAGE_DEFAULT}" ]; then
    style=${_c_bold}
  fi
  text=$(package_description "${key}")
  memory=$(package_memory "${key}")
  if [ -n "${memory}" ]; then
    text="${text} ${memory}."
  fi
  tell "  ${_c_bold}${_c_cyan}${key}${_c_reset}${style}) ${text}${_c_reset}"
  note=$(package_note "${key}")
  if [ -n "${note}" ]; then
    tell "     ${style}${note}${_c_reset}"
  fi
}

# The package applied, shared by the question above and by the headless
# default: one documented default, one place that applies it.
#
# C writes nothing on purpose. It means "ask me the two questions", and a value
# written here would answer them before they are asked -- SQLite plus a search
# engine is a valid install, and C is the only way to reach it.
apply_package() {
  CONFIGURED_PACKAGE=$1
  case "$1" in
    S | Q) use_sqlite_database ; use_database_search ;;
    M) use_bundled_mysql_database ; use_database_search ;;
    L) use_bundled_mysql_database ; use_bundled_search_engine ;;
  esac
}

# Whether the operator asked to answer the database and search-engine questions
# themselves. Only install.sh calls it: the four other packages have applied
# both answers already, so asking again would overwrite the package.
custom_package_chosen() {
  [ "${CONFIGURED_PACKAGE}" = 'C' ]
}

# Whether the operator asked to be asked nothing more. Q is a promise about the
# questions, not a fifth stack: it installs S, and install.sh then applies the
# default of every remaining question instead of putting it on the screen.
#
# Those defaults are APPLIED, never skipped, for the reason the whole of #453
# turns on: .env.prod's empty state is not its documented default, and a
# question that is not asked still has to leave the file complete -- nothing
# else is going to write it.
quick_package_chosen() {
  [ "${CONFIGURED_PACKAGE}" = 'Q' ]
}

# The public origin the three origin questions would have offered, applied
# without asking: the topology .env.prod already describes (plain HTTP, as
# shipped), the hostname it already carries (localhost, as shipped), and the
# default port for that topology. Read from the same helpers the prompts
# default from, so a changed default reaches both paths.
apply_default_public_origin() {
  local topology hostname
  topology=$(current_topology)
  hostname=$(host_from_url "$(env_prod_get PUBLIC_URL)")
  apply_public_origin "${topology}" "${hostname:-localhost}" "$(default_port_for "${topology}")"
}

# --- which database the instance runs on ------------------------------------
# Asked by the installer, once, and only for package C -- every other package
# answers it through the same appliers below. Switching afterwards
# means moving the data from one engine to the other, which nothing here does
# -- so prod-configure.sh, the script that re-asks the questions of an existing
# install, deliberately does not ask this one.
#
# SQLite is the default. It was MySQL while this was the first thing an install
# decided, because that is what every install ran on before the question
# existed; since #453 the package question decides it instead, and its default
# (S, a personal instance) is the smallest stack that starts no container the
# operator did not ask for. Pressing return through the installer has to land
# in the same place whichever path leads here, so this default follows it.
#
# The numbering does not follow the default: 1 stays MySQL and 2 stays SQLite.
# Both lines are printed directly above the prompt, so nobody types a number
# from memory, and configure_mail already defaults to its last choice (4) --
# while renumbering would turn a copy of an older instruction into the other
# engine without a word.
configure_database() {
  local choice
  if ! can_prompt; then
    # Not an early return: an empty DATABASE_URL means MySQL, so doing nothing
    # here lands on the opposite of the documented default. Apply it explicitly
    # instead, the way configure_search_engine applies the caller's.
    use_sqlite_database
    return 0
  fi
  say 'Which database should this instance use?'
  tell '  1) MySQL: a database container beside the app (best for several users)'
  tell '  2) SQLite: a single file, no database container (fine for a personal instance)'
  choice=$(prompt_with_default 'Choice' '2')
  if [ "${choice}" = '1' ]; then
    use_bundled_mysql_database
    return 0
  fi
  use_sqlite_database
}

use_sqlite_database() {
  env_prod_set DATABASE_URL "${PROD_SQLITE_DATABASE_URL}"
  say 'Using SQLite. The database is one file on the php-var volume, and no'
  say 'database container starts.'
}

# Writes the value rather than leaving the file alone. Choosing MySQL has to be
# an answer even when the file already says something else -- a no-op here
# would silently keep a hand-edited SQLite DSN over the answer just given.
use_bundled_mysql_database() {
  env_prod_set DATABASE_URL ''
  say 'Using MySQL in a container beside the app.'
}

# --- whether the instance runs the bundled search engine ---------------------
# Asked by the installer for package C only -- S, M and L answer it through the
# appliers below -- and asked again by prod-configure.sh on every run.
#
# install.sh defaults it to NO. It defaulted to yes while this was one of the
# first questions a fresh install met, on the argument that full-content search
# over article bodies is materially better than the database's title/summary
# LIKE fallback. It is -- but that is an argument for choosing package L, not
# for adding a container to an install whose operator has not yet said how much
# memory the machine has. Since #453 the package question makes that decision,
# its default is S, and pressing return through the installer has to land on
# the same stack whichever path leads here (#453).
#
# Unlike configure_database, this is safe to re-ask. Turning the engine on (or
# off) later moves no data by hand: enabling it needs a URL, a key, and
# `app:search:reindex` to catch the index up from the database, which is
# already this instance's source of truth (EntrySearchWithFallback never
# stops reading it). So prod-configure.sh asks this question again on every
# run, the same as configure_mail -- see the call site there.
#
# prompt_confirm cannot be used here: its default is always no, and a re-ask of
# an install that runs the engine has to default to yes.
#
# A re-ask is a different question with a different right default, so the
# default stays a parameter rather than a hardcoded key. install.sh passes the
# literal 'n': a brand-new .env.prod has an empty MEILISEARCH_URL no matter what
# the operator is about to choose, so that emptiness cannot be read back as
# "the operator already said no" the way it safely can be for an existing
# install -- the value has to come from the caller either way.
# prod-configure.sh passes back current_search_engine_choice, which reads the
# stored value -- so pressing return through an unrelated question (a new
# public URL, a new mail transport) can never flip a decision the operator
# already made. "No" has to be as durable an answer as "yes": unlike mail,
# which must be configured somehow, running no engine is a complete, final
# answer for an install that cannot or does not want another container.
configure_search_engine() {
  local default=$1 choice
  if ! can_prompt; then
    # A silent no-op here always lands on "no": an empty MEILISEARCH_URL is
    # indistinguishable from a decline. So apply the caller's default
    # explicitly instead of doing nothing -- configure_database now does the
    # same for the same reason, its own empty state meaning MySQL rather than
    # the SQLite it defaults to. It is still just the default being applied, so
    # a re-ask (prod-configure.sh, default = current_search_engine_choice)
    # still cannot flip a stored decision: it only ever re-applies what is
    # already configured.
    apply_search_engine_choice "${default}"
    return 0
  fi
  say 'Enable full-content search?'
  tell '  A search engine container (Meilisearch) indexes the full text of'
  tell '  every article, not just its title and summary. Declining leaves'
  tell '  search running against the database, which always works and needs'
  tell '  no extra container -- the right choice on shared hosting, or any'
  tell '  machine that cannot run one.'
  choice=$(prompt_with_default 'Enable search engine? (y/n)' "${default}")
  apply_search_engine_choice "${choice}"
}

# The y/n answer applied, shared by the interactive path above and the
# headless default. Kept separate so headless never repeats the prompt logic.
apply_search_engine_choice() {
  case "$1" in
    [nN]*)
      use_database_search
      ;;
    *)
      use_bundled_search_engine
      ;;
  esac
}

# The default configure_search_engine offers on a re-ask: whatever this
# instance is already configured to run. Only prod-configure.sh calls this --
# a fresh install has no decision on file yet to read back, which is exactly
# why install.sh passes a literal instead ('n', the package default's answer).
current_search_engine_choice() {
  if prod_uses_search_engine; then
    printf 'y'
    return 0
  fi
  printf 'n'
}

use_database_search() {
  env_prod_set MEILISEARCH_URL ''
  say 'No search engine. Search runs against the database.'
}

# Writes the value rather than leaving the file alone, for the same reason as
# use_bundled_mysql_database above: choosing yes has to be an answer even on a
# re-run where MEILISEARCH_URL already says something else.
use_bundled_search_engine() {
  env_prod_set MEILISEARCH_URL 'http://meilisearch:7700'
  ensure_meilisearch_key
  say 'Using the bundled Meilisearch container. Run app:search:reindex once it'
  say 'is up to index what is already stored.'
}

# The host part of PUBLIC_URL -- the default mail domain both the transport
# branches and the no-mail placeholder derive MAIL_FROM from.
mail_host_from_public_url() {
  host_from_url "$(env_prod_get PUBLIC_URL)"
}

# The mail question's own default, applied: no outgoing mail, in the open.
# Called by the question's fourth branch and by the quick install (package Q),
# which reaches the same answer without printing the question.
#
# MAIL_FROM is set because docker-compose.prod.yml refuses to start without it,
# even when nothing is ever sent -- an instance that says "no mail" has to be a
# complete instance, not one more thing to fill in.
use_no_mail() {
  local mail_host
  env_prod_set MAIL_DISABLED 1
  env_prod_set MAILER_DSN 'null://null'
  if [ -z "$(env_prod_get MAIL_FROM)" ]; then
    mail_host=$(mail_host_from_public_url)
    env_prod_set MAIL_FROM "simple-feed-reader@${mail_host}"
    say "Mail is disabled -- set a placeholder From address (never sent): simple-feed-reader@${mail_host}"
  fi
  say 'Running without mail. Email confirmation and password-reset email are off.'
  say 'Recover a password with: docker compose ... exec php bin/console app:user:reset-password <email> --generate'
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
      use_no_mail
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
