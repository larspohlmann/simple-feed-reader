#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's database question in lib.sh (#277), and for
# what the answer makes the rest of the scripts do.
#
# Two things have to hold. Pressing return must still mean MySQL, because that
# is what every install ran on before the question existed and an .env.prod
# written by an older installer must keep its database. And the answer has to
# reach docker: the mysql service sits behind a compose profile, so an install
# that says SQLite but still enables the profile starts a database container it
# never talks to, while the opposite leaves a MySQL install with no database at
# all.
#
# The real prompts read /dev/tty. They are replaced here with a canned answer
# queue, where an empty answer means "press return".

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

# --- stubs ------------------------------------------------------------------
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

assert_env() {
  local key=$1 want=$2 got
  got=$(env_prod_get "${key}")
  [ "${got}" = "${want}" ] || fail "${key}: want '${want}', got '${got}'"
}

assert_profiles() {
  local want=$1 got
  got=$(prod_compose_profiles)
  [ "${got}" = "${want}" ] || fail "compose profiles: want '${want}', got '${got}'"
}

answer_with() {
  printf '%s\n' "$@" > "${queue}"
  printf 'DATABASE_URL=\n' > "${ENV_PROD_FILE}"
  configure_database > /dev/null 2>&1
}

# --- 1. pressing return keeps MySQL -----------------------------------------
# An empty DATABASE_URL is what docker-compose.prod.yml falls back to, so this
# is also the value every .env.prod written before this question holds.
answer_with ''
assert_env DATABASE_URL ''
assert_profiles mysql
prod_uses_bundled_mysql || fail 'the default answer must run the bundled MySQL'

# --- 2. answering SQLite writes the file DSN and drops the container ---------
answer_with 2
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
assert_profiles ''
if prod_uses_bundled_mysql; then
  fail 'a SQLite install must not start the bundled MySQL'
fi

# --- 3. answering MySQL explicitly is not a no-op ----------------------------
# A re-run against a file that already says SQLite has to clear it, or the
# answer silently does nothing.
printf 'DATABASE_URL=sqlite:///%%kernel.project_dir%%/var/data.db\n' > "${ENV_PROD_FILE}"
printf '1\n' > "${queue}"
configure_database > /dev/null 2>&1
assert_env DATABASE_URL ''
assert_profiles mysql

# --- 4. an unreadable terminal changes nothing -------------------------------
# The piped, question-less install stops before starting anyway; it must not
# invent a database choice on the way.
can_prompt() { return 1; }
printf 'DATABASE_URL=sqlite:///%%kernel.project_dir%%/var/data.db\n' > "${ENV_PROD_FILE}"
configure_database > /dev/null 2>&1
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'

printf 'ok: configure_database\n'
