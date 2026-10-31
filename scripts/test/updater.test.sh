#!/usr/bin/env bash
set -euo pipefail

# Unit test for the #1948 updater: the watcher's heartbeat, one run per request,
# and a run that vanished (host reboot) being marked failed. docker is stubbed.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
updater="${_dir}/../../docker/updater"
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
mkdir -p "${work}/bin" "${work}/state" "${work}/repo/scripts"
cat > "${work}/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${STUB_LOG}"
if [ "$1" = ps ]; then cat "${STUB_RUNNING}" 2>/dev/null || true; fi
EOF
chmod +x "${work}/bin/docker"

export PATH="${work}/bin:${PATH}" STUB_LOG="${work}/docker.log" STUB_RUNNING="${work}/running"
export SFR_STATE_DIR="${work}/state" SFR_REPO_ROOT="${work}/repo" SFR_UPDATER_LIB="${updater}/state.sh"
export UPDATER_IMAGE=updater:test UPDATER_SOCKET_GID=0 SFR_RUN_NETWORK=net

watch_once() { bash "${updater}/watch.sh" --once; }

watch_once
[ -s "${work}/state/heartbeat" ] || fail 'a tick writes the heartbeat'
if grep -q '^run' "${STUB_LOG}" 2>/dev/null; then fail 'no request, no run'; fi

printf '{"id":"abc","requestedBy":"a@b","requestedAt":"2026-10-31T10:00:00Z"}' > "${work}/state/request.json"
watch_once
grep -q '^run .*--name sfr-update-run' "${STUB_LOG}" || fail 'a new request starts the run'
[ "$(jq -r .phase "${work}/state/status.json")" = running ] || fail 'the watcher marks the request running'
[ "$(jq -r .requestId "${work}/state/status.json")" = abc ] || fail 'status names the request'

printf 'c0ffee\n' > "${STUB_RUNNING}"
: > "${STUB_LOG}"
watch_once
if grep -q '^run' "${STUB_LOG}"; then fail 'a handled request never starts a second run'; fi

: > "${STUB_RUNNING}"
watch_once
[ "$(jq -r .phase "${work}/state/status.json")" = failed ] || fail 'a running status with no run container is a failed run'

printf '#!/usr/bin/env bash\necho updating\nexit 3\n' > "${work}/repo/scripts/update.sh"
chmod +x "${work}/repo/scripts/update.sh"
bash "${updater}/run.sh" abc
[ "$(jq -r .phase "${work}/state/status.json")" = failed ] || fail 'a non-zero update.sh fails the run'
[ "$(jq -r .exitCode "${work}/state/status.json")" = 3 ] || fail 'the run records the exit code'
grep -q updating "${work}/state/run.log" || fail 'the run logs update.sh output'
printf 'ok\n'
