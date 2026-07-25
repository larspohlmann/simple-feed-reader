# Personal STRATO Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve the reader at `https://lars-pohlmann.de/reader` over the apex domain's existing certificate, deployed by an atomic symlink flip from GitHub Actions.

**Architecture:** The app is mounted at a subpath by symlinking it into the portfolio docroot. Symfony derives its base path from `SCRIPT_NAME` automatically, so no route changes are needed; Angular gets a `/reader/` base href from an additive build configuration. An Apache `.htaccess` splits one directory between Symfony's front controller (`/api`, `/maintenance`) and the SPA. Everything personal lives in `deploy/strato/`.

**Tech Stack:** Symfony 7.4 (PHP 8.4, cgi-fcgi SAPI), Angular 20, MySQL 8, Apache `.htaccess`, GitHub Actions, rsync over SSH.

**Spec:** `docs/superpowers/specs/2026-07-25-strato-deployment-design.md`
**Issue:** [#73](https://github.com/larspohlmann/simple-feed-reader/issues/73)

---

## Context an implementer needs

**The host.** STRATO shared hosting. PHP 8.4.22 in the **cgi-fcgi** SAPI — `php -r` does not
work; console commands are `php84 -q -f bin/console <cmd>`. There is no composer, no node,
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
| `deploy/strato/activate-release.sh` | Run on the server: link shared state, migrate, flip `current` |
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
# are written, and the namespace their keys live under. The defaults below
# reproduce Symfony's own defaults, so nothing changes for the Docker setup or
# for local development.
#
# A release-directory deployment MUST override both in .env.local: Symfony's
# real defaults resolve inside var/cache (per-release, and wiped by
# cache:clear) and seed the namespace with %kernel.project_dir% (which is the
# release path). Left alone, every deploy would reset rate limits and forget
# every spent ALTCHA solution, re-opening the replay window.
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

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Derive the path this app is mounted at, so no rule below has to hardcode
    # "/reader". Compares the request URI against the path mod_rewrite matched
    # and keeps the difference in ENV:BASE.
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
```

Expected: the script prints "Release assembled" with a size; `public/` contains `index.php`,
`index.html`, `.htaccess`, and hashed bundle files; the base href is `/reader/`.

- [ ] **Step 4: Restore local dev dependencies**

The script ran `composer install --no-dev` in the working tree, so the dev tooling is gone.

```bash
cd backend && composer install
```

Expected: PHPUnit, PHPStan and friends are back.

- [ ] **Step 5: Commit**

```bash
git add deploy/strato/build-release.sh
git commit -m "feat(deploy): script to build and assemble a Strato release (#73)"
```

---

## Task 6: Write the release activation script

Runs **on the server**, over SSH, against an already-uploaded release directory. Links the
shared state, migrates, warms the cache, and flips `current` last so a failure anywhere before
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

ROOT="${1:?usage: activate-release.sh <deploy-root> <release-name>}"
NAME="${2:?usage: activate-release.sh <deploy-root> <release-name>}"

RELEASE="${ROOT}/releases/${NAME}"
SHARED="${ROOT}/shared"

# The host runs PHP as cgi-fcgi, where -r is unavailable and -q suppresses the
# HTTP headers the SAPI would otherwise print before the command's output.
PHP="php84 -q -f"

test -d "${RELEASE}" || { echo "no such release: ${RELEASE}"; exit 1; }
test -f "${SHARED}/.env.local" || { echo "missing ${SHARED}/.env.local"; exit 1; }
test -f "${SHARED}/config/jwt/private.pem" || { echo "missing shared JWT keys"; exit 1; }

echo "==> Linking shared state"
# Secrets. Never shipped in the release.
ln -sfn "${SHARED}/.env.local" "${RELEASE}/.env.local"

# JWT keys. Shared because regenerating them per release would invalidate
# every issued token and silently log everyone out on each deploy.
rm -rf "${RELEASE}/config/jwt"
ln -sfn "${SHARED}/config/jwt" "${RELEASE}/config/jwt"

# Logs outlive a release; the cache directory does not.
mkdir -p "${SHARED}/var/log" "${SHARED}/var/cache-pools"
mkdir -p "${RELEASE}/var"
ln -sfn "${SHARED}/var/log" "${RELEASE}/var/log"

echo "==> Warming the cache"
cd "${RELEASE}"
${PHP} bin/console cache:clear --no-interaction
${PHP} bin/console cache:warmup --no-interaction

echo "==> Running migrations"
# Before the flip on purpose: if this fails, the old release is still live.
${PHP} bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "==> Flipping current"
ln -sfn "${RELEASE}" "${ROOT}/current"

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
# Production environment for the personal Strato deployment.
#
# Copy to shared/.env.local ON THE SERVER and fill in real values. This file
# documents names only -- never commit real secrets.

APP_ENV=prod
APP_DEBUG=0
# Generate once: openssl rand -hex 16
APP_SECRET=

# The hosting package's MySQL database, created in the Strato panel.
DATABASE_URL="mysql://USER:PASSWORD@HOST:3306/DBNAME?serverVersion=8.0&charset=utf8mb4"

# Strato SMTP, using the noreply@ mailbox created in the panel.
MAILER_DSN=smtp://USER:PASSWORD@smtp.strato.de:587

# Both point at the mount, not the domain root: the app lives under /reader.
# The mailer concatenates this with /verify-email etc., and the OAuth
# controller redirects to /auth/callback beneath it.
APP_FRONTEND_URL=https://lars-pohlmann.de/reader
APP_BACKEND_URL=https://lars-pohlmann.de/reader

# JWT keys live in shared/config/jwt/ and are symlinked into each release.
JWT_PASSPHRASE=

# Google sign-in. The redirect URI registered in the Google Cloud console must
# be exactly:
#   https://lars-pohlmann.de/reader/api/auth/oauth/google/callback
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=

# Apple sign-in stays off: a blank client id disables that leg entirely.
APPLE_OAUTH_CLIENT_ID=''

# Filesystem cache pools must outlive a release, or every deploy resets the
# rate limits and forgets spent ALTCHA solutions. Absolute path into shared/.
CACHE_DIRECTORY=/mnt/web319/b2/38/59606538/htdocs/simplefeedreader/shared/var/cache-pools
CACHE_PREFIX_SEED=simple-feed-reader
```

- [ ] **Step 2: Create the runbook**

Create `deploy/strato/README.md`:

````markdown
# Personal Strato deployment

The maintainer's own deployment of this app to STRATO shared hosting. **It is not the
supported way to run the project** — use the Docker setup in the repository root for that.
Nothing here is required to develop or run the app.

The app is served at <https://lars-pohlmann.de/reader>. It is mounted at a subpath rather
than a subdomain because STRATO's free certificate covers only the apex domain and `www`,
and the apex is already certified.

## How it fits together

```
~/larspohlmann/reader  ->  ~/simplefeedreader/current/public
~/simplefeedreader/
  releases/<tag>/            one directory per deploy
  current -> releases/<tag>  flipped atomically, last
  shared/
    .env.local               secrets
    config/jwt/              JWT keypair
    var/log/
    var/cache-pools/         rate limiter + ALTCHA replay
```

Symfony derives its base path from `SCRIPT_NAME`, so routes need no prefix. Angular gets
`/reader/` from the `strato` build configuration. `public/.htaccess` sends `/api` and
`/maintenance` to `index.php`, serves real files as they are, and falls back to `index.html`.

## One-time setup

1. **MySQL database** — create it in the Strato panel; put the DSN in `shared/.env.local`.
2. **Mailbox** — create `noreply@lars-pohlmann.de`; put its SMTP credentials in the same file.
3. **PHP version** — set the vhost to **PHP 8.4** in the panel. Reader mode needs it
   (readability.php v4). The CLI already resolves `php84`; the web vhost is separate.
4. **Google OAuth** — register the redirect URI exactly:
   `https://lars-pohlmann.de/reader/api/auth/oauth/google/callback`
5. **Subdomain** — point `reader.lars-pohlmann.de` at `https://lars-pohlmann.de/reader`.
   That redirect travels over plain HTTP (the subdomain has no certificate); it is a
   convenience for old links, not a secure entry point.
6. **JWT keys** — generate them and place them in `shared/config/jwt/`:

   ```bash
   ssh strato-feedreader 'mkdir -p ~/simplefeedreader/shared/config/jwt'
   # locally, then upload:
   openssl genpkey -out private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
   openssl pkey -in private.pem -out public.pem -pubout
   scp private.pem public.pem strato-feedreader:~/simplefeedreader/shared/config/jwt/
   ```

   The passphrase goes in `JWT_PASSPHRASE`.
7. **Environment** — copy `.env.local.example` to `shared/.env.local` and fill it in.
8. **Mount** — link the app into the portfolio docroot:

   ```bash
   ssh strato-feedreader 'ln -sfn ~/simplefeedreader/current/public ~/larspohlmann/reader'
   ```

## Deploying

**`develop` is continuously deployed.** Every push or merge to `develop` runs CI, and if CI
passes the deploy workflow builds both halves on the runner, uploads the release, migrates,
and flips `current`. Migrations run **before** the flip, so a failed migration leaves the
previous release live.

Nothing to do by hand — merge to `develop` and it ships.

Two caveats:

- `workflow_run` only fires when the deploy workflow is on the **default branch** (`main`).
  Until `develop` has been merged to `main` once, automatic deploys do not happen.
- To deploy on demand at any time, run the **Deploy (Strato)** workflow manually from the
  Actions tab (`workflow_dispatch`).

## Rolling back

```bash
ssh strato-feedreader 'ls ~/simplefeedreader/releases'
ssh strato-feedreader 'ln -sfn ~/simplefeedreader/releases/<previous> ~/simplefeedreader/current'
```

No rebuild needed. Down-migrations are not part of this setup: if a release migrated the
schema, rolling back the code does not roll back the database.

## Verifying a deploy

1. <https://lars-pohlmann.de/reader> loads over a valid certificate.
2. `https://lars-pohlmann.de/reader/api/health` responds.
3. A client-side route survives a browser reload (proves the SPA fallback).
4. Register → verification email arrives → verify → approve.
5. Google sign-in completes and lands back under `/reader`.
6. Subscribe to a feed, refresh, open an article, switch to reader mode
   (this is what proves PHP 8.4 on the vhost).
7. <https://lars-pohlmann.de/> and `/de/` still work — the mount must not disturb them.
8. Deploy again: logins survive (shared JWT keys) and rate-limit state survives
   (shared cache pools).

## Notes

- There is **no scheduled refresh**. Feeds update when someone presses refresh.
- The server has no composer, node, or crontab; everything is built on the runner.
- The host's PHP is cgi-fcgi: `php -r` does not work. Use `php84 -q -f bin/console <cmd>`.
````

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
# red build never reaches production.
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
# another release's symlink flip.
concurrency:
  group: deploy-strato
  cancel-in-progress: false

jobs:
  deploy:
    name: Build and deploy
    runs-on: ubuntu-latest
    # Deploy only a green build. A manual dispatch is trusted on its own; a
    # workflow_run must report success, since the event fires on failure too.
    if: >-
      github.repository == 'larspohlmann/simple-feed-reader' &&
      (github.event_name == 'workflow_dispatch' ||
       github.event.workflow_run.conclusion == 'success')

    steps:
      # workflow_run checks out the DEFAULT branch unless told otherwise, so
      # pin the commit CI actually tested -- otherwise every deploy ships main.
      - uses: actions/checkout@v5
        with:
          ref: ${{ github.event.workflow_run.head_sha || github.ref }}

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

      # Node 22 ships npm 10.9.8, which mis-resolves chokidar and rejects the
      # npm-11-authored lockfile. Same pin as the CI workflow.
      - name: Pin npm
        run: npm i -g npm@11

      # No tag to name a release after, so use a sortable timestamp plus the
      # short SHA of the commit being deployed.
      - name: Name the release
        id: release
        run: |
          echo "name=$(date -u +%Y%m%d%H%M%S)-$(git rev-parse --short HEAD)" >> "$GITHUB_OUTPUT"

      - name: Build the release
        run: ./deploy/strato/build-release.sh "${RUNNER_TEMP}/release"

      - name: Configure SSH
        run: |
          mkdir -p ~/.ssh
          echo "${{ secrets.STRATO_SSH_KEY }}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          echo "${{ secrets.STRATO_KNOWN_HOSTS }}" > ~/.ssh/known_hosts

      - name: Upload the release
        env:
          SSH_USER: ${{ secrets.STRATO_SSH_USER }}
          SSH_HOST: ${{ secrets.STRATO_SSH_HOST }}
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
          RELEASE: ${{ steps.release.outputs.name }}
        run: |
          rsync -az --delete \
            -e "ssh -i ~/.ssh/deploy_key -o UserKnownHostsFile=~/.ssh/known_hosts" \
            "${RUNNER_TEMP}/release/" \
            "${SSH_USER}@${SSH_HOST}:${DEPLOY_PATH}/releases/${RELEASE}/"

      - name: Upload the activation script
        env:
          SSH_USER: ${{ secrets.STRATO_SSH_USER }}
          SSH_HOST: ${{ secrets.STRATO_SSH_HOST }}
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
        run: |
          scp -i ~/.ssh/deploy_key -o UserKnownHostsFile=~/.ssh/known_hosts \
            deploy/strato/activate-release.sh \
            "${SSH_USER}@${SSH_HOST}:${DEPLOY_PATH}/activate-release.sh"

      # Links shared state, migrates, warms the cache, and flips `current`
      # last. A failure here leaves the previous release serving traffic.
      - name: Activate the release
        env:
          SSH_USER: ${{ secrets.STRATO_SSH_USER }}
          SSH_HOST: ${{ secrets.STRATO_SSH_HOST }}
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
          RELEASE: ${{ steps.release.outputs.name }}
        run: |
          ssh -i ~/.ssh/deploy_key -o UserKnownHostsFile=~/.ssh/known_hosts \
            "${SSH_USER}@${SSH_HOST}" \
            "chmod +x '${DEPLOY_PATH}/activate-release.sh' && '${DEPLOY_PATH}/activate-release.sh' '${DEPLOY_PATH}' '${RELEASE}'"

      # Keep the five most recent releases so a rollback target always exists.
      - name: Prune old releases
        env:
          SSH_USER: ${{ secrets.STRATO_SSH_USER }}
          SSH_HOST: ${{ secrets.STRATO_SSH_HOST }}
          DEPLOY_PATH: ${{ secrets.STRATO_DEPLOY_PATH }}
        run: |
          ssh -i ~/.ssh/deploy_key -o UserKnownHostsFile=~/.ssh/known_hosts \
            "${SSH_USER}@${SSH_HOST}" \
            "cd '${DEPLOY_PATH}/releases' && ls -1t | tail -n +6 | xargs -r rm -rf"
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
