#!/usr/bin/env bash
# Build both halves of the app and assemble a release tree ready to rsync.
# Runs on the GitHub runner, which has composer and node; the Strato host has
# neither, so nothing is built there.
#
# Usage: deploy/strato/build-release.sh <output-dir>
set -euo pipefail

OUT="${1:?usage: build-release.sh <output-dir>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

echo "==> Assembling release into ${OUT}"
rm -rf "${OUT}"
mkdir -p "${OUT}"

echo "==> Backend dependencies (production only)"
# --no-scripts: the auto-scripts run cache:clear, which needs production env
# vars that only exist on the server. The cache is warmed during activation.
composer install \
    --working-dir="${ROOT}/backend" \
    --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

echo "==> Frontend bundle (/reader base href)"
npm --prefix "${ROOT}/frontend" ci
# BOTH configurations, in this order. `strato` alone still produces a working,
# correctly-routed bundle -- but it silently loses content hashing, so browsers
# and caches keep serving the previous deploy's JavaScript. The hash check
# below is what catches that if this line is ever shortened.
npm --prefix "${ROOT}/frontend" exec -- ng build --configuration production,strato

echo "==> Copying backend"
# Everything the app needs at runtime, and nothing else. var/ is deliberately
# absent: it is created and linked during activation.
for item in bin config migrations public src templates translations vendor composer.json composer.lock .env; do
    if [ -e "${ROOT}/backend/${item}" ]; then
        cp -R "${ROOT}/backend/${item}" "${OUT}/"
    fi
done

echo "==> Copying the built SPA into public/"
cp -R "${ROOT}/frontend/dist/frontend/browser/." "${OUT}/public/"

echo "==> Installing .htaccess"
cp "${ROOT}/deploy/strato/.htaccess" "${OUT}/public/.htaccess"

echo "==> Sanity checks"
test -f "${OUT}/public/index.php"   || { echo "missing Symfony front controller"; exit 1; }
test -f "${OUT}/public/index.html"  || { echo "missing SPA shell"; exit 1; }
test -f "${OUT}/public/.htaccess"   || { echo "missing .htaccess"; exit 1; }
test -d "${OUT}/vendor"             || { echo "missing vendor/"; exit 1; }
grep -q 'base href="/reader/"' "${OUT}/public/index.html" \
    || { echo "SPA was not built with the /reader base href"; exit 1; }

# Content hashing proves the production configuration composed in. Without it
# the deploy would ship cache-poisoned filenames (main.js rather than
# main-<hash>.js) and users would keep running the previous release's code.
ls "${OUT}/public/" | grep -qE '^main-[A-Za-z0-9]+\.js$' \
    || { echo "bundle is not content-hashed: build the SPA with --configuration production,strato"; exit 1; }

echo "==> Release assembled"
du -sh "${OUT}"
