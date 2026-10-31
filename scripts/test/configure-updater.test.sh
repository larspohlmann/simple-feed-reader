#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's self-update question in lib.sh (#1948),
# mirroring configure-grafana.test.sh's harness. The updater holds the Docker
# socket, so it is opt-in: a fresh install defaults to 'n', a re-ask defaults
# to what is already configured, and the answer has to reach docker through
# the 'updater' compose profile.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

queue="${work}/answers"

prompt_with_default() {
  local default=$2 answer=''
  if [ -s "${queue}" ]; then
    answer=$(head -n 1 "${queue}")
    tail -n +2 "${queue}" > "${queue}.rest"
    mv "${queue}.rest" "${queue}"
  fi
  printf '%s' "${answer:-${default}}"
}

can_prompt() { return 0; }
docker_socket_gid() { printf '991'; }

assert_env() {
  local key=$1 want=$2 got
  got=$(env_prod_get "${key}")
  [ "${got}" = "${want}" ] || fail "${key}: want '${want}', got '${got}'"
}

fresh_install_answer_with() {
  local default=$1
  shift
  printf 'DATABASE_URL=\nMEILISEARCH_URL=\nGRAFANA_LOKI_PUSH_URL=\nUPDATER_ENABLED=\n' > "${ENV_PROD_FILE}"
  printf '%s\n' "$@" > "${queue}"
  configure_updater "${default}" > /dev/null 2>&1
}

# --- 1. an empty answer on a default of no leaves the updater off -----------
fresh_install_answer_with 'n' ''
printf 'UPDATER_ENABLED=1\n' > "${ENV_PROD_FILE}"
printf '\n' > "${queue}"
configure_updater 'n' > /dev/null 2>&1
assert_env UPDATER_ENABLED ''
if prod_uses_updater; then
  fail 'a default of no must not enable the updater'
fi
case "$(prod_compose_profiles)" in
  *updater*) fail 'the updater profile must be absent when declined' ;;
esac

# --- 2. yes writes every value the compose service reads --------------------
fresh_install_answer_with 'n' y
assert_env UPDATER_ENABLED 1
assert_env SFR_REPO_ROOT "${REPO_ROOT}"
assert_env UPDATER_UID "$(id -u)"
assert_env UPDATER_GID "$(id -g)"
assert_env UPDATER_SOCKET_GID 991
prod_uses_updater || fail 'answering yes must enable the updater'
case "$(prod_compose_profiles)" in
  *updater) ;;
  *) fail "the profile list must end in updater, got '$(prod_compose_profiles)'" ;;
esac

# --- 3. the stored choice is read back --------------------------------------
[ "$(current_updater_choice)" = y ] || fail 'current_updater_choice must print y once enabled'
printf 'UPDATER_ENABLED=\n' > "${ENV_PROD_FILE}"
[ "$(current_updater_choice)" = n ] || fail 'current_updater_choice must print n on an empty value'

# --- 4. a re-ask with return keeps the stored decision ----------------------
fresh_install_answer_with 'n' y
printf '\n' > "${queue}"
configure_updater "$(current_updater_choice)" > /dev/null 2>&1
prod_uses_updater || fail 'pressing return on a re-ask must keep the updater on'

# --- 5. headless applies the default it was given ---------------------------
can_prompt() { return 1; }
printf 'UPDATER_ENABLED=\n' > "${ENV_PROD_FILE}"
configure_updater 'y' > /dev/null 2>&1
prod_uses_updater || fail 'a headless default of yes must enable the updater'
configure_updater 'n' > /dev/null 2>&1
assert_env UPDATER_ENABLED ''

# --- 5b. a failed socket-group lookup leaves the updater off and returns 0 --
# shellcheck disable=SC2329 # invoked by use_updater
docker_socket_gid() { return 1; }
printf 'UPDATER_ENABLED=\n' > "${ENV_PROD_FILE}"
configure_updater 'y' > /dev/null 2>&1 || fail 'a failed gid lookup must not fail configure_updater'
assert_env UPDATER_ENABLED ''
assert_env UPDATER_SOCKET_GID ''
docker_socket_gid() { printf '991'; }

# --- 5c. the first update asks once -----------------------------------------
can_prompt() { return 0; }
printf 'DATABASE_URL=\n' > "${ENV_PROD_FILE}"
printf 'y\n' > "${queue}"
offer_updater_if_undecided > /dev/null 2>&1
assert_env UPDATER_ENABLED 1

can_prompt() { return 1; }
printf 'DATABASE_URL=\n' > "${ENV_PROD_FILE}"
hint=$(offer_updater_if_undecided 2>&1)
case "${hint}" in
  *'prod-configure.sh'*) ;;
  *) fail 'a headless undecided update must print the hint' ;;
esac
if updater_choice_recorded; then
  fail 'a headless undecided update must record nothing'
fi

can_prompt() { return 0; }
printf 'UPDATER_ENABLED=\n' > "${ENV_PROD_FILE}"
printf 'y\n' > "${queue}"
offer_updater_if_undecided > /dev/null 2>&1
assert_env UPDATER_ENABLED ''

offer_line=$(grep -n '^offer_updater_if_undecided' "${_dir}/../prod-start.sh" | head -n 1 | cut -d: -f1)
state_line=$(grep -n '^ensure_update_state_directory' "${_dir}/../prod-start.sh" | head -n 1 | cut -d: -f1)
[ -n "${offer_line}" ] && [ -n "${state_line}" ] && [ "${offer_line}" -lt "${state_line}" ] \
  || fail 'prod-start.sh must offer the updater before ensure_update_state_directory'

# --- 6. install.sh asks with 'n' as the default -----------------------------
grep -q "configure_updater 'n'" "${_dir}/../install.sh" \
  || fail "install.sh must pass 'n' as the updater default"
grep -q 'use_updater || true' "${_dir}/../install.sh" \
  || fail "install.sh's quick branch must apply use_updater"

# --- 7. the quick install enables the updater, or records no on a failed lookup
printf 'UPDATER_ENABLED=\n' > "${ENV_PROD_FILE}"
use_updater > /dev/null 2>&1
assert_env UPDATER_ENABLED 1
assert_env UPDATER_SOCKET_GID 991

# shellcheck disable=SC2329 # invoked by use_updater
docker_socket_gid() { return 1; }
printf 'UPDATER_ENABLED=\n' > "${ENV_PROD_FILE}"
use_updater > /dev/null 2>&1 || true
assert_env UPDATER_ENABLED ''

# --- 8. the state directory: a world-writable inbox, an outbox only the host user writes
REPO_ROOT="${work}/install"
mkdir -p "${REPO_ROOT}"
ensure_update_state_directory
mode() { stat -c %a "$1" 2>/dev/null || stat -f '%Mp%Lp' "$1" | sed 's/^0//'; }
[ "$(mode "${REPO_ROOT}/.update-state")" = 755 ] || fail 'the state directory is 0755'
[ "$(mode "${REPO_ROOT}/.update-state/inbox")" = 1777 ] || fail 'the inbox is world-writable and sticky'
[ "$(mode "${REPO_ROOT}/.update-state/outbox")" = 755 ] || fail 'only the host user writes the outbox'

if [ "$(id -u)" -ne 0 ]; then
  rm -rf "${REPO_ROOT}/.update-state"
  mkdir -m 0555 "${REPO_ROOT}/.update-state"
  warnings=$(ensure_update_state_directory 2>&1) || fail 'a state directory owned by someone else never aborts the start'
  chmod 0755 "${REPO_ROOT}/.update-state"
  # shellcheck disable=SC2016 # the fix is printed for the operator to run, unexpanded
  case "${warnings}" in
    *'sudo chown -R "$(id -u):$(id -g)" .update-state'*) ;;
    *) fail "a state directory owned by someone else names the chown fix, got: ${warnings}" ;;
  esac
fi

printf 'ok: configure_updater\n'
