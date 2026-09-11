#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's Grafana log-dashboard question in lib.sh
# (#983), mirroring scripts/test/configure-search-engine.test.sh's harness
# and the properties it proves for the search-engine question -- Grafana is
# a second, independent opt-in container pair asked the same way.
#
# A FRESH install must default to NO on return: install.sh passes 'n' in
# both the non-quick and the quick (Q) branches, since a dedicated log
# dashboard is not something an install should get without asking. A RE-ASK
# (scripts/prod-configure.sh) must default to whatever is already
# configured, so pressing return through an unrelated question can never
# reverse a decision the operator already made. And the answer has to reach
# docker: loki and grafana sit behind the same 'grafana' compose profile, so
# the profile list has to combine correctly with mysql and meilisearch.
#
# The real prompts read /dev/tty. They are replaced here with a canned
# answer queue, where an empty answer means "press return".

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
  printf 'DATABASE_URL=\nMEILISEARCH_URL=\nGRAFANA_LOKI_PUSH_URL=\nGRAFANA_URL=\nGRAFANA_ADMIN_PASSWORD=\n' > "${ENV_PROD_FILE}"
  printf '%s\n' "$@" > "${queue}"
  configure_grafana "${default}" > /dev/null 2>&1
}

# Simulates prod-configure.sh: seeds the file with whatever the instance is
# already configured to run, then defaults from that -- exactly what
# current_grafana_choice does at the real call site.
reask_with() {
  local seed=$1
  shift
  printf 'DATABASE_URL=\n%s\n' "${seed}" > "${ENV_PROD_FILE}"
  printf '%s\n' "$@" > "${queue}"
  configure_grafana "$(current_grafana_choice)" > /dev/null 2>&1
}

# --- 1. the default is applied on return, whichever one the caller passes ---
fresh_install_answer_with 'y' ''
assert_env GRAFANA_LOKI_PUSH_URL 'http://loki:3100/loki/api/v1/push'
assert_env GRAFANA_URL 'http://localhost:3000'
[ -n "$(env_prod_get GRAFANA_ADMIN_PASSWORD)" ] || fail 'the default answer must generate GRAFANA_ADMIN_PASSWORD'
prod_uses_grafana || fail 'a default of yes must enable grafana'
assert_profiles 'mysql,grafana'

fresh_install_answer_with 'n' ''
assert_env GRAFANA_LOKI_PUSH_URL ''
assert_env GRAFANA_URL ''
if prod_uses_grafana; then
  fail 'a default of no must not enable grafana'
fi
assert_profiles 'mysql'

# --- 2. an explicit answer beats the default in both directions -------------
fresh_install_answer_with 'y' n
assert_env GRAFANA_LOKI_PUSH_URL ''
if prod_uses_grafana; then
  fail 'answering no must not enable grafana'
fi
assert_profiles 'mysql'

fresh_install_answer_with 'n' y
assert_env GRAFANA_LOKI_PUSH_URL 'http://loki:3100/loki/api/v1/push'
prod_uses_grafana || fail 'answering yes must enable grafana'
assert_profiles 'mysql,grafana'

# --- 3. re-ask: pressing return never reverses the stored decision ----------
# 3a. Already ON: pressing return keeps it on, and does not rotate the
# admin password.
reask_with 'GRAFANA_LOKI_PUSH_URL=http://loki:3100/loki/api/v1/push
GRAFANA_URL=http://localhost:3000
GRAFANA_ADMIN_PASSWORD=already-on-password' ''
assert_env GRAFANA_LOKI_PUSH_URL 'http://loki:3100/loki/api/v1/push'
assert_env GRAFANA_ADMIN_PASSWORD 'already-on-password'

# 3b. Already OFF: pressing return keeps it off.
reask_with 'GRAFANA_LOKI_PUSH_URL=
GRAFANA_URL=
GRAFANA_ADMIN_PASSWORD=' ''
assert_env GRAFANA_LOKI_PUSH_URL ''
if prod_uses_grafana; then
  fail 're-asking with return must not enable grafana after it was declined'
fi

# --- 4. the profile list combines with mysql and meilisearch, in order ------
printf 'DATABASE_URL=\nMEILISEARCH_URL=\nGRAFANA_LOKI_PUSH_URL=http://loki:3100/loki/api/v1/push\nGRAFANA_ADMIN_PASSWORD=already-set\n' > "${ENV_PROD_FILE}"
assert_profiles 'mysql,grafana'

printf 'DATABASE_URL=\nMEILISEARCH_URL=http://meilisearch:7700\nMEILISEARCH_KEY=already-set\nGRAFANA_LOKI_PUSH_URL=http://loki:3100/loki/api/v1/push\nGRAFANA_ADMIN_PASSWORD=already-set\n' > "${ENV_PROD_FILE}"
assert_profiles 'mysql,meilisearch,grafana'

printf 'DATABASE_URL=sqlite:///%%kernel.project_dir%%/var/data.db\nMEILISEARCH_URL=\nGRAFANA_LOKI_PUSH_URL=http://loki:3100/loki/api/v1/push\nGRAFANA_ADMIN_PASSWORD=already-set\n' > "${ENV_PROD_FILE}"
assert_profiles 'grafana'

# --- 5. an existing admin password is never regenerated ---------------------
printf 'DATABASE_URL=\nGRAFANA_LOKI_PUSH_URL=\nGRAFANA_ADMIN_PASSWORD=existing-password\n' > "${ENV_PROD_FILE}"
printf '\n' > "${queue}"
configure_grafana 'y' > /dev/null 2>&1
assert_env GRAFANA_ADMIN_PASSWORD 'existing-password'
assert_env GRAFANA_LOKI_PUSH_URL 'http://loki:3100/loki/api/v1/push'

# --- 6. an unreadable terminal REAPPLIES the current decision, changing -----
# nothing when the caller's default already matches it.
can_prompt() { return 1; }
printf 'DATABASE_URL=\nGRAFANA_LOKI_PUSH_URL=http://loki:3100/loki/api/v1/push\nGRAFANA_ADMIN_PASSWORD=existing-password\n' > "${ENV_PROD_FILE}"
configure_grafana 'y' > /dev/null 2>&1
assert_env GRAFANA_LOKI_PUSH_URL 'http://loki:3100/loki/api/v1/push'
assert_env GRAFANA_ADMIN_PASSWORD 'existing-password'

# --- 7. a headless run applies the default it was given, either way --------
can_prompt() { return 1; }
printf 'DATABASE_URL=\nGRAFANA_LOKI_PUSH_URL=\nGRAFANA_ADMIN_PASSWORD=\n' > "${ENV_PROD_FILE}"
configure_grafana 'y' > /dev/null 2>&1
assert_env GRAFANA_LOKI_PUSH_URL 'http://loki:3100/loki/api/v1/push'
[ -n "$(env_prod_get GRAFANA_ADMIN_PASSWORD)" ] || fail 'a headless default of yes must generate an admin password'
prod_uses_grafana || fail 'a headless default of yes must enable grafana'

can_prompt() { return 1; }
printf 'DATABASE_URL=\nGRAFANA_LOKI_PUSH_URL=\nGRAFANA_ADMIN_PASSWORD=\n' > "${ENV_PROD_FILE}"
configure_grafana 'n' > /dev/null 2>&1
assert_env GRAFANA_LOKI_PUSH_URL ''
assert_env GRAFANA_ADMIN_PASSWORD ''

# --- 7b. and install.sh passes 'n' as the default in both its branches -----
# Pressing return through the whole installer must never turn Grafana on:
# the non-quick branch asks with 'n' as the default, and Q applies
# use_no_grafana outright.
grep -q "configure_grafana 'n'" "${_dir}/../install.sh" \
  || fail "install.sh must pass 'n' as the grafana default in the non-quick branch"
if grep -q "configure_grafana 'y'" "${_dir}/../install.sh"; then
  fail "install.sh must not pass 'y' as the grafana default"
fi
grep -q 'use_no_grafana' "${_dir}/../install.sh" \
  || fail "install.sh's quick branch must apply use_no_grafana"

# --- 8. headless re-configure still cannot flip a stored "no" to "yes" -----
can_prompt() { return 1; }
printf 'DATABASE_URL=\nGRAFANA_LOKI_PUSH_URL=\nGRAFANA_ADMIN_PASSWORD=\n' > "${ENV_PROD_FILE}"
configure_grafana "$(current_grafana_choice)" > /dev/null 2>&1
assert_env GRAFANA_LOKI_PUSH_URL ''
if prod_uses_grafana; then
  fail 'a headless re-ask must not turn on grafana the operator declined'
fi

# --- 9. whitespace-only GRAFANA_LOKI_PUSH_URL does not count as configured --
printf 'DATABASE_URL=\nGRAFANA_LOKI_PUSH_URL=   \nGRAFANA_ADMIN_PASSWORD=\n' > "${ENV_PROD_FILE}"
if prod_uses_grafana; then
  fail 'a whitespace-only GRAFANA_LOKI_PUSH_URL must not count as configured'
fi
assert_profiles 'mysql'

printf 'ok: configure_grafana\n'
