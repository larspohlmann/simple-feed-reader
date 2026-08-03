#!/usr/bin/env bash
set -euo pipefail

# Unit test for the three installer questions in lib.sh -- how users reach the
# instance, the hostname, and the port -- and for what each answer writes into
# .env.prod. Those values become the links in account mail, the OAuth redirect
# base and the published container ports, so their exact shape is a contract
# rather than an implementation detail (issue #252).
#
# The real prompts read /dev/tty. The test replaces them with a canned answer
# queue, where an empty answer means "press return", so it takes the default
# the question offered. That default is the point of most cases here:
# prod-configure.sh re-asks these questions on an instance that is already
# configured, and pressing return three times must change nothing.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

# --- stubs ------------------------------------------------------------------
# Each question runs inside $( ), so its stub runs in a subshell and cannot
# advance a shell variable. The queue therefore lives in a file: one answer per
# line, consumed from the top. An empty line means "press return", so the
# question's own default applies.
queue="${work}/answers"

queue_answers() {
  printf '%s\n' "$@" > "${queue}"
}

prompt_with_default() {
  local default=$2 answer=''
  if [ -s "${queue}" ]; then
    answer=$(head -n 1 "${queue}")
    tail -n +2 "${queue}" > "${queue}.rest"
    mv "${queue}.rest" "${queue}"
  fi
  printf '%s' "${answer:-${default}}"
}

# Decline every offer, so the TLS branch takes its "tell the operator where to
# put the certificate" path instead of running mkcert.
prompt_confirm() { return 1; }

# Whether a port is free, and whether a certificate is already installed, are
# properties of the machine the test runs on, not of the function under test.
check_ports_free() { return 0; }
prod_certs_present() { return 1; }

# --- helpers ----------------------------------------------------------------
# A minimal .env.prod holding only the keys these questions write.
seed_env() {
  local public_url=$1 http_port=$2 tls_port=$3 bind=$4
  cat > "${ENV_PROD_FILE}" <<SEED
PUBLIC_URL=${public_url}
WEB_HTTP_PORT=${http_port}
WEB_TLS_PORT=${tls_port}
WEB_BIND_ADDRESS=${bind}
WEB_MODE=auto
SEED
}

# A file as a fresh install has it, then the questions answered in order. What
# the operator is told is part of the behaviour, so it is kept, not discarded.
told="${work}/told"

configure_fresh() {
  seed_env 'http://localhost' 80 443 0.0.0.0
  queue_answers "$@"
  configure_public_url > "${told}" 2>&1
}

assert_env() {
  local key=$1 want=$2 got
  got=$(env_prod_get "${key}")
  [ "${got}" = "${want}" ] || fail "${key}: want '${want}', got '${got}'"
}

assert_told() {
  grep -q -- "$1" "${told}" || fail "the operator was not told about '$1'"
}

# --- 1. plain HTTP, the direct case -----------------------------------------
# The scheme's own port never appears in PUBLIC_URL: a registered OAuth
# redirect URI is compared as an exact string, and no provider accepts the
# ":80" spelling of the same origin.
configure_fresh 1 localhost 80
assert_env PUBLIC_URL 'http://localhost'
assert_env WEB_HTTP_PORT 80

configure_fresh 1 reader.example.org 8080
assert_env PUBLIC_URL 'http://reader.example.org:8080'
assert_env WEB_HTTP_PORT 8080

# --- 2. HTTPS, this stack serves the certificate -----------------------------
configure_fresh 2 reader.example.org 443
assert_env PUBLIC_URL 'https://reader.example.org'
assert_env WEB_TLS_PORT 443
assert_env WEB_HTTP_PORT 80
# The web container reads its mode off these files, so an HTTPS PUBLIC_URL over
# an empty docker/certs-prod/ serves plain HTTP. Choosing 2 must never leave
# that unsaid -- it is the whole reason the question exists.
assert_told 'docker/certs-prod/fullchain.pem'
assert_told 'serves plain HTTP'

configure_fresh 2 reader.example.org 8443
assert_env PUBLIC_URL 'https://reader.example.org:8443'
assert_env WEB_TLS_PORT 8443

# --- 3. HTTPS behind a reverse proxy ----------------------------------------
# The port answered here is the one the proxy connects to, so it must not
# reach PUBLIC_URL -- users type the proxy's port. WEB_TLS_PORT has to move
# off 443 as well, because docker-compose.prod.yml publishes both ports in
# every mode and 443 belongs to the proxy.
configure_fresh 3 reader.example.org 8080
assert_env PUBLIC_URL 'https://reader.example.org'
assert_env WEB_HTTP_PORT 8080
assert_env WEB_TLS_PORT 8443
assert_env WEB_BIND_ADDRESS '127.0.0.1'
# Choosing 3 is only half an instruction: nothing works until a proxy points at
# the loopback port, so the answer carries the proxy configuration with it.
assert_told 'reverse_proxy 127.0.0.1:8080'

# --- 4. a hostname pasted as a URL ------------------------------------------
# The old single question trained everyone to paste a whole URL. Reduce it to
# the host instead of rejecting it; the scheme belongs to question 1 and the
# port to question 3.
configure_fresh 1 'http://reader.example.org/reader' 80
assert_env PUBLIC_URL 'http://reader.example.org'

configure_fresh 2 'https://reader.example.org:9000' 443
assert_env PUBLIC_URL 'https://reader.example.org'

# --- 5. an unusable hostname is asked again, never fatal ---------------------
# The old flow called die() here, after the clone and after generating every
# secret, leaving the operator to delete the directory and start over.
configure_fresh 1 'http://' localhost 80
assert_env PUBLIC_URL 'http://localhost'

# --- 6. a busy port is asked again ------------------------------------------
# One host address publishes port 80 once. On a machine that already runs
# other web apps that is the normal outcome, so the question comes back.
# The counter lives in a file for the same reason the answer queue does.
probe_calls="${work}/probe-calls"
printf '0\n' > "${probe_calls}"
check_ports_free() {
  local calls
  calls=$(( $(cat "${probe_calls}") + 1 ))
  printf '%s\n' "${calls}" > "${probe_calls}"
  [ "${calls}" -gt 1 ]
}
configure_fresh 1 localhost 80 8080
assert_env PUBLIC_URL 'http://localhost:8080'
assert_env WEB_HTTP_PORT 8080
[ "$(cat "${probe_calls}")" = 2 ] || fail 'the busy port should have been probed twice'
check_ports_free() { return 0; }

# --- 7. re-running keeps every answer ---------------------------------------
# prod-configure.sh runs this against a configured instance. Three returns
# must be a no-op, which means every default is read back out of .env.prod --
# including the topology, which is not a stored value but is implied by the
# scheme and the bind address.
seed_env 'https://reader.example.org' 8080 8443 127.0.0.1
queue_answers '' '' ''
configure_public_url > "${told}" 2>&1
assert_env PUBLIC_URL 'https://reader.example.org'
assert_env WEB_HTTP_PORT 8080
assert_env WEB_TLS_PORT 8443
assert_env WEB_BIND_ADDRESS '127.0.0.1'

seed_env 'https://reader.example.org:8443' 80 8443 0.0.0.0
queue_answers '' '' ''
configure_public_url > "${told}" 2>&1
assert_env PUBLIC_URL 'https://reader.example.org:8443'
assert_env WEB_TLS_PORT 8443
assert_env WEB_BIND_ADDRESS '0.0.0.0'

printf 'ok: configure_public_url\n'
