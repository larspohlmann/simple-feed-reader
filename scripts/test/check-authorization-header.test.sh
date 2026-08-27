#!/usr/bin/env bash
set -euo pipefail

# Regression test for issue #573. The deploy probe must distinguish a missing
# Authorization rewrite from a release error, and a cold-cache 500 must get a
# short grace period before it makes a healthy deploy red.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
probe="${_dir}/../check-authorization-header.sh"
deploy_workflow="${_dir}/../../.github/workflows/deploy-strato.yml"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
fake_bin="${work}/bin"
mkdir "${fake_bin}"

cat > "${fake_bin}/curl" <<'FAKE_CURL'
#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' "$@" >> "${FAKE_CURL_ARGUMENTS}"

output_file=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o)
      output_file=$2
      shift 2
      ;;
    *)
      shift
      ;;
  esac
done

attempt=1
if [ -f "${FAKE_CURL_ATTEMPTS}" ]; then
  attempt=$(($(cat "${FAKE_CURL_ATTEMPTS}") + 1))
fi
printf '%s\n' "${attempt}" > "${FAKE_CURL_ATTEMPTS}"

response=$(sed -n "${attempt}p" "${FAKE_CURL_RESPONSES}")
IFS='|' read -r status_code body exit_status <<< "${response}"
printf '%s' "${body}" > "${output_file}"
printf '%s' "${status_code}"
exit "${exit_status}"
FAKE_CURL

cat > "${fake_bin}/sleep" <<'FAKE_SLEEP'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$1" >> "${FAKE_SLEEP_CALLS}"
FAKE_SLEEP

chmod +x "${fake_bin}/curl" "${fake_bin}/sleep"

assert_contains() {
  local expected=$1 actual=$2 message=$3
  printf '%s\n' "${actual}" | grep -qF -- "${expected}" || fail "${message}"
}

assert_equals() {
  local expected=$1 actual=$2 message=$3
  [ "${actual}" = "${expected}" ] || fail "${message}: want '${expected}', got '${actual}'"
}

run_probe() {
  local responses=$1
  printf '%s\n' "${responses}" > "${FAKE_CURL_RESPONSES}"
  rm -f "${FAKE_CURL_ATTEMPTS}" "${FAKE_SLEEP_CALLS}"
  rm -f "${FAKE_CURL_ARGUMENTS}"
  probe_status=0
  probe_output=$(PATH="${fake_bin}:${PATH}" "${probe}" 'https://reader.example/api/health' 2>&1) \
    || probe_status=$?
}

export FAKE_CURL_RESPONSES="${work}/responses"
export FAKE_CURL_ATTEMPTS="${work}/attempts"
export FAKE_CURL_ARGUMENTS="${work}/curl-arguments"
export FAKE_SLEEP_CALLS="${work}/sleep-calls"

run_probe '401|invalid token|0'
assert_equals 0 "${probe_status}" '401 must pass'
assert_equals 1 "$(cat "${FAKE_CURL_ATTEMPTS}")" '401 must need one request'
assert_contains 'Authorization header reaches PHP' "${probe_output}" '401 must report success'
assert_contains 'Authorization: Bearer deliberately-invalid' "$(cat "${FAKE_CURL_ARGUMENTS}")" \
  'the probe must send the invalid bearer token'
assert_contains 'https://reader.example/api/health' "$(cat "${FAKE_CURL_ARGUMENTS}")" \
  'the probe must request the supplied health URL'
assert_contains '--max-time' "$(cat "${FAKE_CURL_ARGUMENTS}")" \
  'the probe must set a request timeout'

run_probe $'500|warming one|0\n500|warming two|0\n401|invalid token|0'
assert_equals 0 "${probe_status}" 'a 401 after transient 500 responses must pass'
assert_equals 3 "$(cat "${FAKE_CURL_ATTEMPTS}")" 'transient 500 responses must retry'
assert_equals $'5\n5' "$(cat "${FAKE_SLEEP_CALLS}")" 'each transient 500 must wait five seconds'

run_probe '200|healthy but public|0'
[ "${probe_status}" -ne 0 ] || fail '200 must fail'
assert_contains 'Authorization header is not reaching PHP' "${probe_output}" '200 must explain the rewrite failure'
assert_contains 'healthy but public' "${probe_output}" '200 must print the response body'

run_probe $'500|first failure|0\n500|second failure|0\n500|final failure|0'
[ "${probe_status}" -ne 0 ] || fail 'a persistent 500 must fail'
assert_equals 3 "$(cat "${FAKE_CURL_ATTEMPTS}")" 'a persistent 500 must use all attempts'
assert_contains 'release threw' "${probe_output}" '500 must explain the release failure'
assert_contains 'shared/var/log/prod-*.log' "${probe_output}" '500 must identify the production log'
assert_contains 'final failure' "${probe_output}" '500 must print the final response body'

run_probe '418|short and stout|0'
[ "${probe_status}" -ne 0 ] || fail 'an unexpected status must fail'
assert_contains 'unexpected HTTP 418' "${probe_output}" 'an unexpected status must keep an unknown diagnosis'
assert_contains 'short and stout' "${probe_output}" 'an unexpected status must print the response body'

run_probe '000|connection timed out|28'
[ "${probe_status}" -ne 0 ] || fail 'a transport error must fail'
assert_contains 'curl failed with exit code 28' "${probe_output}" 'a transport error must keep its exit code'
assert_contains 'connection timed out' "${probe_output}" 'a transport error must print the response body'

run_probe $'500||0\n500||0\n500||0'
[ "${probe_status}" -ne 0 ] || fail 'a persistent 500 with no body must fail'
assert_contains '<empty>' "${probe_output}" 'an empty response body must be identified'

smoke_step=$(sed -n \
  '/- name: Smoke-test the live release/,/- name: Prune old releases/p' \
  "${deploy_workflow}")
printf '%s\n' "${smoke_step}" | grep -qE '^[[:space:]]+scripts/check-authorization-header\.sh \\$' \
  || fail 'the deploy workflow must use the tested authorization probe'

printf 'ok: check-authorization-header\n'
