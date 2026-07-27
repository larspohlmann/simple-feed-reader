#!/usr/bin/env bash
# Build both halves of the app and assemble a release tree ready to rsync.
# Runs on the GitHub runner, which has composer and node; the Strato host has
# neither, so nothing is built there.
#
# Usage: deploy/strato/build-release.sh <output-dir>
set -euo pipefail

die() { echo "$*" >&2; exit 1; }

# Installed as an EXIT trap outside CI (see below). Preserves the script's own
# exit status: a failed restore is worth a warning, not a rewritten verdict.
#
# Both halves of this undo something the build does to the working tree, so a
# hand-run build does not leave the checkout in a state a developer then commits
# by accident: backend/vendor loses its dev dependencies, and version.ts gets a
# real tag written into it.
restore_working_tree() {
    local status=$?
    echo "==> Restoring dev dependencies in backend/vendor" >&2
    composer install --working-dir="${ROOT}/backend" --no-interaction --no-progress >&2 \
        || echo "!!! restore failed -- run: composer install --working-dir=${ROOT}/backend" >&2
    if [ -n "${VERSION_FILE_BACKUP:-}" ] && [ -f "${VERSION_FILE_BACKUP}" ]; then
        echo "==> Restoring ${VERSION_SOURCE_FILE}" >&2
        mv "${VERSION_FILE_BACKUP}" "${VERSION_SOURCE_FILE}" \
            || echo "!!! restore failed -- run: git checkout -- ${VERSION_SOURCE_FILE}" >&2
    fi
    exit "${status}"
}

OUT="${1:?usage: build-release.sh <output-dir>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"

# The next thing this script does is `rm -rf` the target, and the deployment
# docs tell a human to run it by hand. `${1:?}` only rejects the empty string,
# so resolve the path to something absolute and refuse the targets that would
# destroy work: the filesystem root, the checkout itself (`.` from the repo
# root), and any directory containing the checkout (`~`).
if [ -d "${OUT}" ]; then
    OUT="$(cd "${OUT}" && pwd -P)"
else
    OUT_PARENT="$(cd "$(dirname "${OUT}")" 2>/dev/null && pwd -P)" \
        || die "refusing to build into ${OUT}: its parent directory does not exist"
    OUT="${OUT_PARENT%/}/$(basename "${OUT}")"
fi
[ "${OUT}" = "/" ] && die "refusing to build into the filesystem root"
[ "${OUT}" = "${ROOT}" ] && die "refusing to build into the repository itself (${ROOT})"
case "${ROOT}/" in
    "${OUT%/}"/*) die "refusing to build into ${OUT}: it contains the repository (${ROOT})" ;;
esac
# A directory *inside* the checkout is a fine target (deploy/out, say), but not
# one of the source trees: `rm -rf` on backend/ would take config/jwt/private.pem
# and var/data_dev.db with it -- the very files this script removes from the
# release to keep them off a public host.
case "${OUT}/" in
    "${ROOT}"/backend/*|"${ROOT}"/frontend/*|"${ROOT}"/.git/*)
        die "refusing to build into a source directory (${OUT})" ;;
esac

echo "==> Assembling release into ${OUT}"
rm -rf "${OUT}"
mkdir -p "${OUT}"

echo "==> Backend dependencies (production only)"
# --no-dev strips the test and analysis tools from backend/vendor -- which is
# the same vendor/ the shared Docker php container mounts to run the suite.
# Outside CI, put it back on the way out whatever happens, so a hand-run build
# does not quietly leave the working tree unable to run tests.
if [ -z "${CI:-}" ]; then
    echo "!!! backend/vendor is about to lose its dev dependencies (phpunit, phpstan," >&2
    echo "!!! phpmd). They will be reinstalled when this script exits." >&2
    trap restore_working_tree EXIT
fi
# --no-scripts: the auto-scripts are cache:clear and assets:install. Neither can
# reach the release -- they write to backend/var/, which is never copied -- but
# both run against the runner's environment, dirtying the working tree for no
# gain and failing confusingly when a production variable is absent. Activation
# warms the cache on the server, where the real environment exists.
composer install \
    --working-dir="${ROOT}/backend" \
    --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

echo "==> Deriving the release version"
# GITHUB_REF_NAME holds the tag on a tag push -- but on a workflow_dispatch it
# holds a BRANCH name, and reading it unguarded would ship a bundle proudly
# labelled `develop`. Accept only something tag-shaped; anything else falls
# through to git, which describes the commit honestly as N commits past the last
# tag. The workflow's `fetch-depth: 0` is what gives describe its tags.
VERSION="${GITHUB_REF_NAME:-}"
case "${VERSION}" in
    v[0-9]*) ;;
    *) VERSION="$(git -C "${ROOT}" describe --tags --always 2>/dev/null || echo dev)" ;;
esac
COMMIT="$(git -C "${ROOT}" rev-parse --short HEAD 2>/dev/null || echo unknown)"
BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "==> ${VERSION} (${COMMIT}, built ${BUILT_AT})"

# The SPA carries its version baked in rather than asking the API for it. A
# cached bundle that displayed the *server's* version would report the one thing
# it cannot know -- that it is itself out of date. version.ts is committed with
# placeholders so a fresh clone builds, serves and tests with no generation step;
# here it gets the real values, and outside CI the EXIT trap puts it back.
VERSION_SOURCE_FILE="${ROOT}/frontend/src/environments/version.ts"
VERSION_FILE_BACKUP="${VERSION_SOURCE_FILE}.orig"
cp "${VERSION_SOURCE_FILE}" "${VERSION_FILE_BACKUP}"
sed -e "s|^  version: '.*',$|  version: '${VERSION}',|" \
    -e "s|^  commit: '.*',$|  commit: '${COMMIT}',|" \
    -e "s|^  builtAt: '.*',$|  builtAt: '${BUILT_AT}',|" \
    "${VERSION_FILE_BACKUP}" > "${VERSION_SOURCE_FILE}"
# A silent no-match here would ship placeholders that look like a working build,
# so the substitution is verified rather than assumed.
grep -qF "version: '${VERSION}'" "${VERSION_SOURCE_FILE}" \
    || die "could not write the version into ${VERSION_SOURCE_FILE}: its shape changed"

echo "==> Frontend bundle (/reader base href)"
# `production` is not optional. `strato` alone still produces a working,
# correctly-routed bundle -- but it silently loses content hashing, so browsers
# and caches keep serving the previous deploy's JavaScript. The hash check
# below is what catches that if this line is ever shortened.
#
# Run from frontend/ in a subshell: `npm exec` runs in the caller's working
# directory rather than the prefix, so it only found the right project here by
# fallback -- and on a miss it will happily install a same-named package from
# the registry instead. (`npm run --prefix` does chdir; `npm exec` does not.)
( cd "${ROOT}/frontend" && npm ci && npm run build -- --configuration production,strato )

echo "==> Copying backend"
# Everything the app needs at runtime, and nothing else. var/ is deliberately
# absent: it is created and linked during activation.
for item in bin config migrations public resources src vendor composer.json composer.lock .env; do
    cp -a "${ROOT}/backend/${item}" "${OUT}/"
done
# Optional only because this app is an API and may legitimately carry neither:
# templates/ does not exist today (no server-rendered views), and translations/
# would disappear if the backend ever stopped emitting localized messages. The
# list above must not be softened this way -- a typo there should fail the build.
for item in templates translations; do
    if [ -e "${ROOT}/backend/${item}" ]; then
        cp -a "${ROOT}/backend/${item}" "${OUT}/"
    fi
done

# The copy above takes the live working tree, where config/jwt holds a
# developer's own signing keys -- including the private one, which is gitignored
# precisely because it must never leave the machine. The server does not want
# them anyway: activation symlinks config/jwt to shared/config/jwt/, which holds
# the keypair generated once on the host.
rm -rf "${OUT}/config/jwt"

echo "==> Copying the built SPA into public/"
cp -R "${ROOT}/frontend/dist/frontend/browser/." "${OUT}/public/"

echo "==> Recording the release version"
# At the release root, which is %kernel.project_dir% on the server -- and NOT
# under public/, so the file itself is never web-served. Only /api/version
# exposes what it holds, and that route is authenticated.
cat > "${OUT}/version.json" <<JSON
{
  "version": "${VERSION}",
  "commit": "${COMMIT}",
  "builtAt": "${BUILT_AT}"
}
JSON

echo "==> Installing .htaccess"
cp "${ROOT}/deploy/strato/.htaccess" "${OUT}/public/.htaccess"

echo "==> Sanity checks"
test -f "${OUT}/public/index.php" || die "missing Symfony front controller"
test -f "${OUT}/public/index.html" || die "missing SPA shell"
# Not just that the file arrived, but that it carries the rewrite rules: without
# them the API and the SPA fallback both go missing on the server.
grep -q 'RewriteEngine' "${OUT}/public/.htaccess" \
    || die "public/.htaccess is present but carries no rewrite rules"
# public/index.php requires this exact file, so it proves composer both ran and
# produced an autoloader -- which `test -d vendor` does not, an empty directory
# passes that.
test -f "${OUT}/vendor/autoload_runtime.php" \
    || die "missing vendor/autoload_runtime.php: composer install did not produce an autoloader"
grep -Eq '<base[^>]*href="/reader/"' "${OUT}/public/index.html" \
    || die "SPA was not built with the /reader base href"

# Content hashing proves the production configuration composed in. Without it
# the deploy would ship unversioned filenames (main.js rather than
# main-<hash>.js) and cached copies would keep users on the previous release's
# code.
shopt -s nullglob
bundles=("${OUT}"/public/main-*.js)
(( ${#bundles[@]} )) \
    || die "bundle is not content-hashed: build the SPA with --configuration production,strato"

# Both halves must be able to name the release. The backend reads version.json;
# the SPA carries the same value compiled in, and this grep is what proves the
# rewrite above actually reached the bundle rather than being served from a
# stale build cache. Without it a deploy would ship a sidebar and a Settings
# page reporting `dev` -- which reads as "not deployed yet", the exact question
# this feature exists to answer.
#
# Search every emitted script, not just main-*.js (#139). version.ts is imported
# by the sidebar and the Settings About section, both of which are lazily
# loaded, so Angular compiles the version into a lazy chunk and main-*.js never
# carries it. Which chunk it lands in is a build-time detail that moves whenever
# imports do; that the value reached the SPA at all is the thing worth asserting.
test -f "${OUT}/version.json" || die "missing version.json at the release root"

# The bundled catalog is what makes the admin's one-click seed possible; without
# it BundledCatalog reports unavailable and the button simply does not render
# (#142). That silence is the reason to assert it here: the release built clean
# and smoke-tested green while the feature was quietly missing.
test -f "${OUT}/resources/catalog/catalog.opml" \
    || die "the bundled catalog is missing from the release"
grep -qrF "${VERSION}" "${OUT}/public" --include='*.js' \
    || die "the SPA bundle does not carry ${VERSION}: the version.ts rewrite did not compose in"

echo "==> Release assembled"
du -sh "${OUT}"
