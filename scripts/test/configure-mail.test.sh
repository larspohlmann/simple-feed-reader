#!/usr/bin/env bash
set -euo pipefail

# Unit test for the installer's mail question in lib.sh, and above all for
# what pressing return does.
#
# Mail is the one answer that can leave an instance broken in a way nobody
# notices: a wrong relay password is accepted at configure time and only
# surfaces at the first registration nobody receives. The default is
# therefore "no mail" -- a private instance that works, with account mail
# switched off in the open (MAIL_DISABLED=1 and MAILER_DSN=null://null belong
# together, and InsecureProductionConfigGuard 500s if only one is set).
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
# Each question runs inside $( ), so the queue lives in a file rather than in
# a shell variable -- a subshell cannot advance one.
queue="${work}/answers"

queue_answers() {
  printf '%s\n' "$@" > "${queue}"
}

next_answer() {
  local answer=''
  if [ -s "${queue}" ]; then
    answer=$(head -n 1 "${queue}")
    tail -n +2 "${queue}" > "${queue}.rest"
    mv "${queue}.rest" "${queue}"
  fi
  printf '%s' "${answer}"
}

prompt_with_default() {
  local default=$2 answer
  answer=$(next_answer)
  printf '%s' "${answer:-${default}}"
}

prompt_value() { next_answer; }
prompt_secret_value() { next_answer; }

# There IS a terminal -- the point of these cases is what the questions do,
# not the terminal-less shortcut.
can_prompt() { return 0; }

seed_env() {
  cat > "${ENV_PROD_FILE}" <<'SEED'
PUBLIC_URL=https://reader.example.org
MAILER_DSN=
MAIL_DISABLED=
MAIL_FROM=
SEED
}

assert_env() {
  local key=$1 want=$2 got
  got=$(env_prod_get "${key}")
  [ "${got}" = "${want}" ] || fail "${key}: want '${want}', got '${got}'"
}

told="${work}/told"

configure_with() {
  seed_env
  queue_answers "$@"
  configure_mail > "${told}" 2>&1
}

# --- 1. pressing return runs the instance without mail -----------------------
# Both values are required together, and MAIL_FROM must be filled too: it is
# required by the compose file, so an empty one stops the stack at start over
# an address that is never used.
configure_with '' ''
assert_env MAIL_DISABLED 1
assert_env MAILER_DSN 'null://null'
assert_env MAIL_FROM 'simple-feed-reader@reader.example.org'
# No transport was configured, so there is nothing to send a test mail with.
[ -z "${CONFIGURED_MAIL_CHOICE}" ] \
  || fail 'the no-mail answer must not offer a delivery check'
grep -q 'reset-password' "${told}" \
  || fail 'without mail, the operator needs the console password-reset route'

# --- 2. an SMTP relay still assembles its DSN --------------------------------
# The password is percent-encoded: a raw '@' or '#' truncates a DSN silently.
configure_with 1 'smtp.example.org' '' 'postmaster@example.org' 'p@ss#word' ''
assert_env MAILER_DSN 'smtp://postmaster%40example.org:p%40ss%23word@smtp.example.org:587'
assert_env MAIL_DISABLED ''
assert_env MAIL_FROM 'simple-feed-reader@reader.example.org'
[ "${CONFIGURED_MAIL_CHOICE}" = 1 ] \
  || fail 'a configured relay must be offered a delivery check'

# --- 3. incomplete relay details change nothing ------------------------------
# Half a relay is worse than none: it would pass the required-value check and
# fail at the first real mail.
configure_with 1 'smtp.example.org' '' '' ''
assert_env MAILER_DSN ''
[ -z "${CONFIGURED_MAIL_CHOICE}" ] || fail 'an incomplete relay is not a transport'

# --- 4. "later" leaves the file exactly as it was ----------------------------
configure_with 3
assert_env MAILER_DSN ''
assert_env MAIL_DISABLED ''
assert_env MAIL_FROM ''

printf 'ok: configure_mail\n'
