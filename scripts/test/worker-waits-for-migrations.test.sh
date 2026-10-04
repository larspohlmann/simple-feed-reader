#!/usr/bin/env bash
set -euo pipefail

# Unit test for #1379: a bring-up must not run the worker between the new code
# going live and the migration. On SQLite the worker's writes locked the
# migration out ("database is locked"); on any database it queried columns the
# old schema did not have yet. compose is stubbed and records every call,
# because the ORDER of the calls is the behaviour under test.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

calls=$(mktemp)
trap 'rm -f "${calls}"' EXIT

compose() { printf '%s\n' "$*" >> "${calls}"; }

bring_up_stack >/dev/null

line_of() { grep -n -e "$1" "${calls}" | head -n 1 | cut -d: -f1; }

up_line=$(line_of '^up -d')
migrate_line=$(line_of 'doctrine:migrations:migrate')
worker_line=$(line_of 'worker$')

[ -n "${up_line}" ] || fail "the stack was never brought up: $(cat "${calls}")"
[ -n "${migrate_line}" ] || fail "the migration never ran: $(cat "${calls}")"
[ -n "${worker_line}" ] || fail "the worker was never started: $(cat "${calls}")"

sed -n "${up_line}p" "${calls}" | grep -q -e '--scale worker=0' \
  || fail "the bring-up started the worker with the stack: $(sed -n "${up_line}p" "${calls}")"
[ "${worker_line}" -gt "${migrate_line}" ] \
  || fail 'the worker was started before the migration ran'
sed -n "${worker_line}p" "${calls}" | grep -q -e '^up -d' \
  || fail "a stopped or removed worker is not brought back by: $(sed -n "${worker_line}p" "${calls}")"

echo 'worker-waits-for-migrations: OK'
