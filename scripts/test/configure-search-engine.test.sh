#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's search engine question in lib.sh (#432), and
# for what the answer makes the rest of the scripts do.
#
# Three things have to hold. A FRESH install must default to NO on return:
# since #453 the package question decides the search engine, its default is S
# (a personal instance, no engine container), and this question -- now asked
# for package C only -- has to land in the same place. Full-content search over
# article bodies is still materially better than the database's title/summary
# LIKE fallback; that is the argument for choosing package L, not for adding a
# container to an install whose operator has not said how much memory the
# machine has. A RE-ASK (scripts/prod-configure.sh, on an install
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
# reason: install.sh passes a literal (a fresh .env.prod has no decision on
# file to read back), prod-configure.sh always passes
# current_search_engine_choice (whatever MEILISEARCH_URL already says). Which
# literal install.sh passes is asserted here as well -- the two defaults it
# selects between have to agree, and #453 flipped both at once.
#
# A fourth thing has to hold since the code-review fix for issue #432: a
# TERMINAL-LESS run must not silently no-op. The documented two-step flow
# (write .env.prod, stop for the mail question, finish later in a real
# terminal) has no terminal for this very question either -- so without a
# terminal the function must actively apply the default it was given, the same
# default a terminal would have offered. That keeps the re-ask guarantee too:
# applying a default can never invent a decision, so a headless re-ask still
# cannot turn on an engine the operator declined.
#
# A fifth and separate thing, unrelated to prompting: prod_uses_search_engine
# must agree with the backend's SearchEngineCapability::isConfigured() about
# what "configured" means, including its trim() -- a whitespace-only
# MEILISEARCH_URL (only reachable by hand-editing .env.prod) must not read as
# configured here while the backend refuses to talk to it.
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

# Simulates install.sh: a fresh .env.prod, and the default it hands over.
fresh_install_answer_with() {
  local default=$1
  shift
  printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
  printf '%s\n' "$@" > "${queue}"
  configure_search_engine "${default}" > /dev/null 2>&1
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

# --- 1. the default is applied on return, whichever one the caller passes ----
# 'y' first: this is what a re-ask of an install that runs the engine offers,
# and what install.sh passed until #453 -- the branch has to keep working, or
# prod-configure.sh can no longer keep a decision.
fresh_install_answer_with 'y' ''
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
[ -n "$(env_prod_get MEILISEARCH_KEY)" ] || fail 'the default answer must generate MEILISEARCH_KEY'
prod_uses_search_engine || fail 'a default of yes must enable the search engine'
# The fixture leaves DATABASE_URL empty, i.e. the bundled MySQL -- so both
# profiles are on here; section 4 below isolates each combination.
assert_profiles 'mysql,meilisearch'

# 'n' second: what install.sh passes since #453, so pressing return through the
# C path lands on the same stack the default package S installs.
fresh_install_answer_with 'n' ''
assert_env MEILISEARCH_URL ''
assert_env MEILISEARCH_KEY ''
if prod_uses_search_engine; then
  fail 'a default of no must not enable the search engine'
fi
assert_profiles 'mysql'

# --- 2. an explicit answer beats the default in both directions --------------
fresh_install_answer_with 'y' n
assert_env MEILISEARCH_URL ''
assert_env MEILISEARCH_KEY ''
if prod_uses_search_engine; then
  fail 'answering no must not enable the search engine'
fi
assert_profiles 'mysql'

fresh_install_answer_with 'n' y
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
prod_uses_search_engine || fail 'answering yes must enable the search engine'
assert_profiles 'mysql,meilisearch'

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

# --- 6. an unreadable terminal REAPPLIES the current decision, changing ----
# nothing when the caller's default already matches it. Doing nothing and
# applying the default are not the same outcome here -- an empty
# MEILISEARCH_URL reads as a decline -- so the headless path must actively
# apply what it was given. This case only proves that applying an unchanged
# decision is itself a no-op.
can_prompt() { return 1; }
printf 'DATABASE_URL=\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=existing-key\n' > "${ENV_PROD_FILE}"
configure_search_engine 'y' > /dev/null 2>&1
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
assert_env MEILISEARCH_KEY 'existing-key'

# --- 7. a headless run applies the default it was given, either way ---------
# The documented two-step flow: a piped install writes .env.prod, then stops
# because mail needs a human. An early return left MEILISEARCH_URL empty here
# -- indistinguishable from a decline -- so the LATER prod-configure.sh run (in
# a real terminal) offered the question defaulted to no, whatever the installer
# had promised. Both defaults must therefore reach the file: 'y' configures it,
# 'n' writes the decline rather than leaving the question open.
can_prompt() { return 1; }
printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
configure_search_engine 'y' > /dev/null 2>&1
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
[ -n "$(env_prod_get MEILISEARCH_KEY)" ] || fail 'a headless default of yes must generate a key'
prod_uses_search_engine || fail 'a headless default of yes must enable the engine'

can_prompt() { return 1; }
printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
configure_search_engine 'n' > /dev/null 2>&1
assert_env MEILISEARCH_URL ''
assert_env MEILISEARCH_KEY ''

# --- 7b. and install.sh is the caller that passes 'n' -----------------------
# The default this question offers and the default package the installer offers
# have to agree: pressing return through the whole installer lands on SQLite
# with no search engine, whichever path the operator takes to get there. A flip
# back to 'y' here would make the C path install a container the S path never
# starts, and nothing else would notice.
grep -q "configure_search_engine 'n'" "${_dir}/../install.sh" \
  || fail "install.sh must pass 'n' as the search-engine default"
if grep -q "configure_search_engine 'y'" "${_dir}/../install.sh"; then
  fail "install.sh must not pass 'y' as the search-engine default any more"
fi

# --- 8. headless re-configure still cannot flip a stored "no" to "yes" ------
# prod-configure.sh itself refuses to run without a terminal (it dies), so
# this cannot happen through the real CLI -- but the property the fix must
# keep is in configure_search_engine's own contract: applying a DEFAULT can
# never re-decide anything, headless or not. Simulate the call the same way
# prod-configure.sh makes it (default = current_search_engine_choice) against
# an instance that already said no, with no terminal to ask on.
can_prompt() { return 1; }
printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
configure_search_engine "$(current_search_engine_choice)" > /dev/null 2>&1
assert_env MEILISEARCH_URL ''
if prod_uses_search_engine; then
  fail 'a headless re-ask must not turn on an engine the operator declined'
fi

# --- 9. whitespace-only MEILISEARCH_URL does not count as configured --------
# The backend trims before checking (SearchEngineCapability::isConfigured()),
# so the shell has to agree -- otherwise a hand-edited .env.prod holding only
# whitespace starts a container the app will never talk to.
printf 'DATABASE_URL=\nMEILISEARCH_URL=   \nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
if prod_uses_search_engine; then
  fail 'a whitespace-only MEILISEARCH_URL must not count as configured'
fi
assert_profiles 'mysql'

printf 'ok: configure_search_engine\n'
