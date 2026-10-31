#!/usr/bin/env bash
set -euo pipefail

# One update run (#1948), in its own container outside the compose project so
# the stack rebuild update.sh triggers cannot stop it.

# shellcheck source=docker/updater/state.sh
source "${SFR_UPDATER_LIB:-/usr/local/lib/sfr-updater/state.sh}"
request_id=$1
started_at=$(json_field "${STATE_DIR}/status.json" startedAt)

exit_code=0
"${SFR_REPO_ROOT}/scripts/update.sh" >> "${STATE_DIR}/run.log" 2>&1 || exit_code=$?

phase=succeeded
if [ "${exit_code}" -ne 0 ]; then
  phase=failed
fi
write_status "${request_id}" "${phase}" "${started_at}" "$(now_iso)" "${exit_code}"
