#!/usr/bin/env bash
set -euo pipefail

# Reconfigure an existing production install: re-ask the questions
# scripts/install.sh asked -- how users reach the instance, under which
# hostname, on which port, how mail is sent, the From: address -- each
# defaulting to the current .env.prod value, then apply by re-running
# prod-start.sh and offer the same mail-delivery check. Answering every
# question with return is a no-op.
#
# Secrets and passwords are deliberately NOT touched. Regenerating
# JWT_PASSPHRASE would lock the existing signing key, and the MySQL
# passwords initialized the database volume -- changing them here would not
# change them inside MySQL. Ports and optional values are a hand edit in
# .env.prod (see .env.prod.example), applied with ./scripts/prod-start.sh.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

notes_start

ensure_docker

if [ ! -f "${ENV_PROD_FILE}" ]; then
  die 'No .env.prod found -- nothing to reconfigure. Run scripts/install.sh first, or copy .env.prod.example to .env.prod.'
fi

if [ ! -r /dev/tty ]; then
  die 'prod-configure.sh is interactive and needs a terminal.'
fi

configure_public_url
configure_mail

say 'Applying the configuration ...'
# The mail check below still asks a question, so the closing block waits until
# after it -- the operator reads it last, not in the middle of the run.
SFR_DEFER_SUMMARY=1 "${REPO_ROOT}/scripts/prod-start.sh"

offer_mail_check

print_prod_summary
