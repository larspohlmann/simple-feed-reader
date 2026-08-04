#!/usr/bin/env bash
set -euo pipefail

# Regression test for issue #275: a `curl ... | bash` install that stopped
# halfway through and reported success.
#
# The installer advertises `curl -fsSL ... | bash`, which means bash reads the
# script FROM STDIN, a byte at a time, as it executes it. Any child process
# that reads stdin therefore eats the part of the installer that bash has not
# reached yet -- and bash, finding EOF where the next command should be, exits
# 0. The install looks successful and simply never ran its last steps.
#
# `docker compose exec` is exactly such a child: it attaches the caller's
# stdin and drains it whole, even when the command it runs never reads a byte.
# compose() and prod_compose() close stdin for that reason.
#
# The test does the real thing rather than describing it: it pipes a script
# into bash, has that script call the real wrappers with a docker stub that
# drains stdin like the real client, and asserts the line AFTER the call still
# runs.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
_root=$(CDPATH='' cd -- "${_dir}/../.." && pwd -P)

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

# One case per wrapper, because each one is a separate chance to forget the
# redirect -- prod_compose is the one the installer uses, compose() is the one
# the dev installer and update.sh use.
write_piped_script() {
  local wrapper=$1 script=$2
  cat > "${script}" <<SCRIPT
# The stub stands in for the docker CLI, which forwards stdin to the container
# and consumes all of it. If the wrapper passes the caller's stdin along, this
# 'cat' eats the rest of THIS script.
docker() { cat > /dev/null; }

source "${_root}/scripts/lib.sh"

${wrapper} exec -T php bin/console some:command

# Everything below here is what a truncated install silently skips. It is
# padded so the assertion cannot pass merely because bash had already buffered
# the tail: the real installer has hundreds of bytes left at this point too.
: '$(printf 'x%.0s' {1..2000})'
printf 'THE INSTALLER REACHED ITS LAST STEP\n'
SCRIPT
}

assert_runs_to_the_end() {
  local wrapper=$1 script="${work}/piped-$1.sh" output
  write_piped_script "${wrapper}" "${script}"
  # `cat script | bash` is `curl ... | bash` with the network removed: bash's
  # stdin is a pipe carrying the script itself.
  output=$(cat "${script}" | bash 2>&1) || fail "${wrapper}: the piped script failed: ${output}"
  case "${output}" in
    *'THE INSTALLER REACHED ITS LAST STEP'*) ;;
    *) fail "${wrapper}: the piped script stopped early -- a child ate the rest of it" ;;
  esac
}

assert_runs_to_the_end compose
assert_runs_to_the_end prod_compose

# --- the mechanism itself, so a future reader can see it is real -------------
# Without the redirect this is what happens. If THIS case ever stops failing,
# bash has changed how it reads piped scripts and the two cases above no
# longer prove anything.
cat > "${work}/unprotected.sh" <<'SCRIPT'
drains_stdin() { cat > /dev/null; }
drains_stdin
: 'padding padding padding padding padding padding padding padding padding'
printf 'THIS LINE MUST NOT SURVIVE\n'
SCRIPT
unprotected=$(cat "${work}/unprotected.sh" | bash 2>&1) || true
case "${unprotected}" in
  *'THIS LINE MUST NOT SURVIVE'*)
    fail 'a stdin-draining child no longer truncates a piped script -- re-check what these tests prove'
    ;;
esac

printf 'ok: a piped installer is not truncated by docker compose\n'
