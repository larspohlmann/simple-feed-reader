#!/usr/bin/env bash
set -euo pipefail

# Unit test for the .env.prod rename carry-over (#834). An instance installed
# before the rename holds AI_KEY_SECRET and MAILER_DSN; prod-start.sh must copy
# each to its new name before ensure_ai_key_secret can generate a fresh secret
# over the old one, and must never overwrite a value already under the new name.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/../lib.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT
ENV_PROD_FILE="${work}/.env.prod"

# --- an upgrading instance: old names only ------------------------------------
printf 'AI_KEY_SECRET=old-secret-value\nMAILER_DSN=smtp://relay:587\n' > "${ENV_PROD_FILE}"

carry_over_renamed_env_vars
ensure_ai_key_secret

[ "$(env_prod_get INSTANCE_SECRET_KEY)" = 'old-secret-value' ] \
  || fail "INSTANCE_SECRET_KEY was not carried over from AI_KEY_SECRET: $(env_prod_get INSTANCE_SECRET_KEY)"
[ "$(env_prod_get MAILER_FALLBACK_DSN)" = 'smtp://relay:587' ] \
  || fail "MAILER_FALLBACK_DSN was not carried over from MAILER_DSN: $(env_prod_get MAILER_FALLBACK_DSN)"

# --- a value already under the new name wins ----------------------------------
printf 'AI_KEY_SECRET=old\nINSTANCE_SECRET_KEY=new\nMAILER_DSN=smtp://old\nMAILER_FALLBACK_DSN=smtp://new\n' > "${ENV_PROD_FILE}"

carry_over_renamed_env_vars

[ "$(env_prod_get INSTANCE_SECRET_KEY)" = 'new' ] || fail 'an existing INSTANCE_SECRET_KEY was overwritten'
[ "$(env_prod_get MAILER_FALLBACK_DSN)" = 'smtp://new' ] || fail 'an existing MAILER_FALLBACK_DSN was overwritten'

# --- a fresh install: neither name, nothing written ---------------------------
printf 'PUBLIC_URL=https://example.test\n' > "${ENV_PROD_FILE}"

carry_over_renamed_env_vars

[ -z "$(env_prod_get INSTANCE_SECRET_KEY)" ] || fail 'INSTANCE_SECRET_KEY appeared from nowhere'
[ -z "$(env_prod_get MAILER_FALLBACK_DSN)" ] || fail 'MAILER_FALLBACK_DSN appeared from nowhere'

echo 'carry-over-renamed-env: OK'
