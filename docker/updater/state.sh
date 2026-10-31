# shellcheck shell=bash
# The state directory contract shared by watch.sh, run.sh and the backend (#1948).
# inbox/ (world-writable, read-only here) holds the app's request.json; outbox/ (the host user's, read-only
# to php) holds heartbeat, status.json and run.log. The files are read across versions (an old run writes what
# a newer watcher or backend reads): only ever add fields, and the status.json phases running|succeeded|failed
# are frozen.
STATE_DIR=${SFR_STATE_DIR:-/state}
# shellcheck disable=SC2034 # read by watch.sh
INBOX_DIR="${STATE_DIR}/inbox"
OUTBOX_DIR="${STATE_DIR}/outbox"

now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

json_field() {
  local file=$1 field=$2
  [ -f "${file}" ] && [ ! -L "${file}" ] || return 0
  timeout 5 head -c 65536 -- "${file}" | jq -r --arg field "${field}" '.[$field] // empty'
}

write_atomically() {
  local target=$1 temporary
  temporary=$(mktemp "${OUTBOX_DIR}/.write.XXXXXX")
  cat > "${temporary}"
  chmod 0644 "${temporary}"
  mv -f "${temporary}" "${target}"
}

write_status() {
  local request_id=$1 phase=$2 started_at=$3 finished_at=$4 exit_code=$5
  jq -n --arg requestId "${request_id}" --arg phase "${phase}" --arg startedAt "${started_at}" \
    --arg finishedAt "${finished_at}" --arg exitCode "${exit_code}" \
    '{requestId: $requestId, phase: $phase,
      startedAt: (if $startedAt == "" then null else $startedAt end),
      finishedAt: (if $finishedAt == "" then null else $finishedAt end),
      exitCode: (if $exitCode == "" then null else ($exitCode | tonumber) end)}' \
    | write_atomically "${OUTBOX_DIR}/status.json"
}
