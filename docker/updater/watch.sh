#!/usr/bin/env bash
set -euo pipefail

# The updater's resident half (#1948): a heartbeat the app reads as "the
# updater is here", and one update run per new request.

# shellcheck source=docker/updater/state.sh
source "${SFR_UPDATER_LIB:-/usr/local/lib/sfr-updater/state.sh}"
: "${SFR_REPO_ROOT:?set SFR_REPO_ROOT in .env.prod (./scripts/prod-configure.sh)}"
RUN_CONTAINER=sfr-update-run
POLL_SECONDS=${SFR_UPDATER_POLL_SECONDS:-30}

run_in_progress() {
  [ -n "$(docker ps -q --filter "name=^${RUN_CONTAINER}$")" ]
}

# Paths after -v are host paths: the daemon resolves them, not this container.
start_run() {
  local request_id=$1
  : > "${STATE_DIR}/run.log"
  write_status "${request_id}" running "$(now_iso)" '' ''
  docker run -d --rm --name "${RUN_CONTAINER}" \
    --user "$(id -u):$(id -g)" --group-add "${UPDATER_SOCKET_GID}" \
    --network "${SFR_RUN_NETWORK}" \
    -e SFR_REPO_ROOT -e HOME=/tmp -e SFR_PROD_INTERNAL_HOST=web -e SFR_SKIP_DEV_STACK=1 \
    -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "${SFR_REPO_ROOT}:${SFR_REPO_ROOT}" \
    -v "${SFR_REPO_ROOT}/.update-state:/state" \
    --entrypoint sfr-updater-run "${UPDATER_IMAGE}" "${request_id}" >/dev/null
}

tick() {
  date -u +%s | write_atomically "${STATE_DIR}/heartbeat"

  local status_file="${STATE_DIR}/status.json"
  if [ "$(json_field "${status_file}" phase)" = running ] && ! run_in_progress; then
    write_status "$(json_field "${status_file}" requestId)" failed \
      "$(json_field "${status_file}" startedAt)" "$(now_iso)" ''
  fi

  local request_id
  request_id=$(json_field "${STATE_DIR}/request.json" id)
  if [ -z "${request_id}" ] || [ "${request_id}" = "$(json_field "${status_file}" requestId)" ]; then
    return 0
  fi
  if run_in_progress; then
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
