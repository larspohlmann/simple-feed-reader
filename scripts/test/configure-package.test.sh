#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's package question in lib.sh (#453) -- the first
# question a fresh production install asks, and the one that decides how much
# memory the stack needs.
#
# Four things have to hold. The three fixed packages must select the container
# set the question promises: S no database container at all, M a MySQL one, L
# MySQL plus Meilisearch. Pressing return must land on S, whether there is a
# terminal or not -- S is the documented default, and the two sub-questions it
# selects between now default the same way, so no path through the installer
# may end up anywhere else. C must write nothing, because C means "let the two
# existing questions decide" and a value written here would answer them before
# they are asked. And an unrecognised key must re-ask instead of installing
# something: four keys are not a y/n question, where every non-'n' answer can
# safely mean yes.
#
# The question's own text is tested too, for two reasons the ticket names. The
# figures it prints are measured numbers that must not drift away from the ones
# README.md states -- so the assertions below read README.md and compare. And
# the operator has to SEE which of the four lines pressing return selects, so
# the default line is bold where the others are dim, with the key emphasised
# in every line. All of it flows through the _c_* variables, which is what
# keeps NO_COLOR working and keeps these greps free of escape stripping.
#
# The real prompts read /dev/tty. They are replaced here with a canned answer
# queue, where an empty answer means "press return", and the two output
# helpers are captured so the question can be read back.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

# --- stubs ------------------------------------------------------------------
queue="${work}/answers"
screen="${work}/screen"

prompt_with_default() {
  local default=$2 answer=''
  if [ -s "${queue}" ]; then
    answer=$(head -n 1 "${queue}")
    tail -n +2 "${queue}" > "${queue}.rest"
    mv "${queue}.rest" "${queue}"
  fi
  printf '%s' "${answer:-${default}}"
}

# The real pair writes to /dev/tty (tell) and stdout (say). Both are collected
# here, because the question is prose the operator reads as one block.
tell() { printf '%s\n' "$*" >> "${screen}"; }
say() { printf '%s\n' "$*" >> "${screen}"; }

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

assert_contains() {
  case "$2" in
    *"$1"*) return 0 ;;
  esac
  fail "$3: '$1' is missing"
}

# A fresh .env.prod, the way install.sh hands it to the question: every value
# the packages decide still empty.
answer_with() {
  printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
  printf '%s\n' "$@" > "${queue}"
  : > "${screen}"
  configure_package
}

# --- 1. S: no database container, no search engine ---------------------------
answer_with S
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
assert_env MEILISEARCH_URL ''
assert_profiles ''
if prod_uses_bundled_mysql; then
  fail 'S must not start a database container'
fi

# --- 2. M: MySQL, search against the database --------------------------------
answer_with M
assert_env DATABASE_URL ''
assert_env MEILISEARCH_URL ''
assert_profiles 'mysql'
prod_uses_bundled_mysql || fail 'M must run the bundled MySQL'

# --- 3. L: MySQL plus the search engine, with a key --------------------------
answer_with L
assert_env DATABASE_URL ''
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
[ -n "$(env_prod_get MEILISEARCH_KEY)" ] || fail 'L must generate MEILISEARCH_KEY'
assert_profiles 'mysql,meilisearch'

# --- 4. C writes neither value, so the two questions still decide ------------
# The marker is a value neither applier could produce: if C applied a package
# of its own, it would be gone.
printf 'DATABASE_URL=c-must-not-touch-this\nMEILISEARCH_URL=c-must-not-touch-this\n' > "${ENV_PROD_FILE}"
printf 'C\n' > "${queue}"
: > "${screen}"
configure_package
assert_env DATABASE_URL 'c-must-not-touch-this'
assert_env MEILISEARCH_URL 'c-must-not-touch-this'
custom_package_chosen || fail 'C must send the installer into the two sub-questions'

# ... and the three fixed packages must not.
answer_with S
if custom_package_chosen; then
  fail 'S must not run the sub-questions it already answered'
fi
answer_with L
if custom_package_chosen; then
  fail 'L must not run the sub-questions it already answered'
fi

# --- 4b. Q installs S's stack and answers everything else with its default ---
# "Quick" is a promise about the QUESTIONS, not about the containers: it runs
# the same stack as S, and the rest of the installer stops asking.
answer_with Q
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
assert_env MEILISEARCH_URL ''
assert_profiles ''
quick_package_chosen || fail 'Q must stop the installer asking'
if custom_package_chosen; then
  fail 'Q must not run the two sub-questions'
fi
answer_with S
if quick_package_chosen; then
  fail 'S is a stack, not a promise to stop asking'
fi

# --- 4c. and the file it leaves behind is complete ---------------------------
# The whole point of Q: nothing more to answer. So every value
# docker-compose.prod.yml refuses to start without has to be filled in by the
# time the question returns -- with the two remaining questions never asked,
# nothing else is going to write them.
cp "${_dir}/../../.env.prod.example" "${ENV_PROD_FILE}"
for secret in APP_SECRET ALTCHA_HMAC_KEY JWT_PASSPHRASE MYSQL_ROOT_PASSWORD MYSQL_PASSWORD; do
  env_prod_set "${secret}" 'generated-by-install-sh'
done
printf 'Q\n' > "${queue}"
: > "${screen}"
configure_package
apply_default_public_origin
use_no_mail
missing=$(env_prod_missing)
[ -z "${missing}" ] || fail "a quick install must leave nothing to fill in, but: ${missing}"
assert_env PUBLIC_URL 'http://localhost:3333'
assert_env MAIL_DISABLED '1'

# --- 5. an empty answer selects Q -------------------------------------------
# Pressing return installs the quick package: S's stack, and no further
# question. What it decides on the operator's behalf is printed with it --
# asserted in section 9 -- because a default that silently picks an origin and
# a mail setting is the one thing a default must not be.
answer_with ''
quick_package_chosen || fail 'pressing return must select the quick install'
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
assert_env MEILISEARCH_URL ''

# --- 6. lower case is accepted ----------------------------------------------
answer_with s
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
answer_with m
assert_env DATABASE_URL ''
assert_env MEILISEARCH_URL ''
answer_with l
assert_env MEILISEARCH_URL 'http://meilisearch:7700'
answer_with q
quick_package_chosen || fail 'lower case q must select the quick install'
printf 'DATABASE_URL=c-must-not-touch-this\n' > "${ENV_PROD_FILE}"
printf 'c\n' > "${queue}"
configure_package
assert_env DATABASE_URL 'c-must-not-touch-this'

# --- 7. an unrecognised key re-asks and writes nothing -----------------------
# Two wrong keys, then C -- so the whole run is provably free of writes, and
# the operator was told twice what to type. A y/n question can treat anything
# that is not 'n' as yes; four keys cannot.
printf 'DATABASE_URL=nothing-may-write-here\nMEILISEARCH_URL=nothing-may-write-here\n' > "${ENV_PROD_FILE}"
printf 'x\nyes please\nC\n' > "${queue}"
: > "${screen}"
configure_package
assert_env DATABASE_URL 'nothing-may-write-here'
assert_env MEILISEARCH_URL 'nothing-may-write-here'
[ "$(grep -c 'Answer S, M, L, Q or C' "${screen}")" = '2' ] \
  || fail 'each unrecognised key must be told what to type'

# --- 8. no terminal lands on S, not on Q ------------------------------------
# The headless install (curl | bash without a terminal) writes .env.prod and
# stops for the mail question. It must land on the documented stack here,
# applied through the same appliers -- not leave the file alone, where an empty
# DATABASE_URL means MySQL and an empty MEILISEARCH_URL is indistinguishable
# from a decline nobody made.
#
# S, not the question's own default Q: Q additionally promises that nothing
# else will be asked, and without a terminal nothing can be asked in the first
# place. Taking it would make the installer invent a public origin and a mail
# setting for a machine it could not ask about, and start a stack instead of
# stopping with the two-step instructions it documents.
can_prompt() { return 1; }
printf 'DATABASE_URL=\nMEILISEARCH_URL=\nMEILISEARCH_KEY=\n' > "${ENV_PROD_FILE}"
: > "${screen}"
configure_package
assert_env DATABASE_URL 'sqlite:///%kernel.project_dir%/var/data.db'
assert_env MEILISEARCH_URL ''
assert_profiles ''
if custom_package_chosen; then
  fail 'a headless install must not wait for questions it cannot ask'
fi
if quick_package_chosen; then
  fail 'a headless install must keep the two-step flow, not skip past it'
fi
# It still says what it applied -- that goes to stdout, which a piped install
# shows -- but it must not print a question nobody can answer.
case "$(cat "${screen}")" in
  *'Which package'*) fail 'a headless install must not ask a question nobody can answer' ;;
esac
can_prompt() { return 0; }

# --- 9. the question states the measured memory figure of every package -----
# Measured numbers (see the issue), not estimates, so they are asserted here
# and compared against README.md below: an operator who reads both must not
# find two different numbers.
answer_with S
question=$(cat "${screen}")
for key in S M L Q C; do
  assert_contains "$(package_description "${key}")" "${question}" "the ${key} line"
done
for key in S M L Q; do
  assert_contains "$(package_memory "${key}")" "${question}" "the ${key} memory figure"
done
# And the default says what it decides for the operator, before they press
# return on it: the origin and the mail setting it picks without asking.
assert_contains "$(package_note Q)" "${question}" "the default's own note"
assert_contains 'http://localhost:3333' "${question}" 'the origin the default picks'
# And every answer says what it still leaves open, which is the other half of
# the choice: what runs, and what the operator gets to decide.
assert_contains 'S, M and L ask for the public URL and for mail next.' "${question}" \
  'what the three stacks still ask'
assert_contains 'plus the database and the search engine' "${question}" \
  'what C additionally asks'

# --- 10. README.md states the same words and the same figures ---------------
# The operator chooses the package while reading README.md, and answers it in
# the terminal. Both texts are written from one measurement, so a change to one
# of them has to fail here rather than leave the two disagreeing.
readme=$(cat "${_dir}/../../README.md")
for key in S M L; do
  assert_contains "$(package_description "${key}")" "${readme}" "README.md's ${key} row"
  assert_contains "$(package_memory "${key}")" "${readme}" "README.md's ${key} memory figure"
done
# Q is not a row -- it runs S's containers, so a row would repeat S's numbers
# -- but the reader still has to meet the default before the terminal does,
# with what it decides on their behalf spelled out the same way.
assert_contains "$(package_note Q)" "${readme}" "README.md's note on the default"

# --- 11. the default line is emphasised, every key is -----------------------
# The operator has to see which line pressing return selects, so the default
# is bold and the other three are dim. Forced on here: the variables are empty
# whenever stdout is not a terminal, which is every CI run.
_c_bold=$'\033[1m' _c_dim=$'\033[2m' _c_cyan=$'\033[36m' _c_reset=$'\033[0m'
: > "${screen}"
package_question_line Q
package_question_line S
default_line=$(sed -n 1p "${screen}")
# Line 2 is the default's own note, so the dimmed line to compare against is
# the one after it.
other_line=$(sed -n 3p "${screen}")
assert_contains "${_c_bold}" "${default_line}" "the default package's line is bold"
assert_contains "${_c_dim}" "${other_line}" 'the other packages are dimmed'
case "${default_line}" in
  *"${_c_dim}"*) fail 'the default package must not be dimmed' ;;
esac
assert_contains "Q${_c_reset}" "${default_line}" 'the default key stands out from its line'
assert_contains "${_c_cyan}" "${other_line}" 'the key to type is emphasised in every line'
# The note belongs to the line above it, so it carries that line's emphasis.
assert_contains "${_c_bold}" "$(sed -n 2p "${screen}")" "the default's note is emphasised with it"

# --- 12. cleared colour variables leave the text plain ----------------------
# What keeps NO_COLOR working: the question borrows the script's own _c_*
# variables and adds no escape of its own, so a test that greps its text never
# has to strip one.
_c_bold='' _c_dim='' _c_cyan='' _c_reset=''
: > "${screen}"
package_question_line Q
package_question_line C
case "$(cat "${screen}")" in
  *$'\033'*) fail 'with the colour variables cleared the question must be plain text' ;;
esac

# --- 13. install.sh asks the package question first, and the rest of it -----
# only for C. The two sub-questions now default to SQLite and to no engine, so
# a C install that answers everything with return lands where S does; and no
# other path may reach them, or the package answer is overwritten by a
# question the operator was never meant to see.
installer=$(cat "${_dir}/../install.sh")
assert_contains 'configure_package' "${installer}" 'install.sh asks the package question'
assert_contains "configure_search_engine 'n'" "${installer}" \
  "install.sh's search-engine default is now no"
assert_contains 'custom_package_chosen' "${installer}" \
  'the two sub-questions run for C only'
assert_contains 'quick_package_chosen' "${installer}" \
  'the quick install skips the remaining questions'
assert_contains 'apply_default_public_origin' "${installer}" \
  'the quick install still applies the origin it skipped asking about'
assert_contains 'use_no_mail' "${installer}" \
  'the quick install still applies the mail default it skipped asking about'

printf 'ok: configure_package\n'
