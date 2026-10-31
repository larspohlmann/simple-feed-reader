#!/usr/bin/env bash
set -euo pipefail

# The updater's resident half (#1948): a heartbeat the app reads as "the
# updater is here", and one update run per new request.

# shellcheck source=docker/updater/state.sh
source "${SFR_UPDATER_LIB:-/usr/local/lib/sfr-updater/state.sh}"
: "${SFR_REPO_ROOT:?set SFR_REPO_ROOT in .env.prod (./scripts/prod-configure.sh)}"
RUN_CONTAINER=sfr-update-run
POLL_SECONDS=${SFR_UPDATER_POLL_SECONDS:-30}

warn() { printf 'sfr-updater: %s\n' "$1" >&2; }

# A run that died with its daemon (host reboot) leaves the name taken.
remove_stopped_run_container() {
  [ -n "$(docker ps -aq --filter "name=^${RUN_CONTAINER}$")" ] || return 0
  warn "removing the stopped ${RUN_CONTAINER} container a previous run left behind"
  docker rm -f "${RUN_CONTAINER}" >/dev/null
}

# Paths after -v are host paths: the daemon resolves them, not this container.
start_run() {
  local request_id=$1
  : > "${OUTBOX_DIR}/run.log"
  write_status "${request_id}" running "$(now_iso)" '' ''
  remove_stopped_run_container
  docker run -d --rm --name "${RUN_CONTAINER}" \
    --user "$(id -u):$(id -g)" --group-add "${UPDATER_SOCKET_GID}" \
    --network "${SFR_RUN_NETWORK}" \
    -e SFR_REPO_ROOT -e HOME=/tmp -e SFR_PROD_INTERNAL_HOST=web -e SFR_SKIP_DEV_STACK=1 \
    -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "${SFR_REPO_ROOT}:${SFR_REPO_ROOT}" \
    -v "${SFR_REPO_ROOT}/.update-state/inbox:/state/inbox:ro" \
    -v "${SFR_REPO_ROOT}/.update-state/outbox:/state/outbox" \
    --entrypoint sfr-updater-run "${UPDATER_IMAGE}" "${request_id}" >/dev/null
}

fail_vanished_run() {
  local status_file="${OUTBOX_DIR}/status.json"
  [ "$(json_field "${status_file}" phase)" = running ] || return 0
  write_status "$(json_field "${status_file}" requestId)" failed \
    "$(json_field "${status_file}" startedAt)" "$(now_iso)" ''
}

# The id reaches status.json and docker run's argv; the backend only ever writes 16 hex digits.
is_request_id() { [[ $1 =~ ^[0-9a-f]{16}$ ]]; }

tick() {
  date -u +%s | write_atomically "${OUTBOX_DIR}/heartbeat"

  local running_run
  if ! running_run=$(docker ps -q --filter "name=^${RUN_CONTAINER}$"); then
    warn 'docker ps failed; skipping this tick'
    return 0
  fi
  if [ -n "${running_run}" ]; then
    return 0
  fi
  fail_vanished_run

  local request_id
  if ! request_id=$(json_field "${INBOX_DIR}/request.json" id); then
    warn 'request.json is unreadable; treating it as no request'
    return 0
  fi
  if [ -z "${request_id}" ] || [ "${request_id}" = "$(json_field "${OUTBOX_DIR}/status.json" requestId)" ]; then
    return 0
  fi
  if ! is_request_id "${request_id}"; then
    warn 'ignoring request.json: its id is not 16 lowercase hex digits'
    return 0
  fi
  start_run "${request_id}" \
    || write_status "${request_id}" failed "$(now_iso)" "$(now_iso)" ''
}

if [ "${1:-}" = --once ]; then
  tick
  exit 0
fi
while true; do
  tick
  sleep "${POLL_SECONDS}"
done
