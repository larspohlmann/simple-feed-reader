# shellcheck shell=bash
# The state directory contract shared by watch.sh and run.sh (#1948).
# The files are read across versions (an old run writes what a newer watcher or backend reads): only ever add fields.
STATE_DIR=${SFR_STATE_DIR:-/state}

now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }

json_field() {
  local file=$1 field=$2
  [ -f "${file}" ] || return 0
  jq -r --arg field "${field}" '.[$field] // empty' "${file}"
}

write_atomically() {
  local target=$1
  cat > "${target}.tmp"
  mv -f "${target}.tmp" "${target}"
}

write_status() {
  local request_id=$1 phase=$2 started_at=$3 finished_at=$4 exit_code=$5
  jq -n --arg requestId "${request_id}" --arg phase "${phase}" --arg startedAt "${started_at}" \
    --arg finishedAt "${finished_at}" --arg exitCode "${exit_code}" \
    '{requestId: $requestId, phase: $phase,
      startedAt: (if $startedAt == "" then null else $startedAt end),
      finishedAt: (if $finishedAt == "" then null else $finishedAt end),
      exitCode: (if $exitCode == "" then null else ($exitCode | tonumber) end)}' \
    | write_atomically "${STATE_DIR}/status.json"
}
