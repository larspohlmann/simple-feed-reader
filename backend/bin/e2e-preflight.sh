#!/usr/bin/env bash
# Preflight guard for the e2e suites (#615).
#
# Both e2e suites are black-box tests against the single shared Docker stack,
# whose project name is pinned to "simple-feed-reader". The stack's code under
# test comes from the ./backend and ./frontend bind mounts, which resolve
# against whatever directory last ran `docker compose up`. So a stack started
# from a different checkout (e.g. a git worktree) answers the same :8443 and
# the same `docker compose exec`, and the suite silently tests the WRONG code.
#
# This guard reads the running php container's /app mount source and compares
# it to the current checkout's backend/. Mismatch fails fast and names the
# owning path, turning a silent wrong-checkout run into a legible error.
#
# Usage:
#   source backend/bin/e2e-preflight.sh && assert_stack_owns_checkout "$REPO_ROOT"
#   bash   backend/bin/e2e-preflight.sh "$REPO_ROOT"
#
# Exit codes (of the function and of direct invocation):
#   0  this checkout owns the running stack, OR no stack is running
#   1  a stack is running but mounts a different checkout (message names it)
#   2  docker is not usable (binary missing or daemon unreachable)
set -euo pipefail

# Resolve a path to its canonical form without realpath(1), which is absent on
# stock macOS. `cd … && pwd -P` follows symlinks and normalises, and works on
# bash 3.2. A missing directory prints nothing and returns non-zero.
canonical_path() {
  local target="$1"
  if [ ! -d "$target" ]; then
    return 1
  fi
  ( cd "$target" && pwd -P )
}

# stdout: the host path the running stack's php /app mount points at, or empty
# if no such container is running. Returns 2 if docker itself is unusable.
running_stack_backend_mount() {
  if ! command -v docker >/dev/null 2>&1; then
    return 2
  fi
  local container
  if ! container="$(docker ps -q \
    --filter label=com.docker.compose.project=simple-feed-reader \
    --filter label=com.docker.compose.service=php 2>/dev/null)"; then
    return 2
  fi
  if [ -z "$container" ]; then
    # No running php container: not an ownership problem. Empty stdout, ok.
    return 0
  fi
  docker inspect "$container" \
    --format '{{range .Mounts}}{{if eq .Destination "/app"}}{{.Source}}{{end}}{{end}}'
}

# Fail fast if the running stack is owned by a different checkout than $1.
assert_stack_owns_checkout() {
  local repo_root="$1"
  local expected_backend mounted_backend rc

  expected_backend="$(canonical_path "$repo_root/backend" || true)"

  set +e
  mounted_backend="$(running_stack_backend_mount)"
  rc=$?
  set -e

  if [ "$rc" -eq 2 ]; then
    echo "==> Preflight: docker not usable; skipping the stack-ownership check." >&2
    return 2
  fi
  if [ -z "$mounted_backend" ]; then
    # No stack running. The caller decides whether that is fatal.
    return 0
  fi

  mounted_backend="$(canonical_path "$mounted_backend" || printf '%s' "$mounted_backend")"

  if [ "$mounted_backend" = "$expected_backend" ]; then
    return 0
  fi

  local owning_root="${mounted_backend%/backend}"
  echo "ERROR: the running 'simple-feed-reader' Docker stack is owned by a different checkout." >&2
  echo "       stack mounts:  $mounted_backend" >&2
  echo "       you are in:    $expected_backend" >&2
  echo "       The e2e suites are black-box against that single shared stack, so this run" >&2
  echo "       would test the OTHER checkout. Run e2e from the owning checkout:" >&2
  echo "         $owning_root" >&2
  echo "       or take ownership here first:  (cd '$repo_root' && docker compose up -d)" >&2
  return 1
}

# Run only when executed directly, not when sourced (bash 3.2 safe).
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  assert_stack_owns_checkout "${1:?usage: e2e-preflight.sh <repo-root>}"
fi
