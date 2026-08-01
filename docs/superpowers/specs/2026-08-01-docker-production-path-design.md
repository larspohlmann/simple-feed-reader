# Docker production path — design

**Issue:** #65 — Verify production email delivery (Docker prod still points at Mailpit)
**Date:** 2026-08-01
**Status:** Approved pending user review

## Problem

A fresh Docker install has no production path at all:

- `docker-compose.yml` hardcodes `MAILER_DSN: "smtp://mailpit:1025"` as a real
  environment variable on the `php` service. Real env vars beat `.env` and
  `.env.local` in Symfony's precedence, so there is **no way to point mail at a
  real relay** without editing a committed file.
- The compose file never sets `APP_ENV`, so the container runs in **dev**.
  `InsecureProductionConfigGuard` only fires in prod, so nothing ever warns
  that mail is being black-holed into an in-memory catcher.
- The existing `prod` compose profile only swaps the frontend bundle
  (`frontend-prod`, :8444). The PHP runtime underneath is still dev + Mailpit.
  It is a *preview*, not production.

Net effect: an operator who installs via `scripts/install.sh` and uses the
instance "in production" runs dev mode with all mail silently captured.
Registration verification, admin approval, and password reset never reach
anyone, and nothing logs an error.

## Decisions (made with the maintainer)

1. **Build a genuine Docker production path**, not just documentation.
2. **Multi-stage prod PHP image** — the "future prod target" the docs defer.
3. **TLS: the stack terminates TLS by default** with operator-supplied
   certificates; plain-HTTP-behind-a-reverse-proxy is the documented fallback.
4. **The old prod preview is replaced.** `frontend-prod`, its two scripts, and
   `docker/frontend/` are deleted. A developer previews the prod topology by
   running the real prod stack locally with mkcert certificates.
5. **The PR #209 helper scripts are adjusted** to drive the new path, and all
   user documentation is updated in the same change.

## Design

### 1. Topology — standalone `docker-compose.prod.yml`

A separate compose file at the repo root, **not** an overlay on
`docker-compose.yml`. An overlay would inherit the dev file's
`MAILER_DSN: smtp://mailpit:1025` — that inheritance is the bug — so the prod
file shares nothing with dev. It pins its own project name
(`name: simple-feed-reader-prod`), so dev and prod stacks coexist on one
machine with disjoint containers and volumes.

Services:

| Service | What |
|---|---|
| `mysql` | `mysql:8.4`, own data volume, credentials from the env file, **no host port published** |
| `php` | the new multi-stage `prod` image (§2) |
| `web` | one nginx container: serves the built SPA and proxies `/api` to php-fpm, same-origin (the topology the preview proved) |

**No Mailpit.** Mailpit exists only in the dev compose file.

### 2. Prod PHP image — multi-stage `docker/php/Dockerfile`

Two named stages:

- **`dev`** — the current image content, unchanged behaviour. The dev compose
  file gains `target: dev`.
- **`prod`** —
  - `php:8.4-fpm-alpine`; extensions `pdo_mysql intl opcache zip` — no xdebug.
  - Backend source `COPY`ed in (no bind mount);
    `composer install --no-dev --optimize-autoloader`.
  - `ENV APP_ENV=prod APP_DEBUG=0` baked into the image.
  - Prod php.ini: tuned opcache (`validate_timestamps=0`), sane memory limit.
  - Entrypoint: warm the Symfony cache, then `exec php-fpm`.

Both stages build from the **repo-root context** (the prod stage must see
`backend/`); the dev compose service changes from `build: ./docker/php` to the
root context + `target: dev`. A root **`.dockerignore`** keeps `vendor/`,
`var/`, `node_modules/`, `.git/`, and `docker/certs*/` out of the context —
TLS material must never enter an image.

**State that outlives the container:**

- A named volume on **`/app/var`** persists logs and the filesystem cache
  pools (rate limiter, spent ALTCHA solutions, OAuth states, login codes)
  across recreates. `CACHE_DIRECTORY` is pinned to a path inside that volume
  (plus a fixed `CACHE_PREFIX_SEED`) so a `cache:clear` on upgrade does not
  wipe the pools — the same reasoning as the Strato deployment.
- A named volume on **`/app/config/jwt`** holds the JWT keypair. The start
  script runs `lexik:jwt:generate-keypair --skip-if-exists` on first run, so
  keys survive image rebuilds and updates.

### 3. Configuration — committed `.env.prod.example` → operator's `.env.prod`

A names-only example file in the repo root (the `deploy/strato/.env.local.example`
pattern, trimmed to what Docker needs). The operator copies it to `.env.prod`
(gitignored) and fills in real values. The scripts pass
`--env-file .env.prod`.

The compose file uses **`${VAR:?}` interpolation for every fail-open
variable**, so compose refuses to start with a message naming the missing
variable rather than starting broken:

- `MAILER_DSN` — required; there is no Mailpit to default to. This is the
  structural fix for #65.
- `MAIL_FROM`, `MAIL_FROM_NAME`
- `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`
- `APP_SECRET`, `ALTCHA_HMAC_KEY`, `JWT_PASSPHRASE`
- `APP_FRONTEND_URL` (public origin; also `APP_BACKEND_URL`, `DEFAULT_URI`)

Optional, documented in the example: `ADMIN_SETUP_SECRET` (web first-admin
path), OAuth client credentials, `MAINTENANCE_TOKEN`, port overrides (§4).

`InsecureProductionConfigGuard` remains the runtime backstop for anything that
slips through.

### 4. TLS — stack-terminated by default, HTTP fallback

The web image ships **both** server configs; a small entrypoint selects one at
start:

- **TLS mode** (default): certificates found at the bind-mounted
  `docker/certs-prod/` (`fullchain.pem` + `privkey.pem`, the Let's Encrypt
  naming) → 443 serves the app, 80 answers `301 https://$host$request_uri`.
- **HTTP mode** (fallback): no certificates present → 80 serves the app
  plainly, for an operator's reverse proxy (Caddy, Traefik, nginx) in front.

Selection is automatic (cert files exist or not); `WEB_MODE` overrides it
explicitly. Compose publishes `${WEB_TLS_PORT:-443}` → 443 and
`${WEB_HTTP_PORT:-80}` → 80. The docs cover both modes, including a sample
reverse-proxy snippet and the note that the public origin must be HTTPS (the
`__Host-` OAuth cookie requires it).

### 5. Scripts — the PR #209 set, adjusted

- **New `scripts/prod-start.sh`** — the prod lifecycle, idempotent:
  1. refuse without `.env.prod`, pointing at `.env.prod.example`;
  2. report which mode (TLS / HTTP) the certs imply;
  3. `docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml
     --env-file .env.prod up -d --build`;
  4. run migrations; generate the JWT keypair if missing;
  5. health-check; print a prod summary including the first-admin command
     (`docs/first-run-setup.md`) and the mail verification command (§6).
- **New `scripts/prod-stop.sh`** — stop the prod stack, data kept.
- **Deleted:** `frontend-prod-start.sh`, `frontend-prod-stop.sh`, the
  `frontend-prod` service, `docker/frontend/`.
- `lib.sh` gains shared prod helpers (project name, compose wrapper, env-file
  check) and its dev summary drops the preview lines.
- **Update path:** `prod-start.sh` is idempotent, so updating = check out the
  newer release tag, run it again (rebuilds, migrates). Documented;
  `update.sh` stays dev-only and says so.

CI's `shellcheck scripts/*.sh` picks the new scripts up automatically.

### 6. Mail verification — the heart of #65

Repeatable, documented, and printed by `prod-start.sh`:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml \
  exec php bin/console mailer:test you@example.com
```

`mailer:test` sends through the real configured transport. The docs give
`MAILER_DSN` examples for common setups (authenticated SMTP relay on 587,
provider DSNs) and state the Mailpit rule: dev-only, never reachable from the
prod stack.

### 7. Documentation sweep (user-facing docs stay current)

- **New `docs/docker-production.md`** — the install guide: prerequisites →
  clone + release checkout → `.env.prod` → certificates or reverse proxy →
  `prod-start.sh` → first admin (links `first-run-setup.md`) → verify mail →
  update → backup note (`mysqldump` before major updates).
- **`README.md`** — Quick start table: preview rows out, prod script rows in;
  a "Running in production" pointer to the new guide; Mailpit row marked
  dev-only.
- **`docs/local-docker.md`** — §1/§2 (service list, ports) drop :8444; §8
  "Extension points" updates (prod image now delivered); §9's preview section
  is replaced by a short pointer to `docker-production.md`.
- **`docs/first-run-setup.md`** — the `docker compose exec` invocation gets
  the prod-stack variant (`-p simple-feed-reader-prod -f docker-compose.prod.yml`).
- **`scripts/lib.sh`** summaries and `install.sh` closing summary mention the
  prod path.

### 8. Testing / verification

- **Backend suite untouched** — no PHP code changes are expected; the prod
  image runs the same source. If the entrypoint needs a Symfony change (none
  anticipated), it gets tests then.
- **Shell:** `shellcheck` (CI) + `bash -n` on all touched scripts.
- **Compose:** `docker compose -f docker-compose.prod.yml config` with a
  filled sample env validates interpolation; with a missing `MAILER_DSN` it
  must fail naming the variable.
- **End-to-end prod smoke (manual, documented in the PR):** build the prod
  stack locally with mkcert certs in `docker/certs-prod/`. Verify mail with
  `mailer:test` against an SMTP endpoint set explicitly in `.env.prod` — that
  the mail arrives *there* proves the DSN is honoured and nothing falls back
  to a hidden default. Then: health check, register, first admin via
  `app:admin:create`, OAuth cookie sanity on HTTPS.
- **Regression:** dev stack (`docker compose up -d`) still works; the e2e
  suites (`composer e2e`, Playwright) still pass against it.

## Acceptance criteria of #65, mapped

| Criterion | Where satisfied |
|---|---|
| Documented answer: prod used Mailpit/null | This spec §Problem + `docs/docker-production.md` intro |
| Prod config example for `MAILER_DSN` + `MAIL_FROM*` in the install guide | `.env.prod.example` + `docs/docker-production.md` |
| Repeatable way to verify real delivery | `mailer:test` step, printed by `prod-start.sh`, documented |
| Mailpit confirmed dev-only | Prod compose has no Mailpit and cannot inherit the dev DSN (standalone file) |

## Deliberately out of scope

- A worker/cron container (manual refresh is a decided design point).
- Automatic certificate issuance (operators bring certs or a proxy).
- Registry-published images; the stack builds from the checkout, matching the
  existing install/update flow.
- Any change to the Strato deployment.
