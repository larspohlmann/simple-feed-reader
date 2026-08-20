#!/usr/bin/env bash
set -euo pipefail

# Unit test for scripts/stamp-version.sh -- the one place that records a build's
# release version, so the Docker image builds and the Strato release build never
# stamp it differently (issue #500).
#
# The two behaviours worth pinning: the tag-shape guard on `derive` (a branch
# name must never be shipped as the version), and `apply` writing exactly the
# version.ts and version.json the two readers expect -- including its two
# refusals, a shape that no longer matches and an empty version that must leave
# a local build untouched.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
script="${_dir}/../stamp-version.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "${work}"' EXIT

# The committed placeholder, owned by this test rather than read from the real
# file, so a change to that file cannot silently rewrite what is asserted here.
seed_version_ts() {
  cat > "$1" <<'TS'
export const buildVersion = {
  version: 'dev',
  commit: 'local',
  builtAt: '',
};
TS
}

# --- 1. derive takes a tag-shaped ref verbatim ------------------------------
# GITHUB_REF_NAME on a tag push is the release tag, and it must be reported as
# is -- the first line is the version.
version=$("${script}" derive 'v1.2.3' | head -n 1)
[ "${version}" = 'v1.2.3' ] || fail "derive tag: want 'v1.2.3', got '${version}'"

# --- 2. derive rejects a non-tag ref and falls through to git ----------------
# A workflow_dispatch leaves a BRANCH name here; shipping it as the version
# would label the bundle 'develop'. The guard must send it to `git describe`,
# whose answer for this repository is never the literal branch name.
version=$("${script}" derive 'develop' | head -n 1)
[ -n "${version}" ] || fail 'derive branch: version line is empty'
[ "${version}" != 'develop' ] || fail 'derive branch: shipped the branch name as the version'

# --- 3. apply writes both targets with the given values ----------------------
ts="${work}/version.ts"
json="${work}/version.json"
seed_version_ts "${ts}"
"${script}" apply \
  --version 'v0.6.1' --commit 'abc1234' --built-at '2026-08-20T10:00:00Z' \
  --version-ts "${ts}" --version-json "${json}"

grep -qF "version: 'v0.6.1'," "${ts}" || fail 'apply: version.ts version not written'
grep -qF "commit: 'abc1234'," "${ts}" || fail 'apply: version.ts commit not written'
grep -qF "builtAt: '2026-08-20T10:00:00Z'," "${ts}" || fail 'apply: version.ts builtAt not written'
# version.json is what FileReleaseVersionReader parses: valid JSON, all three
# fields non-empty strings.
python3 - "${json}" <<'PY' || fail 'apply: version.json is not the expected JSON'
import json, sys
data = json.load(open(sys.argv[1]))
assert data == {
    'version': 'v0.6.1',
    'commit': 'abc1234',
    'builtAt': '2026-08-20T10:00:00Z',
}, data
PY

# --- 4. an empty version is a no-op -----------------------------------------
# A local or manual image build supplies none. Both halves must keep reporting
# the development build, so version.ts stays a byte-for-byte placeholder and no
# version.json appears.
ts2="${work}/local.ts"
json2="${work}/local.json"
seed_version_ts "${ts2}"
seed_version_ts "${work}/local.ts.expected"
"${script}" apply --version '' --commit x --built-at y \
  --version-ts "${ts2}" --version-json "${json2}"
cmp -s "${ts2}" "${work}/local.ts.expected" \
  || fail 'empty version: version.ts was modified'
[ ! -e "${json2}" ] || fail 'empty version: version.json was written'

# --- 5. a shape that no longer matches is a hard failure ---------------------
# A silent no-match would ship the 'dev' placeholder that looks like a working
# build. The verify inside apply must turn that into a non-zero exit.
bad="${work}/bad.ts"
printf 'export const buildVersion = { version: "dev" };\n' > "${bad}"
if "${script}" apply --version 'v0.6.1' --commit 'abc1234' \
    --built-at '2026-08-20T10:00:00Z' --version-ts "${bad}" 2>/dev/null; then
  fail 'apply must fail when version.ts no longer has the expected shape'
fi

# --- 6. a version with no commit or built-at is refused ----------------------
# derive always supplies all three; a caller that sets only the version would
# otherwise write a version.json the backend rejects.
if "${script}" apply --version 'v0.6.1' --version-json "${work}/x.json" 2>/dev/null; then
  fail 'apply must refuse a version without a commit'
fi

printf 'ok: stamp-version\n'
