#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's search engine question in lib.sh (#432), and
# for what the answer makes the rest of the scripts do.
#
# Three things have to hold. A FRESH install must default to yes on return,
# because full-content search over article bodies is materially better than
# the database's title/summary LIKE fallback, and the installer's own
# docstring promises it. A RE-ASK (scripts/prod-configure.sh, on an install
# that already answered this question once) must default to whatever is
# already configured, so pressing return through an unrelated question (a new
# public URL, a new mail transport) can never reverse a decision the operator
# already made -- "no" has to be as durable an answer as "yes". And the
# answer has to reach docker: the meilisearch service sits behind a compose
# profile alongside the mysql one, so the profile list has to combine both
# correctly and in the right order -- a wrong list either starts an engine
# container nobody talks to or leaves a configured install without it.
#
# configure_search_engine takes its default as a parameter for exactly this
# reason: install.sh always passes the literal 'y' (a fresh .env.prod has no
# decision on file to read back), prod-configure.sh always passes
# current_search_engine_choice (whatever MEILISEARCH_URL already says).
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

# Simulates install.sh: a fresh .env.prod, always defaulting to 'y'.
fresh_install_answer_with() {
  printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
  printf '%s\n' "$@" > "${queue}"
  configure_search_engine 'y' > /dev/null 2>&1
}

# Simulates prod-configure.sh: seeds the file with whatever the instance is
# already configured to run, then defaults from that -- exactly what
# current_search_engine_choice does at the real call site.
reask_with() {
  local seed=$1
  shift
  printf 'DATABASE_URL=\n%s\n' "${seed}" > "${ENV_PROD_FILE}"
  printf '%s\n' "$@" > "${queue}"
  configure_search_engine "$(current_search_engine_choice)" > /dev/null 2>&1
}

# --- 1. fresh install: pressing return enables the engine and generates a key
fresh_install_answer_with ''
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
[ -n "$(env_prod_get MEILISEARCH_KEY)" ] || fail 'the default answer must generate MEILISEARCH_KEY'
prod_uses_search_engine || fail 'the default answer must enable the search engine'
# The fixture leaves DATABASE_URL empty, i.e. the bundled MySQL -- so both
# profiles are on here; section 4 below isolates each combination.
assert_profiles 'mysql,meilisearch'

# --- 2. fresh install: answering no leaves the URL empty, generates nothing -
fresh_install_answer_with n
assert_env MEILISEARCH_URL ''
assert_env MEILISEARCH_KEY ''
if prod_uses_search_engine; then
  fail 'answering no must not enable the search engine'
fi
assert_profiles 'mysql'

# --- 3. re-ask: pressing return never reverses the stored decision ----------
# This is the regression the default-as-parameter fix exists for: a naive
# `default='y'` on every ask would silently turn the engine back on for an
# operator who declined it and is now re-running prod-configure.sh for
# something unrelated.

# 3a. Already ON: pressing return keeps it on, and does not rotate the key.
reask_with 'MEILISEARCH_URL=http://meilisearch:7700
MEILISEARCH_KEY=already-on-key' ''
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
assert_env MEILISEARCH_KEY 'already-on-key'

# 3b. Already OFF: pressing return keeps it off. THE case that matters -- a
# hardcoded 'y' default would flip this to enabled.
reask_with 'MEILISEARCH_URL=
MEILISEARCH_KEY=' ''
assert_env MEILISEARCH_URL ''
if prod_uses_search_engine; then
  fail 're-asking with return must not enable an engine the operator declined'
fi

# --- 4. the profile list combines with the database choice, in order --------
printf 'DATABASE_URL=\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=already-set\n' > "${ENV_PROD_FILE}"
assert_profiles 'mysql,meilisearch'

printf 'DATABASE_URL=sqlite:///%%kernel.project_dir%%/var/data.db\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=already-set\n' > "${ENV_PROD_FILE}"
assert_profiles 'meilisearch'

# --- 5. an existing key is never regenerated ---------------------------------
# A re-run that enables an already-configured engine must not rotate the key
# -- Meilisearch would reject the app's own requests until every consumer of
# the old value was updated.
printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=existing-key\n' > "${ENV_PROD_FILE}"
printf '\n' > "${queue}"
configure_search_engine 'y' > /dev/null 2>&1
assert_env MEILISEARCH_KEY 'existing-key'
assert_env MEILISEARCH_URL 'http://meilisearch:7700'

# --- 6. an unreadable terminal changes nothing -------------------------------
# The piped, question-less install stops before starting anyway; it must not
# invent a search-engine choice on the way.
can_prompt() { return 1; }
printf 'DATABASE_URL=\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=existing-key\n' > "${ENV_PROD_FILE}"
configure_search_engine 'y' > /dev/null 2>&1
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
assert_env MEILISEARCH_KEY 'existing-key'

printf 'ok: configure_search_engine\n'
