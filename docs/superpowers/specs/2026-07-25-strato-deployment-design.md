# Personal STRATO Deployment — Design

Issue: [#73](https://github.com/larspohlmann/simple-feed-reader/issues/73)
Date: 2026-07-25

## Problem

The app has never been deployed. `~/simplefeedreader/releases/` on the STRATO host is
empty and `current/public/` holds nothing but a 73-byte placeholder `index.html`.

The subdomain `reader.lars-pohlmann.de` was prepared for it, but **STRATO's free
inclusive SSL certificate is a Single Domain certificate covering only the apex domain
and `www` — not arbitrary subdomains.** Verified two ways: the subdomain serves no
certificate at all (TLS handshake failure), and the SSL panel's "Neu zuweisen" dropdown
offers only whole domains, never a subdomain. Wildcard coverage costs 1 €/month for six
months then 7 €/month; Let's Encrypt is impossible on shared hosting (no root access).

Serving the app under the **`/reader` subpath of the already-certified apex domain**
gets HTTPS for free.

This deployment is the maintainer's personal process. It must not interfere with the
Docker setup, which is the correct and canonical path for anyone cloning the repo.

## Goal

`https://lars-pohlmann.de/reader` serves the reader over the apex domain's existing
certificate, with registration + email + Google sign-in working, deployed by an atomic
symlink flip from GitHub Actions.

## Non-goals

- **No scheduled refresh.** Refresh stays manual (the frontend button). The
  `no-refresh-scheduler-gap` remains open; a cron/worker is a separate ticket.
- **No Apple sign-in.** Requires a paid Apple Developer account. `APPLE_OAUTH_CLIENT_ID`
  stays blank, which disables that leg entirely.
- **No changes to the Docker setup**, the Angular `production` configuration, or the
  default root-path build.
- **No wildcard/paid certificate.**
- Not a general-purpose deployment story for other users. Issue #68 (user docs for
  running production locally) covers that separately.

## Decisions (confirmed)

| Decision | Choice |
| --- | --- |
| Isolation | Isolated `deploy/strato/` directory, merged to `develop` |
| Scope | Full: registration + email + Google OAuth — but **no cron** |
| Database | MySQL (the hosting package's DB) |
| Deploy trigger | GitHub Actions — every push/merge to `develop`, gated on CI passing |
| OAuth providers | Google only |
| Mailer | STRATO SMTP (included in the package) |
| Old subdomain | Redirects to `/reader` |

## Architecture

### Why the subpath needs no application logic changes

Three call sites were checked and all compose correctly with a base path:

1. **Symfony base-path detection** — with the front controller at `/reader/index.php`,
   `SCRIPT_NAME` is `/reader/index.php` and `REQUEST_URI` is `/reader/api/...`, so
   Symfony derives a base URL of `/reader` and a path info of `/api/...`. Existing
   routes match **unchanged**; no route prefix, no config.
2. **Bearer interceptor** (`frontend/src/app/core/auth.interceptor.ts`) —
   `req.url.startsWith(base ? base : '/')`. With `API_BASE_URL = '/reader'`, requests to
   `/reader/api/...` still match and get the `Authorization` header.
3. **Backend-built links** — `AccountMailer` uses
   `rtrim($frontendUrl,'/') . $path . '?token=...'` and `OAuthController` uses
   `rtrim($frontendUrl,'/')`. With `APP_FRONTEND_URL=https://lars-pohlmann.de/reader`
   these yield `.../reader/verify-email?token=...` and `.../reader/auth/callback`.

So the subpath is achieved almost entirely through **environment values plus one additive
Angular build configuration**.

**One exception, found while planning.** `frontend/src/app/core/transloco-loader.ts` requests
`/i18n/${lang}.json` — absolute from the domain root. Mounted at `/reader` that resolves to
`https://lars-pohlmann.de/i18n/de.json`, which belongs to the portfolio and 404s. Dictionaries
are preloaded before first paint, so the entire UI would come up untranslated. The fix is to
make the path relative, which resolves against the document base URI and is therefore correct
at the root and under a subpath alike. A sweep of `frontend/src` found this to be the **only**
root-absolute reference: Material Symbols uses `./`, and the base href in `index.html` is
replaced by the build.

### Serving: one docroot for SPA and API

```
~/larspohlmann/reader   --symlink-->   ~/simplefeedreader/current/public
```

`lars-pohlmann.de` is internally mapped to `~/larspohlmann/` (the static freelance
portfolio; it has no `.htaccess`, so there are no rewrite conflicts). A symlink named
`reader` inside it mounts the app. Symlinks are known to work on this host.

The release's `public/` holds Symfony's `index.php`, a **new `.htaccess`**, and the built
Angular bundle. The `.htaccess` is new work: the repo has only nginx configs today
(`backend/public/` contains just `index.php`), because Docker serves the SPA from nginx
and proxies `/api` to php-fpm. Apache needs the equivalent expressed as rewrite rules:

1. An existing file or directory is served as-is (assets, `index.html`) — except dotfiles,
   which are denied so the rule cannot serve the `.htaccess` itself.
2. Anything under `/api` (plus any other Symfony route) goes to `index.php`.
3. Everything else falls back to `index.html` for Angular's client-side router.

`index.html` is additionally sent with `Cache-Control: no-cache`. It names the content-hashed
bundles, so a heuristically cached copy would pin a browser to the previous release's
JavaScript however well the assets themselves are versioned. This also makes `api` and
`maintenance` reserved top-level names: a static asset called either would be routed to
Symfony before the SPA ever saw it.

Rule 3 must not swallow rule 2, and rule 1 must come first so hashed assets are served
directly. Apache also needs `FollowSymLinks` for the mount to resolve.

**`DirectoryIndex` must be pinned to `index.html`.** Symfony's `index.php` and Angular's
`index.html` share the directory, so a bare request for `/reader/` is resolved by
Apache's directory index — and if `index.php` wins, the request reaches Symfony, which
has no route for `/` and answers 404 instead of serving the app. The `.htaccess` sets
`DirectoryIndex index.html` explicitly rather than relying on the server default.

### Release layout and shared state

```
~/simplefeedreader/
  releases/<tag>/        one directory per deploy
  current -> releases/<tag>     atomic symlink, flipped last
  shared/
    .env.local           all secrets — never in git
    config/jwt/          JWT keypair
    var/log/
    var/cache-pools/     rate limiter + ALTCHA replay
```

Each release symlinks these `shared/` paths into place before the flip. Two of them are
correctness requirements, not conveniences:

- **JWT keys must be shared.** Regenerating them per release would invalidate every
  issued token on every deploy, silently logging everyone out.
- **Cache pools must be shared.** This is the plan-6 note from the plan-3 review: the
  `cache.rate_limiter` and `altcha.replay.cache` filesystem pools default to a
  per-release `var/`, so a deploy would reset every rate-limit counter and forget every
  spent ALTCHA solution — re-opening the replay window.

`var/cache` (the compiled container) stays per-release; it is release-specific by nature.

### Build and deploy pipeline

The server has no composer, no node, and no crontab, so **everything is built on the
runner** and shipped as artifacts.

Trigger: **every push or merge to `develop`, but only after CI passes.** `develop` is the
integration branch, so this makes it continuously deployed.

CI does not currently run on `develop` at all — `ci.yml` triggers on `push` to `main` and on
`pull_request`. So a push to `develop` would deploy code whose tests never ran on the merged
result. Two coupled changes fix that: `develop` joins `ci.yml`'s push branches, and the deploy
workflow triggers on `workflow_run` (CI completed on `develop`) with a job-level condition
requiring `conclusion == 'success'`. Ordering is then guaranteed by GitHub rather than by a
duplicated test run inside the deploy workflow.

Two consequences of `workflow_run` worth stating plainly:

- **It only fires when the workflow file is on the default branch**, which is `main`. Under
  git-flow the branch merges to `develop` first, so automatic deploys stay dormant until
  `develop` is merged to `main`. `workflow_dispatch` covers the interim and remains the
  manual escape hatch afterwards.
- **It checks out the default branch by default, not the commit that triggered CI.** The
  checkout must pin `github.event.workflow_run.head_sha`, or every deploy would ship `main`.

Never `pull_request_target`. The repository is public; GitHub withholds secrets from fork
pull requests, and neither trigger is reachable from a fork.

Releases are named from the timestamp and the short commit SHA (there is no tag to name them
after), which keeps them sortable and traceable back to a commit.

Steps: checkout → PHP 8.4 + `composer install --no-dev --optimize-autoloader` → Node +
`npm ci` → `ng build --configuration=strato` → assemble the release tree (backend +
built SPA into `public/`, plus `.htaccess`) → `rsync` over SSH into `releases/<tag>` →
over SSH: link `shared/`, warm the cache, run migrations → `ln -sfn` flip `current`.

Console commands run as `php84 -q -f bin/console <cmd>`: the host's PHP is the
**cgi-fcgi SAPI**, where `-q` suppresses HTTP headers and `-f` takes the script. (`php -r`
is not available in this SAPI.) PHP 8.4.22 is present, with `pdo_mysql`, `pdo_sqlite`,
`curl`, `dom`, `intl`, `mbstring`, `openssl`, and `xml` all compiled in.

The migration step runs **before** the symlink flip, so a failed migration leaves the
live release untouched. The first deploy migrates a fresh, empty database; no
`migrations:version --add --all` baselining is needed because no prior production
database exists.

### What lives where (the isolation boundary)

New, personal — `deploy/strato/`:

- the release-assembly and remote-activation scripts the workflow calls (keeping the
  workflow file thin and the logic reviewable and runnable on its own)
- `.htaccess` for Apache
- `.env.local.example` documenting every required production variable (values excluded)
- a README covering the manual panel steps and first-deploy runbook

New, unavoidably outside that directory:

- `.github/workflows/deploy-strato.yml` — workflows only run from `.github/workflows/`.
  Named unambiguously so it reads as personal infrastructure.

Changed, existing files — behaviour preserved at the domain root:

- `.github/workflows/ci.yml` — `develop` joins the push-trigger branches. Additive: it makes
  CI run on a branch it previously ignored, and changes nothing about `main` or pull requests.
  Required so the deploy has a CI result to gate on.

- `frontend/src/app/core/transloco-loader.ts` — the root-absolute i18n path becomes relative.
  Identical behaviour when served at the root; correct under a subpath.
- `backend/config/packages/cache.yaml` and `backend/.env` — the filesystem cache pools'
  directory and key namespace become env-driven. Symfony's defaults place the pools inside
  `var/cache` (per-release, and wiped by `cache:clear`) and seed the namespace with
  `%kernel.project_dir%` (the release path), so **both** must be overridable or a deploy
  resets every rate limit and forgets every spent ALTCHA solution. The committed directory
  default is Symfony's own, verbatim. The committed seed deliberately is **not** — a stable
  literal replaces a project-path-derived one, which costs a single renamespacing wherever
  cache data already exists (a cold start all three pools tolerate) and is the only way to
  get one namespace that survives a release flip.

Additive, existing files — no existing behaviour altered:

- `frontend/angular.json` gains a `strato` build configuration (base href `/reader/`,
  environment file replacement). `production` and `development` are untouched, so the
  Docker prod profile and every existing build keep emitting a root-path bundle.
- `frontend/src/environments/environment.strato.ts` — new file, `apiBaseUrl: '/reader'`.

Untouched: `docker-compose.yml`, `docker/`, `backend/config`, all application code.

## Production environment values

Set in `shared/.env.local` on the server, never in git:

| Variable | Value |
| --- | --- |
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | generated, unique to production |
| `DATABASE_URL` | `mysql://…` — the package DB |
| `MAILER_DSN` | STRATO SMTP with the `noreply@` mailbox |
| `APP_FRONTEND_URL` | `https://lars-pohlmann.de/reader` |
| `APP_BACKEND_URL` | `https://lars-pohlmann.de/reader` |
| `GOOGLE_OAUTH_CLIENT_ID` / `_SECRET` | from Google Cloud console |
| `APPLE_OAUTH_CLIENT_ID` | blank — disables Apple |

## Manual steps (maintainer, outside automation)

These need the STRATO and Google consoles and cannot be scripted:

1. Create the MySQL database in the STRATO panel; record credentials.
2. Create the `noreply@lars-pohlmann.de` mailbox; record SMTP credentials.
3. ~~Set the vhost's PHP version to 8.4~~ — **already the case.** Probed on 2026-07-25: the
   web vhost serves PHP 8.4.22 (cgi-fcgi), which is what reader mode needs.
4. Register the Google OAuth redirect URI **exactly**:
   `https://lars-pohlmann.de/reader/api/auth/oauth/google/callback`
5. Repoint `reader.lars-pohlmann.de` as a redirect to `https://lars-pohlmann.de/reader`.
   Note this redirect itself travels over plain HTTP (the subdomain still has no
   certificate); it is a convenience for old links, not a secure entry point.
6. Populate `shared/.env.local` and install the JWT keypair into `shared/config/jwt/`.

## Error handling

- **Failed migration** — runs pre-flip, so `current` still points at the previous
  release and the site stays up. The release directory is left for inspection.
- **Rollback** — repoint `current` at the previous release directory and flip back. No
  build required. Down-migrations are not part of this design.
- **Failed rsync or SSH step** — the workflow fails before any flip; nothing changes.
- **Wrong vhost PHP version** — surfaces as a hard failure in the first-deploy
  verification, not silently; reader mode would break at runtime otherwise.

## Testing

This is mostly deployment infrastructure, so verification is largely a live runbook. Two
changes are genuinely testable and get tests:

- The i18n loader's URL is asserted to be relative (no leading slash) by a unit test — this
  is the one application-code change on the branch.
- The Angular build configuration is verified by building it and asserting the emitted base
  href, in both directions.

Build-time checks (in CI, on the runner):

- `ng build --configuration=strato` succeeds and emits `<base href="/reader/">`.
- The existing `production` build still emits a root base href — proving isolation.
- Existing gates (`npm run check`, backend cs/stan/phpmd, full test suites) stay green;
  this branch adds no application code.

First-deploy verification, in order:

1. `https://lars-pohlmann.de/reader` loads the SPA over a valid certificate.
2. `https://lars-pohlmann.de/reader/api/health` responds.
3. Assets load (no 404s from a wrong base href); a client-side route survives a reload.
4. Register → receive the double-opt-in email → verify → admin approval.
5. Google sign-in completes and lands back on `/reader`.
6. Subscribe to a feed, refresh, open an article, and confirm reader mode works
   (this is what proves PHP 8.4 on the vhost).
7. The portfolio at `https://lars-pohlmann.de/` and its `/de/` route still work —
   the symlink mount must not disturb them.
8. Deploy a second time and confirm the flip is atomic, logins survive (shared JWT keys),
   and rate-limit state survives (shared cache pools).

## Quality gates (per project standing rules)

- Any touched PHP source must be phpmd-clean and pass PhpStorm inspections; this branch
  is expected to touch no PHP source at all.
- Frontend gate `npm run check` (ESLint + Prettier + Stylelint + Jest) plus
  `ng build` for both configurations.
- Shell scripts and YAML kept lint-clean and reviewed for quoting around paths.
- No secret values committed. `deploy/strato/.env.local.example` carries names and
  explanatory comments only.
- Branch `feature/73-strato-deployment` off `develop`; merged via PR into `develop`;
  issue closed manually on merge.
