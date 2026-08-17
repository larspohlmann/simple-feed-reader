#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's database question in lib.sh (#277), and for
# what the answer makes the rest of the scripts do.
#
# Three things have to hold. Pressing return must mean SQLite: since #453 the
# package question decides the database, its default is S (a personal
# instance), and this question -- now asked for package C only -- has to land
# in the same place, or the two paths through the installer disagree about what
# return means. Without a terminal the same default must be WRITTEN, not
# skipped: an empty DATABASE_URL is what selects MySQL, so a no-op here lands
# on the opposite of the documented default. And the answer has to reach
# docker: the mysql service sits behind a compose profile, so an install that
# says SQLite but still enables the profile starts a database container it
# never talks to, while the opposite leaves a MySQL install with no database at
# all.
#
# The choice NUMBERS are deliberately not the flipped default: 1 is still
# MySQL, 2 is still SQLite. Both lines print directly above the prompt, so
# nobody types a number from memory -- while renumbering would silently turn a
# copy of an older instruction into the other engine.
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

# --- 1. pressing return selects SQLite ---------------------------------------
# The package question's default is S, so this one's has to be SQLite too: an
# operator who answers C and then presses return through the rest must not end
# up with a database container the S path would never have started.
answer_with ''
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
assert_profiles ''
if prod_uses_bundled_mysql; then
  fail 'the default answer must not run the bundled MySQL'
fi

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

# --- 4. an unreadable terminal WRITES the default ---------------------------
# It used to return without writing, which was correct only while the default
# was the do-nothing state. Now the do-nothing state (an empty DATABASE_URL,
# i.e. the bundled MySQL) is the opposite of the default, so the default has to
# be applied explicitly -- the same thing configure_search_engine does with the
# default it is passed, and for the same reason.
can_prompt() { return 1; }
printf 'DATABASE_URL=\n' > "${ENV_PROD_FILE}"
configure_database > /dev/null 2>&1
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
assert_profiles ''

printf 'ok: configure_database\n'
