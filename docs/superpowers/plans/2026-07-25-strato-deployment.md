# Personal STRATO Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve the reader at `https://lars-pohlmann.de/reader` over the apex domain's existing certificate, deployed by an atomic symlink flip from GitHub Actions.

**Architecture:** The app is mounted at a subpath by symlinking it into the portfolio docroot. Symfony derives its base path from `SCRIPT_NAME` automatically, so no route changes are needed; Angular gets a `/reader/` base href from an additive build configuration. An Apache `.htaccess` splits one directory between Symfony's front controller (`/api`, `/maintenance`) and the SPA. Everything personal lives in `deploy/strato/`.

**Tech Stack:** Symfony 7.4 (PHP 8.4, cgi-fcgi SAPI), Angular 20, MySQL 8, Apache `.htaccess`, GitHub Actions, rsync over SSH.

**Spec:** `docs/superpowers/specs/2026-07-25-strato-deployment-design.md`
**Issue:** [#73](https://github.com/larspohlmann/simple-feed-reader/issues/73)

---

## Context an implementer needs

**The host.** STRATO shared hosting. The *vhost* runs PHP 8.4.22 in the **cgi-fcgi** SAPI,
where `php -r` does not work. A real CLI of the same build sits at
`/opt/RZphp84/bin/php-cli`, unlinked from `PATH` — console commands use that, spelled
ordinarily, and it carries no `max_execution_time` ceiling. There is no composer, no node,
and no crontab on the server, so everything is built on the runner and shipped as files.
`git`, `rsync`, and the `mysql` client are present. Symlinks work.

**The layout.** SSH home *is* the htdocs root. Each domain is internally mapped to a
subfolder: `lars-pohlmann.de` → `~/larspohlmann/` (a static portfolio, no `.htaccess`),
`reader.lars-pohlmann.de` → `~/simplefeedreader/current/public`. The app deploys into
`~/simplefeedreader/` and is mounted at `~/larspohlmann/reader`.

**Why a subpath at all.** STRATO's free certificate covers only the apex domain and `www`,
never subdomains. The apex is already certified, so `/reader` gets HTTPS for free.

**Already done, do not redo.** All five deploy secrets exist on the repository
(`STRATO_SSH_HOST`, `STRATO_SSH_USER`, `STRATO_SSH_KEY`, `STRATO_KNOWN_HOSTS`,
`STRATO_DEPLOY_PATH`) and the deploy public key is installed on the server.

**Conventions from CI (`.github/workflows/ci.yml`).** PHP `8.4` via `shivammathur/setup-php@v2`
with `extensions: intl, pdo_sqlite, pdo_mysql`; Node `22` via `actions/setup-node@v4`
followed by `npm i -g npm@11` (Node 22 ships npm 10.9.8, which mis-resolves chokidar and
rejects the npm-11-authored lockfile) and then `npm ci`.

**Gates.** Backend `composer check` (cs + stan) and `composer md`. Frontend `npm run check`
(lint + format:check + stylelint + jest). Any PHP source touched must be phpmd-clean.

---

## File structure

**New — personal deployment tooling, isolated:**

| File | Responsibility |
| --- | --- |
| `deploy/strato/.htaccess` | Apache rules splitting one directory between Symfony and the SPA |
| `deploy/strato/build-release.sh` | Build both halves on the runner and assemble a release tree |
| `deploy/strato/activate-release.sh` | Run on the server: link shared state, warm the cache, migrate, flip `current` |
| `deploy/strato/.env.local.example` | Names and explanations of every production variable — no values |
| `deploy/strato/README.md` | Manual panel steps, first-deploy runbook, rollback |

**New — unavoidably outside that directory:**

| File | Responsibility |
| --- | --- |
| `.github/workflows/deploy-strato.yml` | Deploys `develop` after CI passes; calls the two scripts above |
| `frontend/src/environments/environment.strato.ts` | `apiBaseUrl: '/reader'` |

**Modified — additive, defaults unchanged:**

| File | Change |
| --- | --- |
| `frontend/angular.json` | New `strato` build configuration |
| `frontend/src/app/core/transloco-loader.ts` | Absolute `/i18n/` path → relative (bug fix) |
| `backend/config/packages/cache.yaml` | `prefix_seed` + `directory` become env-driven |
| `backend/.env` | Defaults for the two new variables, preserving current behaviour |
| `.github/workflows/ci.yml` | `develop` joins the push-trigger branches, so the deploy has a CI result to gate on |

---

## Task 1: Make the i18n dictionary path base-href aware

The Transloco loader requests `/i18n/de.json` — absolute from the domain root. Mounted at
`/reader` that resolves to `https://lars-pohlmann.de/i18n/de.json`, which is the portfolio's
territory and 404s. Dictionaries are preloaded before first paint, so the whole UI would come
up untranslated. A relative URL resolves against the document base URI, which is exactly what
`<base href>` sets — so one string fixes both deployments.

**Files:**
- Modify: `frontend/src/app/core/transloco-loader.ts:12`
- Test: `frontend/src/app/core/transloco-loader.spec.ts` (create)

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/core/transloco-loader.spec.ts`:

```typescript
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpTranslocoLoader } from './transloco-loader';

describe('HttpTranslocoLoader', () => {
  let loader: HttpTranslocoLoader;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [HttpTranslocoLoader, provideHttpClient(), provideHttpClientTesting()],
    });
    loader = TestBed.inject(HttpTranslocoLoader);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  // The app is served both at the domain root (Docker) and under a /reader
  // subpath (Strato). A leading slash would pin the request to the root and
  // 404 under the subpath; a relative URL resolves against <base href>.
  it('requests the dictionary relative to the base href', () => {
    loader.getTranslation('de').subscribe();

    const req = ctrl.expectOne('i18n/de.json');
    expect(req.request.url.startsWith('/')).toBe(false);
    req.flush({});
  });
});
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
cd frontend && npx jest src/app/core/transloco-loader.spec.ts
```

Expected: FAIL — `expectOne('i18n/de.json')` finds no match because the request went to
`/i18n/de.json`.

- [ ] **Step 3: Make the path relative**

In `frontend/src/app/core/transloco-loader.ts`, replace line 12:

```typescript
    return this.http.get<Translation>(`i18n/${lang}.json`);
```

And update the class doc comment above it to record why:

```typescript
/** Loads a language's dictionary from the statically-served `public/i18n/`.
 *
 *  The path is deliberately RELATIVE. The app is served at the domain root by
 *  the Docker setup and under a `/reader` subpath on Strato; a relative URL
 *  resolves against the document base URI, which `<base href>` sets per build,
 *  so one path is correct for both. A leading slash would 404 under a subpath.
 */
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
cd frontend && npx jest src/app/core/transloco-loader.spec.ts
```

Expected: PASS.

- [ ] **Step 5: Run the full frontend gate**

```bash
cd frontend && npm run check
```

Expected: lint, format, stylelint, and the whole Jest suite pass. The dictionaries are still
served from the same place at the root, so no existing spec should change.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/core/transloco-loader.ts frontend/src/app/core/transloco-loader.spec.ts
git commit -m "fix(i18n): load dictionaries relative to the base href (#73)

An absolute /i18n/ path pins the request to the domain root, which 404s
when the app is mounted under a subpath. A relative URL resolves against
<base href> and is correct at the root and under /reader alike."
```

---

## Task 2: Add the `strato` Angular build configuration

Angular configurations can be composed on the command line (`--configuration production,strato`,
later wins), so `strato` only needs to add what differs: the base href and the environment file.
`production` stays byte-for-byte as it is, which is what keeps the Docker prod profile and every
existing build emitting a root-path bundle.

**Files:**
- Create: `frontend/src/environments/environment.strato.ts`
- Modify: `frontend/angular.json:43-70` (the `configurations` block)

- [ ] **Step 1: Create the environment file**

Create `frontend/src/environments/environment.strato.ts`:

```typescript
// src/environments/environment.strato.ts
// Personal Strato deployment: the app is mounted under /reader on the apex
// domain, so API calls must carry that prefix. Every call site builds
// `${apiBaseUrl}/api/...`, and the bearer interceptor matches on this same
// value, so setting it here is the whole change.
export const environment = {
  production: true,
  apiBaseUrl: '/reader',
};
```

- [ ] **Step 2: Add the configuration to `angular.json`**

In `frontend/angular.json`, inside `projects.frontend.architect.build.configurations`, add a
`strato` entry after `development` (leave `production` and `development` untouched):

```json
            "strato": {
              "baseHref": "/reader/",
              "fileReplacements": [
                {
                  "replace": "src/environments/environment.ts",
                  "with": "src/environments/environment.strato.ts"
                }
              ]
            }
```

- [ ] **Step 3: Build it and verify the base href**

```bash
cd frontend && npx ng build --configuration production,strato
grep -o '<base href="[^"]*"' dist/frontend/browser/index.html
```

Expected: `<base href="/reader/"`.

- [ ] **Step 4: Verify the default build is unchanged**

```bash
cd frontend && npx ng build
grep -o '<base href="[^"]*"' dist/frontend/browser/index.html
```

Expected: `<base href="/"`. This is the isolation proof — the Docker path is untouched.

- [ ] **Step 5: Commit**

```bash
git add frontend/angular.json frontend/src/environments/environment.strato.ts
git commit -m "build(frontend): add an additive strato build configuration (#73)

Composes onto production (--configuration production,strato) and only
adds the /reader base href and its environment file, so the default
root-path build the Docker setup uses is untouched."
```

---

## Task 3: Make the filesystem cache pools survive a deploy

Rate-limit counters and spent ALTCHA solutions live in filesystem pools. Two separate defaults
break them across deploys, and both must be fixed or the fix is useless:

1. `framework.cache.directory` defaults to `%kernel.share_dir%/pools/app`, which resolves inside
   `var/cache/<env>` — per-release *and* wiped by `cache:clear`.
2. `framework.cache.prefix_seed` defaults to `_%kernel.project_dir%.%kernel.container_class%`.
   `project_dir` is `releases/<tag>`, so every deploy changes the key namespace even if the
   directory were shared.

Losing them re-opens the ALTCHA replay window and resets every rate limit on each deploy.
Both become env-driven with defaults that reproduce today's behaviour exactly.

**Files:**
- Modify: `backend/config/packages/cache.yaml`
- Modify: `backend/.env`

- [ ] **Step 1: Add the defaults to `backend/.env`**

Append to the `###> symfony/framework-bundle ###` section of `backend/.env` (below `APP_SECRET`):

```dotenv
# Where the filesystem cache pools (rate limiter, ALTCHA replay, OAuth state)
# are written, and the namespace their keys live under.
#
# CACHE_DIRECTORY is Symfony's own default, verbatim -- the on-disk location
# is unchanged for the Docker setup and for local development.
#
# CACHE_PREFIX_SEED deliberately does NOT reproduce Symfony's default
# (`_%kernel.project_dir%.%kernel.container_class%`). That default changes
# with the checkout path, so it would keep renaming the namespace on every
# deploy even after CACHE_DIRECTORY is pointed at shared storage. Pinning a
# literal is the only way to get one stable namespace across releases. It
# costs one renamespacing wherever cache data already exists, dropping the
# then-current rate-limit counters and spent ALTCHA solutions -- a cold start
# all three pools tolerate.
#
# A release-directory deployment MUST override both in .env.local: Symfony's
# real defaults resolve inside var/cache (per-release, and wiped by
# cache:clear) and seed the namespace with %kernel.project_dir% (the release
# path). Left alone, every deploy would reset rate limits and forget every
# spent ALTCHA solution, re-opening the replay window.
CACHE_DIRECTORY=%kernel.share_dir%/pools/app
CACHE_PREFIX_SEED=simple-feed-reader
```

- [ ] **Step 2: Wire them into `cache.yaml`**

In `backend/config/packages/cache.yaml`, inside `framework.cache` and above the existing
`pools:` key, add:

```yaml
        # Both are env-driven so a release-directory deployment can point them
        # at storage that outlives a single release. See backend/.env.
        prefix_seed: '%env(CACHE_PREFIX_SEED)%'
        directory: '%env(resolve:CACHE_DIRECTORY)%'
```

Leave the commented-out `#prefix_seed:` line and the whole `pools:` block exactly as they are.

- [ ] **Step 3: Verify the container still builds and the value resolves**

```bash
cd backend && php bin/console cache:clear && php bin/console debug:config framework cache | head -8
```

Expected: no error, and the dumped config shows `prefix_seed: simple-feed-reader` and a
`directory` ending in `/pools/app`.

- [ ] **Step 4: Confirm the pools still work**

```bash
cd backend && vendor/bin/phpunit --filter Altcha
```

Expected: PASS. The ALTCHA replay tests exercise a real pool, so a broken directory or
namespace shows up here.

- [ ] **Step 5: Run the backend gates**

```bash
cd backend && composer check && composer md
```

Expected: PHPCS, PHPStan, and phpmd all clean. (No PHP source changed — config only.)

- [ ] **Step 6: Commit**

```bash
git add backend/config/packages/cache.yaml backend/.env
git commit -m "config(cache): make pool directory and prefix seed env-driven (#73)

Symfony's defaults put the filesystem pools inside var/cache and seed the
key namespace with the project dir. Under a releases/<tag> deployment that
resets every rate limit and forgets every spent ALTCHA solution on each
deploy. The committed defaults reproduce the previous behaviour exactly."
```

---

## Task 4: Write the Apache `.htaccess`

The repo has only nginx configs today, because Docker serves the SPA from nginx and proxies
`/api` to php-fpm. Apache needs the same split expressed as rewrite rules, in one directory
that holds both `index.php` and `index.html`.

Route prefixes were enumerated from `debug:router`: **36 routes under `/api`, one under
`/maintenance`** (the refresh endpoint), plus `/_error` which is dev-only. Both real prefixes
must reach Symfony.

**Files:**
- Create: `deploy/strato/.htaccess`

- [ ] **Step 1: Create the file**

Create `deploy/strato/.htaccess`:

```apache
# One directory serves two things: Symfony's front controller for the API and
# the built Angular bundle for everything else. This file is copied into the
# release's public/ during assembly.

# Pinned deliberately. index.php and index.html share this directory, and if
# the server's default index order puts index.php first, a bare request for
# /reader/ reaches Symfony -- which has no route for "/" and answers 404
# instead of serving the app.
DirectoryIndex index.html

<IfModule mod_negotiation.c>
    # Without this, a request for /reader/index could be content-negotiated
    # onto index.php and bypass the rules below.
    Options -MultiViews
</IfModule>

# The directory is reached through a symlink from the portfolio docroot.
Options +FollowSymLinks

# The rewrite rules below serve anything that exists on disk, which would
# include this file. Apache's stock config usually denies .ht* already, but
# this docroot is a symlink into a deploy-assembled release tree, so do not
# rely on the parent's defaults.
<IfModule mod_authz_core.c>
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>
</IfModule>

<IfModule mod_headers.c>
    # The shell names the content-hashed bundles, so a cached copy pins the
    # browser to the previous release's JavaScript no matter how the assets
    # are named. The assets themselves are hashed and may cache freely.
    <Files "index.html">
        Header set Cache-Control "no-cache, must-revalidate"
    </Files>
</IfModule>

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Derive the path this app is mounted at, so no rule below has to hardcode
    # "/reader". Compares the request URI against the path mod_rewrite matched
    # and keeps the difference in ENV:BASE.
    #
    # Do NOT "simplify" this to a hardcoded RewriteBase. It looks redundant and
    # is not: it is what keeps every rule below independent of where the app is
    # mounted, and a hardcoded base would keep working right up until that path
    # changes.
    RewriteCond %{REQUEST_URI}::$0 ^(/.+)/(.*)::\2$
    RewriteRule ^(.*) - [E=BASE:%1]

    # Symfony owns /api (36 routes) and /maintenance (the refresh endpoint).
    # Tested first so neither the static-file rule nor the SPA fallback can
    # claim them.
    RewriteRule ^(api|maintenance)(/.*)?$ %{ENV:BASE}/index.php [L]

    # Anything that exists on disk -- hashed bundles, i18n dictionaries,
    # favicons -- is served as it is.
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Everything else is an Angular client-side route: hand it the shell and
    # let the router sort it out.
    RewriteRule ^ %{ENV:BASE}/index.html [L]
</IfModule>
```

- [ ] **Step 2: Commit**

```bash
git add deploy/strato/.htaccess
git commit -m "feat(deploy): Apache rules splitting one docroot between API and SPA (#73)"
```

---

## Task 5: Write the release build script

Runs on the GitHub runner. Builds both halves and assembles the exact tree that will be
rsynced. Composer runs with `--no-scripts` because the auto-scripts call `cache:clear`, which
would need production environment variables the runner does not have — the cache is warmed on
the server instead, after `.env.local` is linked.

**Files:**
- Create: `deploy/strato/build-release.sh`

- [ ] **Step 1: Create the script**

Create `deploy/strato/build-release.sh`:

```bash
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
restore_dev_dependencies() {
    local status=$?
    echo "==> Restoring dev dependencies in backend/vendor" >&2
    composer install --working-dir="${ROOT}/backend" --no-interaction --no-progress >&2 \
        || echo "!!! restore failed -- run: composer install --working-dir=${ROOT}/backend" >&2
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
    trap restore_dev_dependencies EXIT
fi
# --no-scripts: the auto-scripts are cache:clear and assets:install. Neither can
# reach the release -- they write to backend/var/, which is never copied -- but
# both run against the runner's environment, dirtying the working tree for no
# gain and failing confusingly when a production variable is absent. Activation
# warms the cache on the server, where the real environment exists.
composer install \
    --working-dir="${ROOT}/backend" \
    --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

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
for item in bin config migrations public src vendor composer.json composer.lock .env; do
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

echo "==> Release assembled"
du -sh "${OUT}"
```

- [ ] **Step 2: Make it executable**

```bash
chmod +x deploy/strato/build-release.sh
```

- [ ] **Step 3: Run it and verify the assembled tree**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
./deploy/strato/build-release.sh /tmp/sfr-release-test
ls /tmp/sfr-release-test/public/ | head
grep -o '<base href="[^"]*"' /tmp/sfr-release-test/public/index.html
find /tmp/sfr-release-test -name '*.pem'
ls backend/vendor/bin/phpunit
```

Expected: the script prints "Release assembled" with a size; `public/` contains `index.php`,
`index.html`, `.htaccess`, and hashed bundle files; the base href is `/reader/`. The `find`
must print **nothing** -- a developer's own JWT private key sits in `backend/config/jwt/` and
is gitignored precisely because it must never leave the machine. And `phpunit` must still be
there: the script strips dev dependencies to build, then restores them on the way out.

- [ ] **Step 4: Commit**

```bash
git add deploy/strato/build-release.sh
git commit -m "feat(deploy): script to build and assemble a Strato release (#73)"
```

---

## Task 6: Write the release activation script

Runs **on the server**, over SSH, against an already-uploaded release directory. Links the
shared state, warms the cache, migrates, and flips `current` last so a failure anywhere before
the flip leaves the live site untouched.

**Files:**
- Create: `deploy/strato/activate-release.sh`

- [ ] **Step 1: Create the script**

Create `deploy/strato/activate-release.sh`:

```bash
#!/usr/bin/env bash
# Activate an uploaded release. Runs ON the Strato host.
#
# The flip is the last thing that happens: migrations run against the new code
# while `current` still points at the old release, so a failure leaves the live
# site serving the previous version.
#
# Usage: activate-release.sh <deploy-root> <release-name>
set -euo pipefail

die() { echo "$*" >&2; exit 1; }

ROOT="${1:?usage: activate-release.sh <deploy-root> <release-name>}"
NAME="${2:?usage: activate-release.sh <deploy-root> <release-name>}"

# `ln` writes ${RELEASE} into `current` verbatim, and that string is resolved
# later, relative to the directory the link lives in -- the deploy root. A
# relative ROOT would therefore produce a dangling `current` and an instantly
# dead site, so normalize it before anything is built out of it.
ROOT="$(cd "${ROOT}" 2>/dev/null && pwd -P)" || die "no such deploy root: ${1}"

# A release name is one path segment. Anything else would let `..` walk the
# `rm -rf` below out of the release directory and aim the flip at a parent.
case "${NAME}" in
    */*|.|..) die "release name must be a single path segment: ${NAME}" ;;
esac

RELEASE="${ROOT}/releases/${NAME}"
SHARED="${ROOT}/shared"

# An absolute vendor path, not the `php84` on PATH, because the two are not the
# same SAPI. `php84` is a symlink to the cgi-fcgi binary: it has to be spelled
# `-q -f <script> -- <args>` (the -q suppresses HTTP headers, the -- stops the
# SAPI swallowing the first dash-prefixed argument), it chdir()s into the
# script's directory, and its ini caps max_execution_time at 240 seconds. None
# of that is wanted here -- least of all the ceiling, which is enough to kill a
# migration on a table with real data.
#
# /opt/RZphp84/bin/php-cli is the same PHP 8.4.22 build, differing only in SAPI.
# Measured on the host 2026-07-25: max_execution_time 0 (no ceiling), memory
# limit 512M as with cgi-fcgi, every extension this app requires present, empty
# disable_functions, date.timezone UTC, ordinary argument passing, and exit
# codes propagate. The path shape is a Strato convention rather than a one-off:
# php-cli exists identically under /opt/RZphp82, RZphp83, RZphp84 and RZphp85.
# It is simply not on PATH, which is the only reason it looks odd here.
#
# ${RELEASE}/bin/console stays absolute, for a different reason than before: the
# CLI does *not* chdir, it stays in the directory it was invoked from, and an
# SSH command starts in $HOME. ${RELEASE} is absolute anyway, by the
# normalization above.
PHP=/opt/RZphp84/bin/php-cli

console() { "${PHP}" "${RELEASE}/bin/console" "$@"; }

test -x "${PHP}" || die "no PHP CLI at ${PHP} -- if Strato has reorganised /opt, look for the new path with 'ls -d /opt/RZphp*/bin/php-cli' and update this script. The cgi-fcgi binary on PATH still works as a fallback, spelled: php84 -q -f ${RELEASE}/bin/console -- <command>"
test -d "${RELEASE}" || die "no such release: ${RELEASE}"
test -f "${SHARED}/.env.local" || die "missing ${SHARED}/.env.local"
test -f "${SHARED}/config/jwt/private.pem" || die "missing shared JWT key: ${SHARED}/config/jwt/private.pem"
test -f "${SHARED}/config/jwt/public.pem" || die "missing shared JWT key: ${SHARED}/config/jwt/public.pem"

# shared/.env.local is hand-written on the server, so its *contents* are as
# unverified as its existence. Two omissions there are silent and expensive,
# because backend/.env ships a working default for each and the deploy then
# succeeds with it.
#
# APP_ENV: the committed default is `dev`. Missing here, the cache is warmed for
# the dev environment and the site goes live in debug mode, serving stack traces
# -- with the database credentials in them -- to the public internet.
grep -Eq "^APP_ENV=['\"]?prod['\"]?[[:space:]]*$" "${SHARED}/.env.local" \
    || die "${SHARED}/.env.local does not set APP_ENV=prod -- the deploy would warm a dev cache and put the site live in debug mode, serving stack traces to the public"
# CACHE_DIRECTORY: the committed default is kernel-relative, so it resolves
# inside the release's own var/cache -- which `cache:clear` below wipes. Missing
# (or left kernel-relative) here, every deploy silently resets the filesystem
# pools: rate-limit counters, spent ALTCHA solutions, pending OAuth states and
# login codes. Requiring an absolute path is what rules out the kernel-relative
# default; that it points into shared/ is the operator's job.
grep -Eq "^CACHE_DIRECTORY=['\"]?/" "${SHARED}/.env.local" \
    || die "${SHARED}/.env.local does not set CACHE_DIRECTORY to an absolute path -- the filesystem cache pools would live inside the release, so every deploy would reset the rate-limit counters and the record of spent ALTCHA solutions (point it at ${SHARED}/var/cache-pools)"

echo "==> Linking shared state"
# Secrets. Never shipped in the release. Unguarded on purpose, unlike the two
# links below: `ln -sfn` over an existing *regular file* does the right thing,
# because -f unlinks it before creating the link. The trap is specific to the
# target already being a real directory.
ln -sfn "${SHARED}/.env.local" "${RELEASE}/.env.local"

# JWT keys. Shared because regenerating them per release would invalidate
# every issued token and silently log everyone out on each deploy.
rm -rf "${RELEASE}/config/jwt"
ln -sfn "${SHARED}/config/jwt" "${RELEASE}/config/jwt"

# Logs outlive a release; the cache directory does not.
#
# cache-pools is the counterpart: the filesystem pools (rate limiter, ALTCHA
# replay, OAuth state and login codes) are pointed here by CACHE_DIRECTORY in
# shared/.env.local, which is what keeps `cache:clear` below -- it only wipes
# the release's own var/cache -- from resetting them on every deploy.
mkdir -p "${SHARED}/var/log" "${SHARED}/var/cache-pools"
mkdir -p "${RELEASE}/var"
# `rm -rf` first for the same reason as config/jwt above. If ${RELEASE}/var/log
# already exists as a real directory, `ln -sfn` puts the link *inside* it --
# var/log/log -> shared/var/log -- and exits 0, and the release then writes its
# logs to a directory that vanishes with it. Nothing about that is visible in
# the deploy output. That the release currently arrives without var/ at all is
# a property of build-release.sh's copy list, not something this script may
# assume. Re-running is safe: `rm -rf` on a symlink removes the link, not the
# shared directory it points at.
rm -rf "${RELEASE}/var/log"
ln -sfn "${SHARED}/var/log" "${RELEASE}/var/log"

echo "==> Warming the cache"
# Clear first so a re-run against a release whose previous activation died
# mid-warmup cannot build on top of a half-written cache. One command, not two:
# cache:clear warms the cache itself unless --no-warmup is passed (Symfony 7.4,
# CacheClearCommand), so a following cache:warmup would buy a second container
# compile and nothing else.
console cache:clear --no-interaction

echo "==> Running migrations"
# Before the flip on purpose: if this fails, the old release is still live.
#
# It can fail halfway, and that is the expensive case. MySQL 8 commits
# implicitly on DDL, so Doctrine's per-migration transaction does not protect a
# migration that dies partway through: a statement the server rejects, a
# connection lost to the shared MySQL host, or the job being killed leaves a
# half-changed schema with no row in doctrine_migration_versions, and a blind
# retry then fails on something like "Duplicate column name".
#
# What is *no longer* one of those causes is a timeout. Under the CLI above
# max_execution_time is 0; the 240s ceiling belongs to the cgi-fcgi SAPI this
# script deliberately does not use. The implicit-commit hazard is unchanged --
# this script cannot make DDL transactional; all it can do is refuse to fail
# mutely.
migrate_status=0
console doctrine:migrations:migrate --no-interaction --allow-no-migration || migrate_status=$?
if [ "${migrate_status}" -ne 0 ]; then
    {
        echo "!!! Migrations failed (exit ${migrate_status})."
        echo "!!! current was NOT flipped: ${ROOT}/current still points at the previous"
        echo "!!! release, and that release is still serving the site."
        echo "!!! The schema may be partially migrated -- MySQL commits DDL implicitly, so"
        echo "!!! statements from a migration that died partway through can be applied"
        echo "!!! without the migration being recorded as executed."
        echo "!!! Check what actually ran before retrying the deploy:"
        echo "!!!   ${PHP} ${RELEASE}/bin/console doctrine:migrations:status"
    } >&2
    exit "${migrate_status}"
fi

echo "==> Flipping current"
# Two steps, because `ln -sfn` over an existing link is unlink() then symlink():
# for the instant in between, `current` does not exist and in-flight requests
# resolve a dangling path. Creating the link beside it and renaming it over the
# old one is a single rename(2), which is atomic -- there is no moment at which
# `current` is missing. -T also makes this fail loudly rather than nest a link
# inside it, should `current` ever be a real directory.
ln -sfn "${RELEASE}" "${ROOT}/current.tmp"
mv -Tf "${ROOT}/current.tmp" "${ROOT}/current"

echo "==> Active release is now ${NAME}"
```

- [ ] **Step 2: Make it executable**

```bash
chmod +x deploy/strato/activate-release.sh
```

- [ ] **Step 3: Check it parses**

```bash
bash -n deploy/strato/activate-release.sh && echo "syntax OK"
```

Expected: `syntax OK`. (It cannot be run locally — it targets the host's layout and `php84`.)

- [ ] **Step 4: Commit**

```bash
git add deploy/strato/activate-release.sh
git commit -m "feat(deploy): server-side release activation with a last-step flip (#73)"
```

---

## Task 7: Write the production environment template and runbook

**Files:**
- Create: `deploy/strato/.env.local.example`
- Create: `deploy/strato/README.md`

- [ ] **Step 1: Create the environment template**

Create `deploy/strato/.env.local.example`:

```dotenv
# Production environment for the maintainer's personal Strato deployment.
#
# Copy this to shared/.env.local ON THE SERVER and fill in the real values
# there. This file documents NAMES ONLY: every secret below ends in `=` with
# nothing after it, and it must stay that way -- the file is committed.
#
# Symfony loads .env first and .env.local second, so anything set here wins
# over the committed defaults in backend/.env. Anything NOT set here silently
# keeps its committed default, which is why the variables below are listed
# even where the default happens to be harmless: the dangerous ones are
# dangerous precisely because they work.
#
# activate-release.sh refuses to activate a release unless this file sets
# APP_ENV=prod and points CACHE_DIRECTORY at an absolute path. See the
# runbook for why those two, and only those two, are enforced.

APP_ENV=prod
# Redundant on paper -- Dotenv derives 0 from APP_ENV=prod -- but symfony/runtime
# reads it directly, so pinning it costs nothing and beats an inherited export.
APP_DEBUG=0
# Generate once, on your own machine: openssl rand -hex 16
# That is exactly what Symfony Flex generates (32 hex characters from 16 random
# bytes). Any non-empty string works; this one is the convention.
#
# Unlike everything else here, leaving this empty breaks nothing today: its only
# consumer is framework.secret, and this app has no CSRF tokens, no UriSigner
# and no remember-me cookie to derive a key for. Set it anyway. It is also the
# only variable whose committed default is empty, so an unedited copy of this
# file is indistinguishable from a deliberate one -- and the day something here
# does start deriving keys from it, that would be a silent, shared, public
# secret rather than a loud missing one.
APP_SECRET=

# The hosting package's MySQL database. The database name and the host are not
# secret; the password is. The server is MySQL 8 -- confirm the exact
# serverVersion after the first successful connect, because Doctrine picks its
# platform (and therefore the SQL its migrations emit) from this string.
DATABASE_URL="mysql://dbs15919276:PASSWORD@database-5020972012.webspace-host.com:3306/dbs15919276?serverVersion=8.0&charset=utf8mb4"

# The host's local MTA -- no credentials needed. See deploy/strato/README.md. The username is
# the full mailbox address. If the password contains any of : / ? # [ ] @ it
# MUST be percent-encoded here, or the DSN parses into a different host and the
# failure looks like a credentials problem.
#
# Leaving this at the committed default (null://null) is a hard error in prod:
# InsecureProductionConfigGuard refuses every request, because null:// discards
# mail and reports success -- registration would 202 with no verification mail
# and nothing would log a thing.
MAILER_DSN=sendmail://default

# The From: header on account mail. The committed default is example.com, which
# a real transport will either reject or deliver straight to spam. Strato
# requires the sender to be the mailbox you authenticated as.
MAIL_FROM=simplefeedreader@lars-pohlmann.de
MAIL_FROM_NAME="Simple Feed Reader"

# Both point at the mount, not the domain root: the app lives under /reader.
# AccountMailer concatenates APP_FRONTEND_URL with /verify-email and friends,
# and the OAuth controller redirects beneath it. APP_BACKEND_URL is what builds
# the redirect URI handed to the identity providers, so it must match what is
# registered with them byte for byte.
#
# APP_FRONTEND_URL is also the single allowed CORS origin -- CorsListener
# reduces it to scheme://host and echoes that, never the request's own Origin.
# There is no separate CORS variable. Here the SPA and the API share an origin,
# so the policy is inert; get this URL wrong and the SPA breaks before CORS
# ever becomes the interesting part.
APP_FRONTEND_URL=https://lars-pohlmann.de/reader
APP_BACKEND_URL=https://lars-pohlmann.de/reader

# Used only to generate absolute URLs outside an HTTP request (console
# commands). Nothing does that today; set it anyway so the day something does,
# it does not emit http://localhost links.
DEFAULT_URI=https://lars-pohlmann.de/reader

# Signs ALTCHA challenges, and is the ONLY thing making one unforgeable.
# backend/.env ships a known placeholder in a public repository: with that in
# place anyone computes a valid solution in one hash instead of ~150k, and the
# proof-of-work on /register and /password-reset-request is void while both
# endpoints keep answering 200. InsecureProductionConfigGuard therefore refuses
# to serve prod traffic at all while the placeholder is still here -- every
# request 500s until this is set.
#
# Generate once: openssl rand -hex 32
ALTCHA_HMAC_KEY=

# The passphrase for shared/config/jwt/private.pem, which activation symlinks
# into each release. If it contains a `#` or a space, quote it -- dotenv treats
# an unquoted `#` as the start of a comment, and the resulting error is
# "unable to load private key", not "your passphrase was truncated".
JWT_PASSPHRASE=

# Google sign-in. The redirect URI registered in the Google Cloud console must
# be exactly:
#   https://lars-pohlmann.de/reader/api/auth/oauth/google/callback
# Leave both blank to disable Google sign-in; the provider registry then
# reports it as unconfigured rather than failing at the callback.
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=

# Apple sign-in stays off. A blank Services ID disables that leg entirely, so
# APPLE_OAUTH_TEAM_ID, APPLE_OAUTH_KEY_ID and APPLE_OAUTH_PRIVATE_KEY are left
# to their (empty) committed defaults and are deliberately not listed here.
APPLE_OAUTH_CLIENT_ID=''

# Shared token for POST /maintenance/refresh, the only way to refresh feeds
# without a logged-in browser. There is no scheduler yet, and an empty token is
# fail-closed -- MaintenanceController refuses every call before comparing
# anything -- so leaving this blank is a safe default and NOT an oversight.
# Fill it in (openssl rand -hex 32) on the day a pinger exists.
MAINTENANCE_TOKEN=

# The filesystem cache pools -- rate limiter, spent ALTCHA solutions, pending
# OAuth states, login codes -- must outlive a release. The committed default is
# kernel-relative, so it resolves inside the release's own var/cache, which
# activation wipes: every deploy would reset the rate limits and re-open the
# ALTCHA replay window. An absolute path into shared/ is what fixes that, and
# activate-release.sh refuses to activate without one.
CACHE_DIRECTORY=/mnt/web319/b2/38/59606538/htdocs/simplefeedreader/shared/var/cache-pools

# A literal, not Symfony's default. Symfony seeds the namespace with the
# project directory, which changes with every release, so the pools would be
# renamespaced on each deploy even after CACHE_DIRECTORY is shared. This must
# stay byte-identical across releases; changing it is a one-time cold start of
# all four pools.
CACHE_PREFIX_SEED=simple-feed-reader
```

- [ ] **Step 2: Create the runbook**

Create `deploy/strato/README.md`:

The runbook shipped as `deploy/strato/README.md`. It is deliberately not reproduced here:
it grew well past the draft during review -- gaining the admin-bootstrap step, the
placeholder-`current` removal, the deploy-root skeleton, the hand-deploy path, and the
real-CLI console form -- and a second copy in this document would only rot. Read the file.

- [ ] **Step 3: Verify no real secrets are present**

```bash
grep -nE "(PASSWORD|SECRET|PASSPHRASE)=.+" deploy/strato/.env.local.example
```

Expected: no output except the `DATABASE_URL`/`MAILER_DSN` lines containing the literal
placeholders `USER:PASSWORD` — every real secret key must end in `=` with nothing after it.

- [ ] **Step 4: Commit**

```bash
git add deploy/strato/.env.local.example deploy/strato/README.md
git commit -m "docs(deploy): production env template and Strato runbook (#73)"
```

---

## Task 8: Write the GitHub Actions workflow

Deploys on every push or merge to `develop`, gated on CI passing. Thin on purpose: it sets up
toolchains, calls the two scripts, and moves files between them.

Two `workflow_run` behaviours drive the shape of this file, and getting either wrong breaks
the deploy silently:

- It fires **only when the workflow file is on the default branch** (`main` here). Under
  git-flow this branch merges to `develop` first, so automatic deploys stay dormant until
  `develop` reaches `main`. `workflow_dispatch` covers the interim.
- It checks out the **default branch** by default, not the commit CI ran on. The checkout
  must pin `github.event.workflow_run.head_sha` or every deploy would ship `main`.

**Files:**
- Modify: `.github/workflows/ci.yml:4-6` (add `develop` to the push branches)
- Create: `.github/workflows/deploy-strato.yml`

- [ ] **Step 1: Make CI run on `develop`**

CI currently triggers on `push` to `main` and on `pull_request`, so nothing runs on a merge
into `develop` — there would be no CI result to gate the deploy on. In
`.github/workflows/ci.yml`, change:

```yaml
on:
  push:
    branches: [main]
  pull_request:
```

to:

```yaml
on:
  push:
    branches: [main, develop]
  pull_request:
```

- [ ] **Step 2: Create the workflow**

Create `.github/workflows/deploy-strato.yml`:

```yaml
# Personal deployment to the maintainer's Strato shared hosting.
# Not part of CI and not needed by anyone else working on this project --
# see deploy/strato/README.md.
#
# Runs after CI succeeds on develop, so develop is continuously deployed and a
# red build never reaches production. Thin on purpose: it sets up the two
# toolchains the server does not have, calls deploy/strato/build-release.sh and
# deploy/strato/activate-release.sh, and moves files between them.
#
# NOTE: workflow_run only fires when this file is on the DEFAULT branch (main).
# Until develop is merged to main, deploy by hand with workflow_dispatch.
name: Deploy (Strato)

on:
  workflow_run:
    workflows: ['CI']
    types: [completed]
    branches: [develop]
  workflow_dispatch:

# One deploy at a time. Two overlapping runs could interleave an rsync with
# another release's symlink flip. Never cancel a run in progress: the dangerous
# moment is between "migrations started" and "current flipped", and killing the
# job there leaves a migrated database in front of the old code with no record
# of how far it got. A superseded *pending* run is dropped by GitHub anyway, so
# nothing older than the head of the queue ever reaches the host.
concurrency:
  group: deploy-strato
  cancel-in-progress: false

# This job talks to the Strato host over SSH and to the GitHub API not at all.
# `contents: read` is what actions/checkout needs and nothing else is granted.
permissions:
  contents: read

jobs:
  deploy:
    name: Build and deploy
    runs-on: ubuntu-latest
    timeout-minutes: 30
    # Deploy only a green build of our own code. A manual dispatch is trusted on
    # its own. A workflow_run must clear three bars, not one:
    #   conclusion  -- the event fires on failure and cancellation too.
    #   event       -- CI also runs on pull_request, and the branch filter above
    #                  matches the *head* branch. A pull request opened from a
    #                  fork whose branch happens to be named `develop` would
    #                  otherwise reach this job, which runs with the deploy
    #                  secrets and executes the branch's own build scripts.
    #   head_repository -- belt and braces for the same attack.
    if: >-
      github.repository == 'larspohlmann/simple-feed-reader' &&
      (github.event_name == 'workflow_dispatch' ||
       (github.event.workflow_run.conclusion == 'success' &&
        github.event.workflow_run.event == 'push' &&
        github.event.workflow_run.head_repository.full_name == github.repository))

    steps:
      # workflow_run checks out the DEFAULT branch unless told otherwise, so
      # pin the commit CI actually tested -- otherwise every deploy ships main.
      # Nothing here needs git write access, so the token is not left in
      # .git/config for the build steps to trip over.
      - uses: actions/checkout@v5
        with:
          ref: ${{ github.event.workflow_run.head_sha || github.ref }}
          persist-credentials: false

      # Two seconds here rather than a confusing failure three minutes in. The
      # value is interpolated into remote command lines below, where it is
      # single-quoted: that survives spaces but not a single quote of its own.
      # Relative paths are refused because `current` stores an absolute target
      # and an SSH command starts in $HOME, not in the deploy root.
      - name: Check the deploy target
        env:
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
        run: |
          set -euo pipefail
          # The value is a secret, so it is never echoed -- the message says
          # what is wrong with it, not what it is.
          die() { echo "!!! STRATO_DEPLOY_PATH $1" >&2; exit 1; }
          [ -n "${DEPLOY_PATH}" ] || die "is empty"
          [ "${DEPLOY_PATH#/}" != "${DEPLOY_PATH}" ] || die "is not an absolute path"
          [ "$(printf '%s' "${DEPLOY_PATH}" | wc -l)" -eq 0 ] || die "contains a newline"
          case "${DEPLOY_PATH}" in
            *\'*) die "contains a single quote, which the remote commands below cannot quote" ;;
          esac

      # Everything the later steps need to reach the host lives in ~/.ssh/config
      # under the alias `strato`, so no step has to repeat the connection
      # details and neither the host nor the user appears on a command line.
      # printf rather than a heredoc: the values are secrets and must land in
      # the file verbatim, not as something the shell got a second look at.
      - name: Configure SSH
        env:
          SSH_HOST: ${{ secrets.STRATO_SSH_HOST }}
          SSH_USER: ${{ secrets.STRATO_SSH_USER }}
          SSH_KEY: ${{ secrets.STRATO_SSH_KEY }}
          KNOWN_HOSTS: ${{ secrets.STRATO_KNOWN_HOSTS }}
        run: |
          set -euo pipefail
          umask 077
          mkdir -p ~/.ssh
          chmod 700 ~/.ssh
          # printf, not echo: `echo` mangles a value beginning with -n or -e,
          # and the trailing \n is what saves a key stored without one --
          # OpenSSH rejects a private key whose final line is unterminated.
          printf '%s\n' "${SSH_KEY}" > ~/.ssh/deploy_key
          printf '%s\n' "${KNOWN_HOSTS}" > ~/.ssh/known_hosts
          chmod 600 ~/.ssh/deploy_key ~/.ssh/known_hosts
          {
            printf 'Host strato\n'
            printf '  HostName %s\n' "${SSH_HOST}"
            printf '  User %s\n' "${SSH_USER}"
            printf '  IdentityFile ~/.ssh/deploy_key\n'
            printf '  UserKnownHostsFile ~/.ssh/known_hosts\n'
            # IdentitiesOnly stops the agent offering other keys and burning
            # the server's MaxAuthTries before ours is tried; StrictHostKeyChecking
            # plus BatchMode turn an unknown host into a failure rather than a
            # prompt that would hang the job until the 30-minute timeout.
            printf '  IdentitiesOnly yes\n'
            printf '  StrictHostKeyChecking yes\n'
            printf '  BatchMode yes\n'
            printf '  ConnectTimeout 20\n'
            # A migration can legitimately run for minutes on a shared host, but
            # a connection that has silently died should not hold the job open.
            printf '  ServerAliveInterval 15\n'
            printf '  ServerAliveCountMax 8\n'
          } > ~/.ssh/config
          chmod 600 ~/.ssh/config

      # Fails before the build rather than after it if the key, the host key, or
      # the account is wrong.
      - name: Check the SSH connection
        run: ssh strato true

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: intl, pdo_sqlite, pdo_mysql
          coverage: none

      - name: Set up Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: frontend/package-lock.json

      # Node 22 ships npm 10.9.8, which mis-resolves chokidar and rejects the
      # npm-11-authored lockfile. Same pin as the CI workflow.
      - name: Pin npm 11 (matches the lockfile author)
        run: npm i -g npm@11

      # No tag to name a release after, so use a sortable UTC timestamp plus the
      # short SHA of the commit being deployed. The name is validated because it
      # becomes a path segment on the server, an argument to the activation
      # script, and the thing the prune step below reasons about.
      - name: Name the release
        run: |
          set -euo pipefail
          : "${RUNNER_TEMP:?RUNNER_TEMP is unset}"
          sha="$(git rev-parse --short HEAD)"
          name="$(date -u +%Y%m%d%H%M%S)-${sha}"
          case "${name}" in
            [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9a-f]*) ;;
            *) echo "!!! refusing to deploy under the release name '${name}'" >&2; exit 1 ;;
          esac
          echo "RELEASE_NAME=${name}" >> "$GITHUB_ENV"
          echo "RELEASE_DIR=${RUNNER_TEMP}/release" >> "$GITHUB_ENV"
          echo "==> Release ${name}"

      # Installs production dependencies, builds the SPA with the /reader base
      # href, assembles the tree, and sanity-checks it. The script's EXIT trap
      # that restores dev dependencies is skipped here because GitHub Actions
      # sets CI=true for every step; it would be harmless on this throwaway
      # checkout anyway.
      - name: Build the release
        run: ./deploy/strato/build-release.sh "${RELEASE_DIR}"

      # Travels with the release instead of being uploaded to a fixed path of
      # its own: one transfer fewer, no chance of overwriting the copy a
      # concurrent run is executing, and a release on disk stays activatable
      # later by exactly the script it was built against.
      - name: Add the activation script to the release
        run: cp deploy/strato/activate-release.sh "${RELEASE_DIR}/activate-release.sh"

      # No --delete: the destination is a fresh directory every time (the name
      # carries a timestamp), so it would have nothing to delete -- while an
      # empty or malformed RELEASE_NAME would aim it at releases/ itself and
      # empty the lot. The name is validated above and the source is checked
      # here; --delete would still be a loaded gun for no gain.
      #
      # The remote path is deliberately unquoted, unlike the ssh commands below.
      # Since 3.2.4 rsync backslash-escapes shell-active characters in a remote
      # argument itself (the runner has 3.2.7), so a space in DEPLOY_PATH is
      # handled for us and quotes of our own would become part of the path.
      - name: Upload the release
        env:
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
        run: |
          set -euo pipefail
          test -f "${RELEASE_DIR}/public/index.php" \
            || { echo "!!! ${RELEASE_DIR} is not a built release" >&2; exit 1; }
          rsync -az --info=stats1 \
            "${RELEASE_DIR}/" \
            "strato:${DEPLOY_PATH}/releases/${RELEASE_NAME}/"

      # Links shared state, warms the cache, migrates, and flips `current` last.
      # A failure here leaves the previous release serving traffic, and ssh
      # returns the script's exit status, so the job goes red with it.
      #
      # Run through `bash` rather than chmod + execute: one round trip fewer and
      # one fewer thing to go wrong if the host ever mounts the webspace noexec.
      # Single quotes around the interpolated values are what makes a DEPLOY_PATH
      # containing a space survive the remote shell.
      - name: Activate the release
        env:
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
        run: |
          set -euo pipefail
          # shellcheck disable=SC2029  # expanding here is the point: the runner
          # knows the path and the release name, the server does not.
          ssh strato "bash '${DEPLOY_PATH}/releases/${RELEASE_NAME}/activate-release.sh' '${DEPLOY_PATH}' '${RELEASE_NAME}'"

      # Activation deliberately checks only the two .env.local settings whose
      # absence fails silently. Everything else -- ALTCHA_HMAC_KEY, MAILER_DSN,
      # a database that refuses the connection -- surfaces only once the app
      # serves a request, and InsecureProductionConfigGuard answers 500 to
      # every route when it is unhappy. Without this step that release would go
      # live and the deploy would report success. develop is continuously
      # deployed, so nobody is necessarily watching.
      #
      # Deliberately after the flip: the point is to exercise the release that
      # is actually serving. A failure here means the site is broken NOW and
      # wants a rollback, not that the deploy did not happen.
      - name: Smoke-test the live release
        run: |
          set -euo pipefail
          curl -fsS --retry 3 --retry-delay 5 --max-time 30 \
            https://lars-pohlmann.de/reader/api/health \
            || { echo "!!! ${RELEASE_NAME} is live but /api/health is not answering." >&2
                 echo "!!! Roll back per deploy/strato/README.md, then check .env.local:" >&2
                 echo "!!!   ALTCHA_HMAC_KEY and MAILER_DSN both 500 every route while unset." >&2
                 exit 1; }
          echo

      # Keep the five most recent releases so a rollback target always exists.
      #
      # The naive form of this (`ls -1t | tail -n +6 | xargs -r rm -rf`) decides
      # what to delete purely by modification time, and mtime is not the same
      # question as "which release is serving the site". A release directory's
      # mtime moves whenever anything is written into it, releases that were
      # uploaded but never activated stay on disk and are newer than the live
      # one, and a rollback makes `current` point at something that is not the
      # newest at all. Any of those can push the live release past position five
      # -- and deleting it takes the site down instantly, with the deploy
      # reporting success. So resolve `current` first and never delete its
      # target, whatever the sort order says. If `current` cannot be resolved,
      # nothing is deleted at all.
      #
      # This runs after the flip, so the new release is already live: a failure
      # in this step is a red build over a healthy site, not a failed deploy.
      - name: Prune old releases (the new release is already live)
        env:
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
        run: |
          set -euo pipefail
          # shellcheck disable=SC2029  # ditto: DEPLOY_PATH must expand here.
          # The script itself is quoted (<<'REMOTE') and expands on the server.
          ssh strato "bash -s -- '${DEPLOY_PATH}' 5" <<'REMOTE'
          set -eu
          root=$1
          keep=$2

          cd "$root/releases" || { echo "!!! no releases directory" >&2; exit 1; }

          # `cd` + `pwd -P` resolves a symlink and a real directory alike, and
          # fails on a dangling one -- which is the case where guessing would be
          # worst.
          live="$(cd "$root/current" 2>/dev/null && pwd -P)" || live=''
          if [ -z "$live" ]; then
            echo "!!! cannot resolve $root/current -- refusing to prune anything" >&2
            exit 1
          fi
          live="$(basename "$live")"
          echo "==> live release: $live"

          seen=0
          while IFS= read -r name; do
            [ -n "$name" ] || continue
            [ -d "$name" ] || continue
            seen=$((seen + 1))
            if [ "$seen" -le "$keep" ]; then
              continue
            fi
            if [ "$name" = "$live" ]; then
              echo "    keeping $name (it is live, despite being older than $keep others)"
              continue
            fi
            echo "    removing $name"
            rm -rf -- "./$name"
          done <<EOF
          $(ls -1t)
          EOF
          echo "==> pruned"
          REMOTE
```

- [ ] **Step 3: Verify the YAML parses**

```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/deploy-strato.yml')); print('YAML OK')"
```

Expected: `YAML OK`.

- [ ] **Step 4: Confirm the secret names match what exists**

```bash
gh secret list
```

Expected: `STRATO_SSH_HOST`, `STRATO_SSH_USER`, `STRATO_SSH_KEY`, `STRATO_KNOWN_HOSTS`,
`STRATO_DEPLOY_PATH` — every name referenced in the workflow.

- [ ] **Step 5: Confirm `STRATO_DEPLOY_PATH` points at the app directory**

The scripts assume `${DEPLOY_PATH}/releases`, `${DEPLOY_PATH}/shared`, and
`${DEPLOY_PATH}/current`. The secret was set on 2026-07-21 and its value cannot be read back,
so confirm the layout it must point at exists:

```bash
ssh strato-feedreader 'ls -d ~/simplefeedreader/releases ~/simplefeedreader/shared'
```

Expected: both directories listed. `STRATO_DEPLOY_PATH` must be that `simplefeedreader`
directory — as an absolute path, since the workflow interpolates it into remote commands where
`~` is not reliably expanded. If it differs, update the secret:

```bash
gh secret set STRATO_DEPLOY_PATH --body '/mnt/web319/b2/38/59606538/htdocs/simplefeedreader'
```

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/deploy-strato.yml .github/workflows/ci.yml
git commit -m "ci(deploy): deploy develop to Strato after CI passes (#73)

CI did not run on develop at all, so a merge there had no test result to
gate on. develop joins the push triggers, and the deploy waits on a
successful CI workflow_run rather than duplicating the suite.

workflow_run checks out the default branch unless told otherwise, so the
checkout pins the SHA CI actually tested."
```

---

## Task 9: Verify the isolation claim end to end

The whole point of the branch is that a person cloning this repository is unaffected. Prove it
rather than assert it.

**Files:** none modified.

- [ ] **Step 1: Confirm the Docker setup is untouched**

```bash
git diff develop...HEAD --name-only
```

Expected: no path under `docker/` and not `docker-compose.yml`. The list should be exactly the
files named in this plan's File structure section.

- [ ] **Step 2: Confirm the default frontend build still targets the root**

```bash
cd frontend && npx ng build && grep -o '<base href="[^"]*"' dist/frontend/browser/index.html
```

Expected: `<base href="/"`.

- [ ] **Step 3: Run both full gates**

```bash
cd frontend && npm run check
cd ../backend && composer check && composer md && vendor/bin/phpunit
```

Expected: all green. The only application-code change on this branch is the i18n path.

- [ ] **Step 4: Verify the Docker stack still comes up**

```bash
docker compose up -d && sleep 20 && curl -sk https://localhost:8443/api/health
```

Expected: a healthy JSON response. Then `docker compose down`.

- [ ] **Step 5: Commit any incidental fixes, then open the PR**

```bash
git push -u origin feature/73-strato-deployment
gh pr create --base develop --title "Personal STRATO deployment under /reader (#73)" --body "Implements docs/superpowers/specs/2026-07-25-strato-deployment-design.md

Serves the app at https://lars-pohlmann.de/reader, reusing the apex domain's
certificate (STRATO's free cert does not cover subdomains).

All personal tooling is isolated in deploy/strato/. The Docker setup, the
Angular production configuration, and the default root-path build are
untouched -- verified by Task 9.

Three shared-code changes, all with unchanged defaults:
- fix: the i18n loader used an absolute /i18n/ path that 404s under a subpath
- build: an additive 'strato' Angular configuration
- config: cache pool directory and prefix seed are now env-driven, so rate
  limits and spent ALTCHA solutions survive a deploy

Closes #73"
```

---

## After the merge

The code is only half of it. Work through **One-time setup** in `deploy/strato/README.md`
(panel steps, JWT keys, `.env.local`, the mount). Merging this branch into `develop` will not
deploy on its own — `workflow_run` needs the workflow on `main` first — so run the **Deploy
(Strato)** workflow manually the first time, then work through
**Verifying a deploy**. Close [#73](https://github.com/larspohlmann/simple-feed-reader/issues/73)
by hand — PRs merge into `develop`, so GitHub will not auto-close it.
