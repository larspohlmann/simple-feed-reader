#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's search engine question in lib.sh (#432), and
# for what the answer makes the rest of the scripts do.
#
# Two things have to hold. Pressing return must enable the engine, because
# full-content search over article bodies is materially better than the
# database's title/summary LIKE fallback, and the installer's own docstring
# promises it. And the answer has to reach docker: the meilisearch service
# sits behind a compose profile alongside the mysql one, so the profile list
# has to combine both correctly and in the right order -- a wrong list either
# starts an engine container nobody talks to or leaves a configured install
# without it.
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
  printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
  configure_search_engine > /dev/null 2>&1
}

# --- 1. pressing return enables the engine and generates a key --------------
answer_with ''
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
[ -n "$(env_prod_get MEILISEARCH_KEY)" ] || fail 'the default answer must generate MEILISEARCH_KEY'
prod_uses_search_engine || fail 'the default answer must enable the search engine'
# answer_with's fixture leaves DATABASE_URL empty, i.e. the bundled MySQL --
# so both profiles are on here; section 3 below isolates each combination.
assert_profiles 'mysql,meilisearch'

# --- 2. answering no leaves the URL empty and generates nothing -------------
answer_with n
assert_env MEILISEARCH_URL ''
assert_env MEILISEARCH_KEY ''
if prod_uses_search_engine; then
  fail 'answering no must not enable the search engine'
fi
# Same fixture, so the mysql profile is still on -- only meilisearch dropped.
assert_profiles 'mysql'

# --- 3. the profile list combines with the database choice, in order --------
printf 'DATABASE_URL=\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=already-set\n' > "${ENV_PROD_FILE}"
assert_profiles 'mysql,meilisearch'

printf 'DATABASE_URL=sqlite:///%%kernel.project_dir%%/var/data.db\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=already-set\n' > "${ENV_PROD_FILE}"
assert_profiles 'meilisearch'

# --- 4. an existing key is never regenerated ---------------------------------
# A re-run that enables an already-configured engine must not rotate the key
# -- Meilisearch would reject the app's own requests until every consumer of
# the old value was updated.
printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=existing-key\n' > "${ENV_PROD_FILE}"
printf '\n' > "${queue}"
configure_search_engine > /dev/null 2>&1
assert_env MEILISEARCH_KEY 'existing-key'
assert_env MEILISEARCH_URL 'http://meilisearch:7700'

# --- 5. an unreadable terminal changes nothing -------------------------------
# The piped, question-less install stops before starting anyway; it must not
# invent a search-engine choice on the way.
can_prompt() { return 1; }
printf 'DATABASE_URL=\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=existing-key\n' > "${ENV_PROD_FILE}"
configure_search_engine > /dev/null 2>&1
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
assert_env MEILISEARCH_KEY 'existing-key'

printf 'ok: configure_search_engine\n'
