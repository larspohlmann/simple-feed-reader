#!/usr/bin/env bash
# Routes the running stack's mail to Mailpit for an e2e run (#1287): the app otherwise sends through the admin's
# saved mail server. Sourced by bin/e2e.sh, run directly by frontend/e2e/global-setup.ts.

mail_fallback_compose() {
  local repo_root="$1"
  shift
  docker compose -f "$repo_root/docker-compose.yml" "$@"
}

mail_fallback_value() {
  mail_fallback_compose "$1" exec -T php printenv MAILER_FORCE_FALLBACK 2>/dev/null || true
}

mail_fallback_wait_for_api() {
  for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "$1/api/health"; then
      return 0
    fi
    sleep 1
  done
  echo "ERROR: $1/api/health did not come back after recreating php." >&2
  return 1
}

# A php recreate alone strands nginx on the old container IP.
mail_fallback_recreate_php() {
  local repo_root="$1" base_url="$2" value="$3"
  MAILER_FORCE_FALLBACK="$value" mail_fallback_compose "$repo_root" up -d php >&2
  mail_fallback_compose "$repo_root" restart nginx >&2
  mail_fallback_wait_for_api "$base_url"
}

force_mail_fallback() {
  local repo_root="$1" base_url="$2"
  if ! command -v docker >/dev/null 2>&1 \
    || ! mail_fallback_compose "$repo_root" ps --status running --services 2>/dev/null | grep -x php >/dev/null; then
    return 2
  fi
  if [ "$(mail_fallback_value "$repo_root")" = "1" ]; then
    echo already
    return 0
  fi
  mail_fallback_recreate_php "$repo_root" "$base_url" 1 || return 1
  if [ "$(mail_fallback_value "$repo_root")" != "1" ]; then
    echo "ERROR: php still does not read MAILER_FORCE_FALLBACK=1; refusing to run e2e against a real mail server." >&2
    return 1
  fi
  if mail_fallback_compose "$repo_root" ps --status running --services 2>/dev/null | grep -x worker >/dev/null; then
    echo "WARNING: the worker still sends through the saved mail server; stop it for e2e runs (docker compose stop worker)." >&2
  fi
  echo forced
}

restore_mail_fallback() {
  mail_fallback_recreate_php "$1" "$2" 0
}

# Run only when executed directly, not when sourced (bash 3.2 safe).
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  set -euo pipefail
  case "${1:-}" in
    force) force_mail_fallback "${2:?repo root}" "${3:?base url}" ;;
    restore) restore_mail_fallback "${2:?repo root}" "${3:?base url}" ;;
    *) echo "usage: e2e-mail-fallback.sh force|restore <repo-root> <base-url>" >&2; exit 64 ;;
  esac
fi
