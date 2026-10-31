#!/usr/bin/env bash
set -euo pipefail

# Unit test for the #1948 updater: the watcher's heartbeat, one run per request,
# a run that vanished (host reboot) being marked failed, and a busy daemon or a
# broken request.json never failing a run or the watcher. docker is stubbed.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
updater="${_dir}/../../docker/updater"
fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
mkdir -p "${work}/bin" "${work}/state" "${work}/repo/scripts"
cat > "${work}/bin/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${STUB_LOG}"
case "$1" in
  ps)
    if [ -e "${STUB_PS_FAILS}" ]; then exit 1; fi
    if [ "$2" = -aq ]; then cat "${STUB_STOPPED}" 2>/dev/null || true; else cat "${STUB_RUNNING}" 2>/dev/null || true; fi
    ;;
  run)
    if [ -e "${STUB_RUN_FAILS}" ]; then exit 1; fi
    ;;
esac
EOF
chmod +x "${work}/bin/docker"

export PATH="${work}/bin:${PATH}" STUB_LOG="${work}/docker.log" STUB_RUNNING="${work}/running"
export STUB_STOPPED="${work}/stopped" STUB_PS_FAILS="${work}/ps-fails" STUB_RUN_FAILS="${work}/run-fails"
export SFR_STATE_DIR="${work}/state" SFR_REPO_ROOT="${work}/repo" SFR_UPDATER_LIB="${updater}/state.sh"
export UPDATER_IMAGE=updater:test UPDATER_SOCKET_GID=0 SFR_RUN_NETWORK=net

status_field() { jq -r ".$1" "${work}/state/status.json"; }
request() {
  printf '{"id":"%s","requestedBy":"a@b","requestedAt":"2026-10-31T10:00:00Z"}' "$1" > "${work}/state/request.json"
}
watch_once() { bash "${updater}/watch.sh" --once 2> "${work}/stderr" || fail 'a tick never exits non-zero'; }
no_run_started() { ! grep -q '^run' "${STUB_LOG}" 2>/dev/null; }

watch_once
[ -s "${work}/state/heartbeat" ] || fail 'a tick writes the heartbeat'
no_run_started || fail 'no request, no run'

request abc
watch_once
grep -q '^run .*--name sfr-update-run' "${STUB_LOG}" || fail 'a new request starts the run'
[ "$(status_field phase)" = running ] || fail 'the watcher marks the request running'
[ "$(status_field requestId)" = abc ] || fail 'status names the request'

printf 'c0ffee\n' > "${STUB_RUNNING}"
: > "${STUB_LOG}"
watch_once
no_run_started || fail 'a handled request never starts a second run'

request def
watch_once
no_run_started || fail 'a new request waits while a run is in progress'
[ "$(status_field requestId)" = abc ] || fail 'a waiting request leaves the running status alone'
request abc

: > "${STUB_RUNNING}"
touch "${STUB_PS_FAILS}"
rm "${work}/state/heartbeat"
watch_once
rm "${STUB_PS_FAILS}"
[ -s "${work}/state/heartbeat" ] || fail 'a failing docker ps still writes the heartbeat'
[ "$(status_field phase)" = running ] || fail 'a failing docker ps never fails a running run'
no_run_started || fail 'a failing docker ps starts nothing'
grep -q 'docker ps failed' "${work}/stderr" || fail 'a failing docker ps is reported'

watch_once
[ "$(status_field phase)" = failed ] || fail 'a running status with no run container is a failed run'

request def
printf 'dead\n' > "${STUB_STOPPED}"
: > "${STUB_LOG}"
watch_once
grep -q '^rm -f sfr-update-run' "${STUB_LOG}" || fail 'a stopped run container is removed first'
grep -q 'removing the stopped' "${work}/stderr" || fail 'removing a stopped run container is reported'
grep -q '^run .* def$' "${STUB_LOG}" || fail 'the run starts after the stopped container is gone'
: > "${STUB_STOPPED}"

: > "${STUB_RUNNING}"
request ghi
touch "${STUB_RUN_FAILS}"
watch_once
rm "${STUB_RUN_FAILS}"
[ "$(status_field requestId)" = ghi ] || fail 'a run that cannot start names its request'
[ "$(status_field phase)" = failed ] || fail 'a run that cannot start is a failed request'

printf '{not json' > "${work}/state/request.json"
rm "${work}/state/heartbeat"
watch_once
[ -s "${work}/state/heartbeat" ] || fail 'an unreadable request keeps the heartbeat alive'
grep -q 'request.json is unreadable' "${work}/stderr" || fail 'an unreadable request is reported'

printf '{"requestId":"jkl","phase":"running"}' > "${work}/state/status.json"
watch_once
[ "$(status_field phase)" = failed ] || fail 'a vanished run without a start time is failed'
[ "$(status_field startedAt)" = null ] || fail 'a missing start time is written as null, never ""'

printf '#!/usr/bin/env bash\necho updating\nexit 3\n' > "${work}/repo/scripts/update.sh"
chmod +x "${work}/repo/scripts/update.sh"
bash "${updater}/run.sh" abc
[ "$(status_field phase)" = failed ] || fail 'a non-zero update.sh fails the run'
[ "$(status_field exitCode)" = 3 ] || fail 'the run records the exit code'
grep -q updating "${work}/state/run.log" || fail 'the run logs update.sh output'

printf '#!/usr/bin/env bash\nexit 0\n' > "${work}/repo/scripts/update.sh"
bash "${updater}/run.sh" abc
[ "$(status_field phase)" = succeeded ] || fail 'a zero update.sh succeeds the run'
[ "$(status_field exitCode)" = 0 ] || fail 'a succeeded run records exit code 0'
printf 'ok\n'
