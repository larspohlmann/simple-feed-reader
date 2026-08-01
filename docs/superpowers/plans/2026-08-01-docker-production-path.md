# Docker Production Path Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A genuine Docker production runtime — prod PHP image, prod compose stack with a required real mail transport, prod installer — replacing the dev-backed "prod preview" (issue #65).

**Architecture:** A standalone `docker-compose.prod.yml` (own project name, never an overlay, so the dev stack's Mailpit DSN cannot leak in) runs three services: mysql, a new multi-stage `prod` PHP image (APP_ENV=prod baked, no xdebug, source copied in), and one `web` nginx container serving the built SPA plus `/api` via fastcgi. TLS is auto-selected: certificates mounted → TLS mode, none → plain HTTP for a reverse proxy. `install.sh` becomes the production installer (generated secrets, interactive prompts, two-step fallback); `install-dev.sh` takes over the dev install.

**Tech Stack:** Docker Compose v2, php:8.4-fpm-alpine, nginx:1.27-alpine, node:22-slim (build stage), bash (3.2-compatible), Symfony console commands (`lexik:jwt:generate-keypair`, `doctrine:migrations:migrate`, `mailer:test`).

**Spec:** `docs/superpowers/specs/2026-08-01-docker-production-path-design.md`

## Global Constraints

- Shell scripts must pass `shellcheck` with zero findings of ANY severity (CI runs `shellcheck scripts/*.sh` and fails on info-level too). Use guard clauses, never `A && B || C` (SC2015).
- Shell scripts must run on macOS's default bash 3.2 (`set -u` safe; no `${var,,}`, no associative arrays, no `readarray`).
- Prose in user docs: ASD-STE100 style is for chat replies only; docs follow the existing docs' voice (see `docs/local-docker.md`).
- `MAILER_DSN` never gets a working default. Compose `${VAR:?}` enforces it; `InsecureProductionConfigGuard` stays the runtime backstop.
- No script ever runs `docker compose down -v` (deletes the MySQL volume).
- TLS material never enters a git commit or a Docker image (`.gitignore` + `.dockerignore`).
- The dev stack (`docker compose up -d`, `composer e2e`, Playwright) must keep working unchanged.
- Branch: `feature/65-docker-production-path`. Commit after every task.
- **Release-lag caveat:** the rewritten `install.sh`/`update.sh` check out the latest `vX.Y.Z` release tag, which will not contain the prod files until the next release is cut. End-to-end installer runs against GitHub only work after that release; verification therefore tests the post-clone path directly in the checkout (Task 10) and the PR description must say so.

---

### Task 1: Root `.dockerignore` and the multi-stage PHP image

**Files:**
- Create: `.dockerignore` (repo root)
- Modify: `docker/php/Dockerfile` (dev stage keeps current behaviour; new prod stage)
- Create: `docker/php/conf.d/prod.ini`
- Create: `docker/php/entrypoint-prod.sh`
- Modify: `docker-compose.yml` (php service: root build context + `target: dev`)

**Interfaces:**
- Produces: image target `prod` (php-fpm on 9000, source at `/app`, `APP_ENV=prod` baked, entrypoint warms cache then writes `/app/var/.ready`); image target `dev` (unchanged behaviour). Task 3's compose file builds `target: prod`; Task 4's `wait_for_php_ready` polls `var/.ready`.

- [ ] **Step 1: Create `.dockerignore`** at the repo root:

```
# Keep build contexts small and secrets out of images. Both the php and the
# web image build from the repo root (they must see backend/ and frontend/).
.git
.idea
.claude
.superpowers
.playwright-mcp
docs
deploy
scripts
.github

# Local TLS material and operator secrets must never enter an image.
docker/certs
docker/certs-prod
.env.prod
backend/config/jwt

# Host-side build products; the images install/build their own.
backend/var
backend/vendor
frontend/node_modules
frontend/dist
frontend/test-results
frontend/playwright-report
```

- [ ] **Step 2: Rewrite `docker/php/Dockerfile`** as two named stages. The dev stage is the current content with COPY paths adjusted for the repo-root context:

```dockerfile
# Two stages, selected via compose `target:`.
#
#  * dev  -- the local development image: xdebug, dev-friendly limits, source
#            bind-mounted by docker-compose.yml (nothing is copied in).
#  * prod -- the production image: no xdebug, tuned opcache, dependencies and
#            source baked in. docker-compose.prod.yml builds this target.
#
# Both build from the REPO-ROOT context (the prod stage must COPY backend/);
# the root .dockerignore keeps vendor/, var/, node_modules/ and all TLS
# material out of the context.

FROM php:8.4-fpm-alpine AS dev

# install-php-extensions resolves each extension's build- and runtime-deps on
# alpine (icu for intl, libzip for zip, phpize toolchain for the xdebug pecl
# build) and cleans the build deps up afterwards.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache zip xdebug

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /app


FROM php:8.4-fpm-alpine AS prod

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
# su-exec: the entrypoint fixes volume ownership as root, then drops to
# www-data for the cache warmup so runtime writes stay www-data-owned.
RUN install-php-extensions pdo_mysql intl opcache zip \
    && apk add --no-cache su-exec

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app

# Manifests first so the dependency layer caches across source-only edits.
# --no-scripts: flex's cache:clear must not run at build time -- the real
# environment (DATABASE_URL, MAILER_DSN) only exists at run time, and the
# entrypoint warms the cache then anyway.
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Real env vars beat the committed .env, so the baked APP_ENV=dev is inert.
ENV APP_ENV=prod APP_DEBUG=0

COPY docker/php/entrypoint-prod.sh /usr/local/bin/entrypoint-prod.sh
RUN chmod +x /usr/local/bin/entrypoint-prod.sh
ENTRYPOINT ["/usr/local/bin/entrypoint-prod.sh"]
```

- [ ] **Step 3: Create `docker/php/conf.d/prod.ini`:**

```ini
; Production runtime limits. The dev image uses conf.d/app.ini instead.
memory_limit = 256M
expose_php = 0

; The source is baked into the image, so a file can never change under a
; running container -- skipping the mtime check on every request is free
; speed. A code change arrives as a new image, which starts an empty opcache.
opcache.validate_timestamps = 0
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

- [ ] **Step 4: Create `docker/php/entrypoint-prod.sh`:**

```sh
#!/bin/sh
# Prod entrypoint: prepare the mounted volumes, rebuild the Symfony cache for
# THIS image (the var/ volume can carry a previous release's cache, and a
# stale container class is a fatal error), then hand off to php-fpm.
#
# The cache pools (rate limiter, ALTCHA replay, OAuth state, login codes)
# live under var/cache-pools -- CACHE_DIRECTORY, set by docker-compose.prod.yml
# -- outside var/cache/prod, so the rebuild below never resets rate limits or
# replay protection.
#
# var/.ready is the readiness flag scripts/lib.sh polls before running console
# commands: removed first thing, written after the warmup succeeded.
set -e

rm -f var/.ready
mkdir -p var/cache var/log var/cache-pools config/jwt
chown -R www-data:www-data var config/jwt

rm -rf var/cache/prod
su-exec www-data php bin/console cache:warmup --no-interaction

touch var/.ready
chown www-data:www-data var/.ready

exec php-fpm
```

Then `chmod +x docker/php/entrypoint-prod.sh` on the host so git records the executable bit.

- [ ] **Step 5: Point the dev compose service at the new context.** In `docker-compose.yml`, replace

```yaml
  php:
    build: ./docker/php
```

with

```yaml
  php:
    build:
      # Repo-root context shared with the prod target (docker/php/Dockerfile
      # is multi-stage); the root .dockerignore keeps the context small.
      context: .
      dockerfile: docker/php/Dockerfile
      target: dev
```

- [ ] **Step 6: Verify the dev image still builds and the stack still runs**

Run: `docker compose build php && docker compose up -d && docker compose exec -T php php -m | grep -i xdebug`
Expected: build succeeds; `Xdebug` listed.

Run: `curl -fsk https://localhost:8443/api/health`
Expected: `{"status":"ok"}`

- [ ] **Step 7: Verify the prod target builds**

Run: `docker build --target prod -f docker/php/Dockerfile -t sfr-php-prod-smoke . && docker run --rm sfr-php-prod-smoke php -m | grep -i -c xdebug || true`
Expected: build succeeds; xdebug grep count is `0`.

Run: `docker run --rm sfr-php-prod-smoke php -r 'echo ini_get("opcache.validate_timestamps"), " ", getenv("APP_ENV"), PHP_EOL;'`
Expected: `0 prod`

- [ ] **Step 8: Commit**

```bash
git add .dockerignore docker/php/Dockerfile docker/php/conf.d/prod.ini docker/php/entrypoint-prod.sh docker-compose.yml
git commit -m "feat(#65): multi-stage PHP image with a genuine prod target"
```

---

### Task 2: The prod web image (SPA + API gateway), preview deleted

**Files:**
- Create: `docker/web/Dockerfile`
- Create: `docker/web/tls.conf`
- Create: `docker/web/http.conf`
- Create: `docker/web/10-select-mode.sh`
- Delete: `docker/frontend/Dockerfile`, `docker/frontend/prod.conf`
- Modify: `docker-compose.yml` (delete the `frontend-prod` service and its profile)

**Interfaces:**
- Consumes: php-fpm at `php:9000` with the front controller at `/app/public/index.php` (Task 1's prod image).
- Produces: an image listening on 80 (always) and 443 (when certs are mounted at `/etc/nginx/certs/fullchain.pem` + `privkey.pem`); env `WEB_MODE` (`auto`|`tls`|`http`) selects the server config. Task 3's compose file builds it.

- [ ] **Step 1: Create `docker/web/Dockerfile`:**

```dockerfile
# The production web container: stage one builds the Angular bundle, stage two
# serves it behind nginx with /api handed to php-fpm -- same-origin, so the
# prod build (apiBaseUrl '') needs no CORS. Replaces the old docker/frontend
# prod-preview image, which served the same topology over the DEV backend.
#
# Build context is the repo root (frontend/ and docker/web/ must both be
# reachable). TLS or plain HTTP is selected at CONTAINER START, not at build
# time -- see 10-select-mode.sh.
FROM node:22-slim AS build
WORKDIR /app
# Manifests first so the dependency layer caches across source-only changes.
COPY frontend/package.json frontend/package-lock.json ./
# node:22 ships npm 10.9.8, but the lockfile was authored by npm 11 (npm 10
# mis-resolves a transitive dep -- chokidar -- and rejects the lock). Pin npm 11.
RUN npm i -g npm@11 && npm ci --no-audit --no-fund
COPY frontend/ ./
# Default configuration is production (environment.ts -> apiBaseUrl '').
RUN npx ng build

FROM nginx:1.27-alpine
# Angular's application builder emits the browser bundle under dist/<project>/browser.
COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
# Both server configs ship in the image; the entrypoint drop-in picks one.
COPY docker/web/tls.conf /etc/nginx/available/tls.conf
COPY docker/web/http.conf /etc/nginx/available/http.conf
COPY docker/web/10-select-mode.sh /docker-entrypoint.d/10-select-mode.sh
```

- [ ] **Step 2: Create `docker/web/tls.conf`:**

```nginx
# TLS mode: chosen when certificates are mounted (see 10-select-mode.sh).
# The operator brings fullchain.pem + privkey.pem (the Let's Encrypt naming).
server {
    listen 80;
    server_name _;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name _;

    ssl_certificate     /etc/nginx/certs/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/privkey.pem;

    root /usr/share/nginx/html;

    # nginx's 1m default would answer real-world OPML imports with a raw 413
    # before Symfony ever sees the request.
    client_max_body_size 10m;

    # The API. The prod php image bakes the app at /app, so the front
    # controller is always /app/public/index.php inside php-fpm regardless of
    # THIS nginx's root -- hence the hardcoded SCRIPT_FILENAME (no rewrite
    # happens here, so $fastcgi_script_name would be /api/... and is not
    # usable). Symfony routes on REQUEST_URI (supplied by fastcgi_params).
    location /api/ {
        include fastcgi_params;
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT /app/public;
        fastcgi_param HTTPS on;
    }

    # SPA client-side routes: fall back to index.html for anything not a real file.
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

- [ ] **Step 3: Create `docker/web/http.conf`:**

```nginx
# Plain-HTTP mode: chosen when no certificate is mounted. For an operator's
# reverse proxy terminating TLS in front, or a localhost/LAN instance.
# Same routing as tls.conf, minus TLS -- keep the two files in step.
server {
    listen 80;
    server_name _;

    root /usr/share/nginx/html;

    client_max_body_size 10m;

    location /api/ {
        include fastcgi_params;
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT /app/public;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

- [ ] **Step 4: Create `docker/web/10-select-mode.sh`** (the nginx alpine image runs every executable `/docker-entrypoint.d/*.sh` before starting nginx):

```sh
#!/bin/sh
# Select the server config before nginx starts: TLS when certificates are
# mounted, plain HTTP otherwise. WEB_MODE=tls|http overrides the detection
# (auto is the default). An explicit tls without certs is a deliberate hard
# failure -- nginx will refuse to start -- rather than silently serving HTTP.
set -e

rm -f /etc/nginx/conf.d/default.conf

mode="${WEB_MODE:-auto}"
if [ "${mode}" = "auto" ]; then
    if [ -f /etc/nginx/certs/fullchain.pem ] && [ -f /etc/nginx/certs/privkey.pem ]; then
        mode=tls
    else
        mode=http
    fi
fi

cp "/etc/nginx/available/${mode}.conf" /etc/nginx/conf.d/default.conf
echo "simple-feed-reader web: ${mode} mode"
```

Then `chmod +x docker/web/10-select-mode.sh`.

- [ ] **Step 5: Delete the preview.** Remove `docker/frontend/` entirely (`git rm -r docker/frontend`), and delete the whole `frontend-prod` service block (lines 91–105, the comment included) from `docker-compose.yml`.

- [ ] **Step 6: Verify**

Run: `docker build -f docker/web/Dockerfile -t sfr-web-smoke . && docker run --rm -e WEB_MODE=http sfr-web-smoke nginx -t`
Expected: build succeeds (Angular build inside); config test prints `syntax is ok` / `test is successful`.

Run: `docker compose config --services`
Expected: the list contains `mysql`, `php`, `nginx`, `mailpit`, `frontend` (any order) and does NOT contain `frontend-prod`.

- [ ] **Step 7: Commit**

```bash
git add -A docker/web docker/frontend docker-compose.yml
git commit -m "feat(#65): prod web image (SPA + API, TLS/HTTP auto-select); drop the preview"
```

---

### Task 3: `docker-compose.prod.yml`, `.env.prod.example`, ignore rules

**Files:**
- Create: `docker-compose.prod.yml`
- Create: `.env.prod.example`
- Create: `docker/certs-prod/.gitkeep`
- Modify: `.gitignore` (root)

**Interfaces:**
- Consumes: image targets from Tasks 1–2.
- Produces: the prod stack under project name `simple-feed-reader-prod`; env-file contract (`PUBLIC_URL`, `MAILER_DSN`, `MAIL_FROM`, `MAIL_FROM_NAME`, `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD`, `MYSQL_USER`, `MYSQL_DATABASE`, `APP_SECRET`, `ALTCHA_HMAC_KEY`, `JWT_PASSPHRASE`, `WEB_TLS_PORT`, `WEB_HTTP_PORT`, `WEB_BIND_ADDRESS`, `WEB_MODE`, `ADMIN_SETUP_SECRET`, `MAINTENANCE_TOKEN`, OAuth vars). Tasks 4–6 build on both.

- [ ] **Step 1: Create `docker-compose.prod.yml`:**

```yaml
# The PRODUCTION stack. Deliberately a standalone file, not an overlay on
# docker-compose.yml: an overlay would inherit the dev file's
# MAILER_DSN=smtp://mailpit:1025 -- the exact silent mail black hole issue #65
# exists to kill. Nothing here defaults to a dev service.
#
# Run it through the helper scripts (scripts/prod-start.sh reads .env.prod):
#   docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod ...
#
# Every ${VAR:?} below makes compose refuse to start with a message naming the
# missing variable -- fail loud at start, never fail open at runtime. See
# .env.prod.example for what each variable means.

name: simple-feed-reader-prod

services:
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:?set it in .env.prod}
      MYSQL_DATABASE: ${MYSQL_DATABASE:-feedreader}
      MYSQL_USER: ${MYSQL_USER:-feedreader}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:?set it in .env.prod}
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -uroot -p\"$$MYSQL_ROOT_PASSWORD\" --silent"]
      interval: 5s
      timeout: 3s
      retries: 20

  php:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      target: prod
    environment:
      DATABASE_URL: "mysql://${MYSQL_USER:-feedreader}:${MYSQL_PASSWORD:?set it in .env.prod}@mysql:3306/${MYSQL_DATABASE:-feedreader}?serverVersion=8.4&charset=utf8mb4"
      # No default and no fallback: null:// discards mail and reports success,
      # and InsecureProductionConfigGuard 500s every request while it is set.
      MAILER_DSN: ${MAILER_DSN:?set a real mail transport in .env.prod}
      MAIL_FROM: ${MAIL_FROM:?set it in .env.prod}
      MAIL_FROM_NAME: ${MAIL_FROM_NAME:-Simple Feed Reader}
      APP_SECRET: ${APP_SECRET:?set it in .env.prod}
      ALTCHA_HMAC_KEY: ${ALTCHA_HMAC_KEY:?set it in .env.prod}
      JWT_PASSPHRASE: ${JWT_PASSPHRASE:?set it in .env.prod}
      # One public origin fills all three URL roles (SPA links in mail, OAuth
      # redirect base, CLI-generated URLs).
      APP_FRONTEND_URL: ${PUBLIC_URL:?set it in .env.prod}
      APP_BACKEND_URL: ${PUBLIC_URL:?set it in .env.prod}
      DEFAULT_URI: ${PUBLIC_URL:?set it in .env.prod}
      # Pools live OUTSIDE var/cache/prod so the entrypoint's cache rebuild
      # never resets rate limits or ALTCHA replay protection. The php-var
      # volume makes them survive container recreates.
      CACHE_DIRECTORY: /app/var/cache-pools/app
      ADMIN_SETUP_SECRET: ${ADMIN_SETUP_SECRET:-}
      MAINTENANCE_TOKEN: ${MAINTENANCE_TOKEN:-}
      GOOGLE_OAUTH_CLIENT_ID: ${GOOGLE_OAUTH_CLIENT_ID:-}
      GOOGLE_OAUTH_CLIENT_SECRET: ${GOOGLE_OAUTH_CLIENT_SECRET:-}
      APPLE_OAUTH_CLIENT_ID: ${APPLE_OAUTH_CLIENT_ID:-}
      APPLE_OAUTH_TEAM_ID: ${APPLE_OAUTH_TEAM_ID:-}
      APPLE_OAUTH_KEY_ID: ${APPLE_OAUTH_KEY_ID:-}
      APPLE_OAUTH_PRIVATE_KEY: ${APPLE_OAUTH_PRIVATE_KEY:-}
    extra_hosts:
      # Lets MAILER_DSN=smtp://host.docker.internal:25 reach an MTA on the
      # host; harmless when unused. Works on Docker Engine variants where
      # host.docker.internal is not built in.
      - "host.docker.internal:host-gateway"
    volumes:
      # Logs and cache pools outlive the container; JWT keys outlive rebuilds.
      - php-var:/app/var
      - jwt-keys:/app/config/jwt
    depends_on:
      mysql:
        condition: service_healthy

  web:
    build:
      context: .
      dockerfile: docker/web/Dockerfile
    environment:
      WEB_MODE: ${WEB_MODE:-auto}
    ports:
      # The ONLY published ports of the whole stack -- mysql and php-fpm stay
      # on the internal network. Behind a local reverse proxy, set
      # WEB_BIND_ADDRESS=127.0.0.1 so nothing but the proxy can reach these.
      - "${WEB_BIND_ADDRESS:-0.0.0.0}:${WEB_HTTP_PORT:-80}:80"
      - "${WEB_BIND_ADDRESS:-0.0.0.0}:${WEB_TLS_PORT:-443}:443"
    volumes:
      - ./docker/certs-prod:/etc/nginx/certs:ro
    depends_on:
      - php

volumes:
  mysql-data:
  php-var:
  jwt-keys:
```

- [ ] **Step 2: Create `.env.prod.example`:**

```bash
# Production configuration for the Docker stack (docker-compose.prod.yml).
#
# Copy this file to .env.prod and fill in the values -- scripts/install.sh
# does both for you, including generating every secret it can. .env.prod is
# gitignored; this example documents NAMES and stays committed, so every
# secret below ends in `=` with nothing after it, and it must stay that way.
#
# Empty required values fail loudly: docker compose refuses to start and
# names the variable. See docs/docker-production.md for the full guide.

# The public origin users reach the app at, no trailing slash. Fills the SPA
# link base (mail links), the OAuth redirect base, and CLI-generated URLs.
# http://localhost works for a local or LAN instance; OAuth sign-in and
# Safari's Secure-cookie handling want a real HTTPS origin.
PUBLIC_URL=http://localhost

# How the app sends mail. REQUIRED, and there is no working default: the
# container ships no MTA, and a discarded mail reports success -- registration
# and password reset would silently go nowhere (issue #65).
#
#   Authenticated SMTP relay (your mail provider; URL-encode user and pass):
#     MAILER_DSN=smtp://user%40example.org:PASSWORD@smtp.example.org:587
#   An MTA on the Docker host itself (postfix/exim on localhost:25):
#     MAILER_DSN=smtp://host.docker.internal:25
MAILER_DSN=

# The From: header on account mail. Use an address on a domain whose mail the
# transport above is allowed to send, or it lands in spam.
MAIL_FROM=
MAIL_FROM_NAME="Simple Feed Reader"

# MySQL credentials. The passwords are used by the mysql container on FIRST
# start (they initialize the data volume) and by the app on every start.
# Changing them later means changing them inside MySQL too.
MYSQL_ROOT_PASSWORD=
MYSQL_PASSWORD=
MYSQL_USER=feedreader
MYSQL_DATABASE=feedreader

# Application secrets. Generate each one:  openssl rand -hex 32
# APP_SECRET seeds framework internals; ALTCHA_HMAC_KEY signs the
# proof-of-work on /register and /password-reset-request (the committed dev
# placeholder is public, so prod refuses to serve while it is in use);
# JWT_PASSPHRASE protects the login token signing key.
APP_SECRET=
ALTCHA_HMAC_KEY=
JWT_PASSPHRASE=

# Published ports -- the web container is the stack's ONLY published surface;
# MySQL and PHP are reachable solely on the internal Docker network. With
# certificates in docker/certs-prod/ the app serves TLS on WEB_TLS_PORT and
# redirects WEB_HTTP_PORT there; without certificates it serves plain HTTP on
# WEB_HTTP_PORT (for a reverse proxy, or localhost). WEB_MODE=tls|http
# overrides the automatic choice. With a reverse proxy on this same machine,
# set WEB_BIND_ADDRESS=127.0.0.1 so only the proxy can reach the stack.
WEB_HTTP_PORT=80
WEB_TLS_PORT=443
WEB_BIND_ADDRESS=0.0.0.0
WEB_MODE=auto

# Optional: enable the no-shell web setup for the FIRST admin. Leave empty
# when you use `app:admin:create` over the shell (recommended); remove the
# value again after setup. See docs/first-run-setup.md.
ADMIN_SETUP_SECRET=

# Optional: shared token for POST /maintenance/refresh (feed refresh without
# a logged-in browser). Empty is fail-closed: the endpoint refuses every call.
MAINTENANCE_TOKEN=

# Optional: OAuth sign-in. Leave blank to disable a provider. The redirect
# URI registered with the provider must be, exactly:
#   <PUBLIC_URL>/api/auth/oauth/google/callback
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=
APPLE_OAUTH_CLIENT_ID=
APPLE_OAUTH_TEAM_ID=
APPLE_OAUTH_KEY_ID=
APPLE_OAUTH_PRIVATE_KEY=
```

- [ ] **Step 3: Ignore rules.** Append to the root `.gitignore`:

```
# Operator secrets and TLS material for the production stack -- never commit.
/.env.prod
/docker/certs-prod/*
!/docker/certs-prod/.gitkeep
```

Create the empty `docker/certs-prod/.gitkeep` (the bind mount needs the directory to exist in every checkout).

- [ ] **Step 4: Verify the fail-loud contract**

Run (missing MAILER_DSN must name the variable):

```bash
cd "$(git rev-parse --show-toplevel)"
envtest=$(mktemp)
sed '/^MAILER_DSN=/d' .env.prod.example > "${envtest}"
printf 'PUBLIC_URL=http://x\nMYSQL_ROOT_PASSWORD=a\nMYSQL_PASSWORD=b\nAPP_SECRET=c\nALTCHA_HMAC_KEY=d\nJWT_PASSPHRASE=e\nMAIL_FROM=f@g.h\n' >> "${envtest}"
docker compose -p sfr-prod-configtest -f docker-compose.prod.yml --env-file "${envtest}" config > /dev/null
```

Expected: non-zero exit; error message contains `MAILER_DSN` and `set a real mail transport`.

Run (a filled file must validate):

```bash
printf 'PUBLIC_URL=http://x\nMAILER_DSN=smtp://h:25\nMAIL_FROM=f@g.h\nMYSQL_ROOT_PASSWORD=a\nMYSQL_PASSWORD=b\nAPP_SECRET=c\nALTCHA_HMAC_KEY=d\nJWT_PASSPHRASE=e\n' > "${envtest}"
docker compose -p sfr-prod-configtest -f docker-compose.prod.yml --env-file "${envtest}" config --services
```

Expected: `mysql php web`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add docker-compose.prod.yml .env.prod.example .gitignore docker/certs-prod/.gitkeep
git commit -m "feat(#65): standalone prod compose stack; MAILER_DSN required, Mailpit unreachable"
```

---

### Task 4: `scripts/lib.sh` — prod helpers

**Files:**
- Modify: `scripts/lib.sh`

**Interfaces:**
- Produces (used by Tasks 5–7): `prod_compose "$@"`, `ENV_PROD_FILE`, `env_prod_get KEY`, `env_prod_set KEY VALUE`, `env_prod_missing` (prints missing required names, one per line), `generate_secret`, `url_encode STRING`, `prompt_with_default QUESTION DEFAULT`, `prompt_value QUESTION`, `prompt_secret_value QUESTION`, `prod_certs_present`, `prod_base_url`, `wait_for_php_ready`, `wait_for_health [URL]` (existing callers keep the default), `print_prod_summary`.

- [ ] **Step 1: Generalize `wait_for_health`.** Replace its first line `local url='https://localhost:8443/api/health'` with:

```bash
  local url="${1:-https://localhost:8443/api/health}"
```

(`curl -fsk` handles http URLs unchanged; the `-k` stays irrelevant there.)

- [ ] **Step 2: Drop the preview line from `print_summary`.** Remove the line

```
    ./scripts/frontend-prod-start.sh   build & start the production preview (:8444)
```

and add, in its place:

```
    ./scripts/prod-start.sh            run the production stack (docs/docker-production.md)
```

- [ ] **Step 3: Append the prod helper section** to `scripts/lib.sh`:

```bash
# --- production stack -------------------------------------------------------
# The prod stack is a standalone compose file under its own project name, so
# it can never collide with (or inherit from) the dev stack. All three flags
# travel together -- always go through prod_compose.
ENV_PROD_FILE="${REPO_ROOT}/.env.prod"

prod_compose() {
  ( cd -- "${REPO_ROOT}" \
      && docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml \
           --env-file .env.prod "$@" )
}

# The value of KEY in .env.prod, '' when absent. Surrounding double quotes are
# stripped, matching how docker compose reads the file.
env_prod_get() {
  local line
  line=$(grep -E "^$1=" "${ENV_PROD_FILE}" 2>/dev/null | tail -n 1 || true)
  line=${line#*=}
  line=${line#\"}
  line=${line%\"}
  printf '%s' "${line}"
}

# Set KEY to VALUE in .env.prod, replacing the existing line or appending.
# Pure shell on purpose: sed -i differs between BSD and GNU, and awk -v
# mangles backslashes in values.
env_prod_set() {
  local key=$1 value=$2 tmp replaced=0 line
  tmp=$(mktemp)
  while IFS= read -r line; do
    case "${line}" in
      "${key}="*)
        printf '%s=%s\n' "${key}" "${value}"
        replaced=1
        ;;
      *)
        printf '%s\n' "${line}"
        ;;
    esac
  done < "${ENV_PROD_FILE}" > "${tmp}"
  if [ "${replaced}" -eq 0 ]; then
    printf '%s=%s\n' "${key}" "${value}" >> "${tmp}"
  fi
  mv "${tmp}" "${ENV_PROD_FILE}"
}

# The .env.prod values docker-compose.prod.yml refuses to start without.
# Keep in step with the ${VAR:?} interpolations there.
ENV_PROD_REQUIRED='PUBLIC_URL MAILER_DSN MAIL_FROM MYSQL_ROOT_PASSWORD MYSQL_PASSWORD APP_SECRET ALTCHA_HMAC_KEY JWT_PASSPHRASE'

# Names of required values that are still empty, one per line. Empty output
# means the file is complete.
env_prod_missing() {
  local key
  for key in ${ENV_PROD_REQUIRED}; do
    if [ -z "$(env_prod_get "${key}")" ]; then
      printf '%s\n' "${key}"
    fi
  done
}

# 64 hex characters of real randomness -- the same shape `openssl rand -hex 32`
# produces everywhere else in this project's docs.
generate_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 32
    return 0
  fi
  od -An -tx1 -N32 /dev/urandom | tr -d ' \n'
  printf '\n'
}

# Percent-encode for safe embedding in a DSN: RFC 3986 unreserved characters
# pass through, everything else becomes %XX. A raw '#' or '@' in a hand-typed
# DSN truncates it silently, which is why the installer never asks for one.
url_encode() {
  local raw=$1 out='' ch i
  for (( i = 0; i < ${#raw}; i++ )); do
    ch=${raw:i:1}
    case "${ch}" in
      [a-zA-Z0-9.~_-]) out="${out}${ch}" ;;
      *) out="${out}$(printf '%%%02X' "'${ch}")" ;;
    esac
  done
  printf '%s' "${out}"
}

# Interactive prompts. All read from /dev/tty, never stdin -- stdin is the
# script itself under `curl | bash`. Without a terminal they return the
# default (or nothing), so callers degrade to the two-step flow.
prompt_with_default() {
  local question=$1 default=$2 answer
  if [ ! -r /dev/tty ]; then
    printf '%s' "${default}"
    return 0
  fi
  printf '%s [%s]: ' "${question}" "${default}" >/dev/tty
  IFS= read -r answer </dev/tty || answer=''
  printf '%s' "${answer:-${default}}"
}

prompt_value() {
  local question=$1 answer
  if [ ! -r /dev/tty ]; then
    return 0
  fi
  printf '%s: ' "${question}" >/dev/tty
  IFS= read -r answer </dev/tty || answer=''
  printf '%s' "${answer}"
}

prompt_secret_value() {
  local question=$1 answer
  if [ ! -r /dev/tty ]; then
    return 0
  fi
  printf '%s: ' "${question}" >/dev/tty
  IFS= read -rs answer </dev/tty || answer=''
  printf '\n' >/dev/tty
  printf '%s' "${answer}"
}

prod_certs_present() {
  [ -f "${REPO_ROOT}/docker/certs-prod/fullchain.pem" ] \
    && [ -f "${REPO_ROOT}/docker/certs-prod/privkey.pem" ]
}

# The local base URL of the running prod stack (for probes and the summary).
# This is the LOCAL view; the public origin is PUBLIC_URL and may differ.
prod_base_url() {
  local port
  if prod_certs_present; then
    port=$(env_prod_get WEB_TLS_PORT)
    port=${port:-443}
    if [ "${port}" = "443" ]; then
      printf 'https://localhost'
    else
      printf 'https://localhost:%s' "${port}"
    fi
    return 0
  fi
  port=$(env_prod_get WEB_HTTP_PORT)
  port=${port:-80}
  if [ "${port}" = "80" ]; then
    printf 'http://localhost'
  else
    printf 'http://localhost:%s' "${port}"
  fi
}

# The prod php entrypoint writes var/.ready after the cache warmup; console
# commands (migrations, key generation) must not race it.
wait_for_php_ready() {
  local deadline=$(( SECONDS + 180 ))
  say 'Waiting for the PHP runtime ...'
  while [ "${SECONDS}" -lt "${deadline}" ]; do
    if prod_compose exec -T php test -f var/.ready 2>/dev/null; then
      ok 'PHP runtime is ready.'
      return 0
    fi
    sleep 2
  done
  die 'The PHP container did not become ready. Check:  docker compose -p simple-feed-reader-prod logs php'
}

print_prod_summary() {
  local base_url public_url
  base_url=$(prod_base_url)
  public_url=$(env_prod_get PUBLIC_URL)
  printf '\n%s\n\n' "${_c_bold}simple-feed-reader (production) is running${_c_reset}"
  printf '  Public URL ........  %s\n' "${public_url}"
  printf '  Local health ......  %s/api/health\n' "${base_url}"
  printf '\n'
  printf '  Create the first admin (docs/first-run-setup.md):\n'
  printf '    docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \\\n'
  printf '      exec -u www-data php bin/console app:admin:create you@example.com\n'
  printf '\n'
  printf '  Verify mail delivery (docs/docker-production.md):\n'
  printf '    docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \\\n'
  printf '      exec -u www-data php bin/console mailer:test you@example.com\n'
  printf '\n'
  printf '  Stop the stack (data is kept):  ./scripts/prod-stop.sh\n'
  printf '  Update to a new release:        see docs/docker-production.md\n'
  printf '\n'
}
```

- [ ] **Step 4: Verify the helpers in isolation**

```bash
bash -n scripts/lib.sh && shellcheck scripts/lib.sh
bash -c '
  set -euo pipefail
  source scripts/lib.sh
  ENV_PROD_FILE=$(mktemp)
  printf "A=1\nMAILER_DSN=\n" > "${ENV_PROD_FILE}"
  env_prod_set MAILER_DSN "smtp://u:p@h:587"
  env_prod_set NEW_KEY "value with spaces"
  [ "$(env_prod_get MAILER_DSN)" = "smtp://u:p@h:587" ]
  [ "$(env_prod_get NEW_KEY)" = "value with spaces" ]
  [ "$(url_encode "p@ss#w:rd")" = "p%40ss%23w%3Ard" ]
  s=$(generate_secret)
  [ "${#s}" -eq 64 ]
  missing=$(env_prod_missing)
  printf "%s" "${missing}" | grep -q PUBLIC_URL
  echo HELPERS-OK
'
```

Expected: `HELPERS-OK` (note: run from the repo root so `source scripts/lib.sh` resolves).

- [ ] **Step 5: Commit**

```bash
git add scripts/lib.sh
git commit -m "feat(#65): prod-stack helpers in scripts/lib.sh"
```

---

### Task 5: `prod-start.sh` / `prod-stop.sh`; preview scripts deleted

**Files:**
- Create: `scripts/prod-start.sh`
- Create: `scripts/prod-stop.sh`
- Delete: `scripts/frontend-prod-start.sh`, `scripts/frontend-prod-stop.sh`

**Interfaces:**
- Consumes: every helper from Task 4; the compose contract from Task 3; `var/.ready` from Task 1.
- Produces: `./scripts/prod-start.sh` (idempotent bring-up) and `./scripts/prod-stop.sh`; Task 6's installer and Task 7's updater call `prod-start.sh`.

- [ ] **Step 1: Create `scripts/prod-start.sh`:**

```bash
#!/usr/bin/env bash
set -euo pipefail

# Build and start the PRODUCTION stack (docker-compose.prod.yml): MySQL, the
# prod PHP image, and nginx serving the built SPA with /api same-origin.
# Configuration comes from .env.prod -- see .env.prod.example and
# docs/docker-production.md.
#
# Idempotent and safe to re-run: it rebuilds what changed, re-applies
# migrations, and never deletes data. Re-running it is also the update step
# after checking out a newer release, and the way to switch to TLS after
# dropping certificates into docker/certs-prod/.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

if [ ! -f "${ENV_PROD_FILE}" ]; then
  die "No .env.prod found. Copy .env.prod.example to .env.prod and fill it in -- see docs/docker-production.md. (scripts/install.sh does this for you.)"
fi

missing=$(env_prod_missing)
if [ -n "${missing}" ]; then
  warn 'These required values in .env.prod are still empty:'
  while IFS= read -r name; do
    printf '    %s\n' "${name}" >&2
  done <<< "${missing}"
  die 'Fill them in (see the comments in .env.prod.example), then re-run.'
fi

if prod_certs_present; then
  say 'TLS mode: certificates found in docker/certs-prod/.'
else
  say 'HTTP mode: no certificates in docker/certs-prod/ -- serving plain HTTP.'
  say 'Either put a TLS reverse proxy in front, or add fullchain.pem and'
  say 'privkey.pem to docker/certs-prod/ and re-run this script.'
fi

say 'Building and starting the production stack (the first build takes a few minutes) ...'
prod_compose up -d --build

wait_for_php_ready

say 'Ensuring JWT signing keys exist ...'
prod_compose exec -T -u www-data php bin/console lexik:jwt:generate-keypair --skip-if-exists

say 'Applying database migrations ...'
prod_compose exec -T -u www-data php bin/console doctrine:migrations:migrate --no-interaction

if wait_for_health "$(prod_base_url)/api/health"; then
  print_prod_summary
else
  warn 'The API did not report healthy in time. It may still be starting.'
  warn 'Check the logs with:  docker compose -p simple-feed-reader-prod logs -f php web'
fi
```

- [ ] **Step 2: Create `scripts/prod-stop.sh`:**

```bash
#!/usr/bin/env bash
set -euo pipefail

# Stop the production stack. Containers are removed; the data volumes (MySQL,
# logs and cache pools, JWT keys) are kept. Start again with prod-start.sh.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

if [ ! -f "${ENV_PROD_FILE}" ]; then
  die 'No .env.prod found -- there is no production stack to stop here.'
fi

say 'Stopping the production stack ...'
prod_compose down

ok 'Production stack stopped. Your data is kept.'
say 'Start it again with:  ./scripts/prod-start.sh'
```

- [ ] **Step 3:** `chmod +x scripts/prod-start.sh scripts/prod-stop.sh` and `git rm scripts/frontend-prod-start.sh scripts/frontend-prod-stop.sh`.

- [ ] **Step 4: Verify statically**

Run: `bash -n scripts/prod-start.sh scripts/prod-stop.sh && shellcheck scripts/*.sh`
Expected: no output, exit 0.

Run (the refusal path, no `.env.prod` present): `./scripts/prod-start.sh; echo "exit=$?"`
Expected: `error: No .env.prod found...` and `exit=1`.

(The full live run happens in Task 10 with a test `.env.prod`.)

- [ ] **Step 5: Commit**

```bash
git add -A scripts/
git commit -m "feat(#65): prod-start/prod-stop scripts; drop the preview scripts"
```

---

### Task 6: `install-dev.sh` (the old installer) and the new prod `install.sh`

**Files:**
- Create: `scripts/install-dev.sh` (today's `scripts/install.sh`, retitled)
- Rewrite: `scripts/install.sh` (the production installer)

**Interfaces:**
- Consumes: Task 4 helpers, Task 5's `prod-start.sh`, Task 3's `.env.prod.example`.
- Produces: the `curl | bash` production one-liner; `install-dev.sh` for the dev stack. README (Task 9) links both.

- [ ] **Step 1: Create `scripts/install-dev.sh`** as an exact copy of the current `scripts/install.sh`, with only the header comment changed to:

```bash
# One-line DEV installer for simple-feed-reader -- the full development stack:
# MySQL, the PHP API with xdebug, nginx with a locally trusted certificate,
# Mailpit, and the Angular dev server with live reload.
#
#   curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install-dev.sh | bash
#
# To run the app in PRODUCTION, use scripts/install.sh instead -- it sets up
# the production stack (real mail transport, no dev tooling).
#
# It clones the repository, checks out the latest release, and brings the
# stack up. Nothing here deletes data.
# Optional: pass a target directory. Default is ./simple-feed-reader.
```

Everything below the header stays byte-identical to today's `install.sh` (prerequisites incl. mkcert, clone, release checkout, CA confirm, certificate, `check_ports_free 4200 8080 8443 8025`, `bring_up_stack`, `wait_for_health`, `print_summary`). Then `chmod +x scripts/install-dev.sh`.

- [ ] **Step 2: Rewrite `scripts/install.sh`** as the production installer:

```bash
#!/usr/bin/env bash
set -euo pipefail

# One-line PRODUCTION installer for simple-feed-reader.
#
#   curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
#
# It clones the repository, checks out the latest release, writes .env.prod
# with freshly generated secrets, asks for the few values only you know (the
# public URL and how to send mail), and starts the production stack: MySQL,
# the production PHP image, and nginx serving the built app. Nothing here
# deletes data.
#
# Without a terminal (or when you skip the mail question) it stops after
# writing .env.prod and tells you exactly how to finish: edit the file, then
# run ./scripts/prod-start.sh.
#
# Looking for the DEVELOPMENT stack (live reload, xdebug, Mailpit)? Use
# scripts/install-dev.sh instead.
#
# Optional: pass a target directory. Default is ./simple-feed-reader.
#   curl -fsSL <url> | bash -s -- my-folder

REPO_URL="${SFR_REPO_URL:-https://github.com/larspohlmann/simple-feed-reader.git}"
TARGET_DIR="${1:-simple-feed-reader}"

# Minimal output helpers for the bootstrap phase, before lib.sh is available.
# Once the repository is cloned we source lib.sh, which defines the full set.
if [ -t 1 ]; then
  _b_blue=$'\033[34m' _b_red=$'\033[31m' _b_reset=$'\033[0m'
else
  _b_blue='' _b_red='' _b_reset=''
fi
say() { printf '%s\n' "${_b_blue}==>${_b_reset} $*"; }
die() { printf '%s\n' "${_b_red}error:${_b_reset} $*" >&2; exit 1; }

# Ask a yes/no question. Reads from the terminal, not stdin, because stdin is
# the script itself when this runs through `curl | bash`.
confirm() {
  local prompt="$1" answer
  if [ ! -r /dev/tty ]; then
    return 1
  fi
  printf '%s [y/N] ' "${prompt}" >/dev/tty
  read -r answer </dev/tty || return 1
  case "${answer}" in
    [yY] | [yY][eE][sS]) return 0 ;;
    *) return 1 ;;
  esac
}

# --- 1. prerequisites -------------------------------------------------------
say 'Checking prerequisites ...'

command -v git >/dev/null 2>&1 \
  || die 'git is not installed. Install it from https://git-scm.com/downloads and try again.'

command -v docker >/dev/null 2>&1 \
  || die 'Docker is not installed. Install Docker: https://docs.docker.com/get-docker/'
docker compose version >/dev/null 2>&1 \
  || die 'The Docker Compose plugin is missing. Update Docker, or install the compose plugin.'
docker info >/dev/null 2>&1 \
  || die 'Docker is installed but not running. Start the Docker daemon and try again.'

# --- 2. clone ---------------------------------------------------------------
if [ -e "${TARGET_DIR}" ]; then
  die "The directory '${TARGET_DIR}' already exists. To update an existing install, run:  cd ${TARGET_DIR} && ./scripts/update.sh"
fi

say "Cloning simple-feed-reader into ./${TARGET_DIR} ..."
git clone --quiet "${REPO_URL}" "${TARGET_DIR}"
cd "${TARGET_DIR}"

# From here on the repository is present, so use its shared helpers.
# shellcheck source=scripts/lib.sh
source scripts/lib.sh

# --- 3. check out the latest release ----------------------------------------
release_tag=$(latest_release_tag)
[ -n "${release_tag}" ] \
  || die 'No release tag (vX.Y.Z) exists on main yet. See docs/releasing.md, then re-run.'

say "Checking out the latest release: ${release_tag}"
git -C "${REPO_ROOT}" checkout --quiet "${release_tag}"

# --- 4. write .env.prod -----------------------------------------------------
if [ -f "${ENV_PROD_FILE}" ]; then
  die '.env.prod already exists in the fresh clone -- refusing to overwrite it.'
fi

say 'Writing .env.prod with freshly generated secrets ...'
cp "${REPO_ROOT}/.env.prod.example" "${ENV_PROD_FILE}"
env_prod_set APP_SECRET "$(generate_secret)"
env_prod_set ALTCHA_HMAC_KEY "$(generate_secret)"
env_prod_set JWT_PASSPHRASE "$(generate_secret)"
env_prod_set MYSQL_ROOT_PASSWORD "$(generate_secret)"
env_prod_set MYSQL_PASSWORD "$(generate_secret)"

# --- 5. the values only the operator knows ----------------------------------
public_url=$(prompt_with_default 'Public URL of this instance (as users will reach it)' 'http://localhost')
public_url=${public_url%/}
env_prod_set PUBLIC_URL "${public_url}"

# Derive a plausible From: domain from the public URL for the prompt default.
mail_host=${public_url#*://}
mail_host=${mail_host%%/*}
mail_host=${mail_host%%:*}

mail_choice='3'
if [ -r /dev/tty ]; then
  say 'How should the app send mail? Registration and password reset depend on it.'
  printf '  1) An SMTP relay (your mail provider): host, port, user, password\n' >/dev/tty
  printf "  2) This server's own MTA (postfix/exim listening on localhost:25)\n" >/dev/tty
  printf '  3) Later: I will edit .env.prod myself\n' >/dev/tty
  mail_choice=$(prompt_with_default 'Choice' '1')
fi

case "${mail_choice}" in
  1)
    smtp_host=$(prompt_value 'SMTP host (e.g. smtp.example.org)')
    smtp_port=$(prompt_with_default 'SMTP port' '587')
    smtp_user=$(prompt_value 'SMTP username')
    smtp_password=$(prompt_secret_value 'SMTP password (not echoed)')
    if [ -n "${smtp_host}" ] && [ -n "${smtp_user}" ] && [ -n "${smtp_password}" ]; then
      env_prod_set MAILER_DSN "smtp://$(url_encode "${smtp_user}"):$(url_encode "${smtp_password}")@${smtp_host}:${smtp_port}"
    else
      warn 'Incomplete SMTP details -- leaving MAILER_DSN for you to fill in.'
    fi
    ;;
  2)
    env_prod_set MAILER_DSN 'smtp://host.docker.internal:25'
    say 'Using the MTA on this machine. Delivery is only as good as its setup'
    say '(SPF, DKIM, reverse DNS) -- watch the first real mail.'
    ;;
  *)
    : # configure later -- the two-step fallback below handles it
    ;;
esac

if [ "${mail_choice}" = "1" ] || [ "${mail_choice}" = "2" ]; then
  mail_from=$(prompt_with_default 'From: address for account mail' "simple-feed-reader@${mail_host}")
  if [ -n "${mail_from}" ]; then
    env_prod_set MAIL_FROM "${mail_from}"
  fi
fi

# --- 6. start, or explain how to --------------------------------------------
missing=$(env_prod_missing)
if [ -n "${missing}" ]; then
  warn 'These required values in .env.prod are still empty:'
  while IFS= read -r name; do
    printf '    %s\n' "${name}" >&2
  done <<< "${missing}"
  say "Finish the setup in two steps:"
  say "  1. Edit ${TARGET_DIR}/.env.prod (the comments explain every value)."
  say "  2. Run:  cd ${TARGET_DIR} && ./scripts/prod-start.sh"
  exit 0
fi

"${REPO_ROOT}/scripts/prod-start.sh"

# --- 7. verify mail delivery ------------------------------------------------
# A wrong relay password should surface NOW, not at the first lost
# registration. mailer:test uses the real configured transport.
if [ "${mail_choice}" = "1" ] || [ "${mail_choice}" = "2" ]; then
  if confirm 'Send a test mail now to verify delivery?'; then
    recipient=$(prompt_value 'Recipient address')
    if [ -n "${recipient}" ]; then
      prod_compose exec -T -u www-data php bin/console mailer:test "${recipient}"
      ok "Test mail handed to the transport. Check the ${recipient} inbox (and its spam folder)."
    fi
  fi
fi
```

Note: `SFR_REPO_URL` exists so verification can clone from a local path; the default stays the GitHub URL.

- [ ] **Step 3: Verify statically**

Run: `bash -n scripts/install.sh scripts/install-dev.sh && shellcheck scripts/*.sh`
Expected: clean, exit 0.

Run (the existing-directory refusal, which exercises the script head without touching the network): `bash scripts/install.sh "$(basename "$(pwd)")" 2>&1; echo "exit=$?"` from the repo's parent directory — or more simply, from inside the repo: `bash scripts/install.sh . ; echo "exit=$?"`
Expected: `error: The directory '.' already exists...` and `exit=1`.

The full one-liner cannot be exercised before the next release exists: it checks out the latest `vX.Y.Z` tag, which predates these scripts and `.env.prod.example`. Its building blocks are covered elsewhere — the env/DSN/prompt helpers at the lib.sh level (Task 4), `prod-start.sh` live (Task 10). This is the release-lag caveat from Global Constraints; the PR body must repeat it.

- [ ] **Step 4: Commit**

```bash
git add scripts/install.sh scripts/install-dev.sh
git commit -m "feat(#65): install.sh installs production; install-dev.sh takes the dev stack"
```

---

### Task 7: `update.sh` updates what is installed

**Files:**
- Modify: `scripts/update.sh`

**Interfaces:**
- Consumes: `ENV_PROD_FILE` and `prod-start.sh` (Tasks 4–5); existing dev helpers.
- Produces: one updater for both stacks.

- [ ] **Step 1: Rework the post-checkout half.** Keep everything through the `git checkout` of the new tag (tag resolution, dirty-tree refusal, the lockfile blobs before and after) unchanged. Replace everything from `say 'Rebuilding images where their definitions changed ...'` to the end of the file with:

```bash
updated_any=0

# --- production stack -------------------------------------------------------
# A .env.prod marks a production install; prod-start.sh is idempotent and is
# exactly the update procedure (rebuild, migrate, health check).
if [ -f "${ENV_PROD_FILE}" ]; then
  say 'Updating the production stack ...'
  "${REPO_ROOT}/scripts/prod-start.sh"
  updated_any=1
fi

# --- development stack ------------------------------------------------------
# Any php container (running or stopped) under the dev project marks a dev
# install. Both stacks can exist on a developer machine; update both.
if [ -n "$(compose ps -aq php 2>/dev/null)" ]; then
  say 'Updating the development stack ...'
  say 'Rebuilding images where their definitions changed ...'
  compose up -d --build

  # Reinstall the frontend packages only when the lockfile actually changed;
  # the install runs into a named volume and is the slow part of an update.
  if [ "${lockfile_before}" != "${lockfile_after}" ]; then
    say 'Frontend lockfile changed -- refreshing node_modules ...'
    compose run --rm frontend npm ci
  fi

  say 'Installing backend dependencies ...'
  compose exec -T php composer install --no-interaction
  say 'Applying database migrations ...'
  compose exec -T php bin/console doctrine:migrations:migrate --no-interaction

  if wait_for_health; then
    ok "Development stack updated."
  else
    warn 'The API did not report healthy in time. Check:  docker compose logs -f php nginx'
  fi
  print_summary
  updated_any=1
fi

if [ "${updated_any}" -eq 0 ]; then
  warn 'No installed stack found (no .env.prod, no dev containers).'
  say 'The checkout is now on the new release; start a stack with'
  say './scripts/prod-start.sh (production) or ./scripts/install-dev.sh (development).'
fi

ok "Updated ${current} -> ${latest}."
```

Also update the header comment to say it updates the production stack, the development stack, or both, depending on what exists.

- [ ] **Step 2: Verify**

Run: `bash -n scripts/update.sh && shellcheck scripts/*.sh`
Expected: clean.

Run (no stacks, clean tree, already-latest short-circuit still works): `./scripts/update.sh || true`
Expected: either "Already on the latest release" (when the checkout sits on the release tag) or the dirty-tree/no-stack messages — never a compose invocation error.

- [ ] **Step 3: Commit**

```bash
git add scripts/update.sh
git commit -m "feat(#65): update.sh updates the prod and/or dev stack, whichever exists"
```

---

### Task 8: `docs/docker-production.md`

**Files:**
- Create: `docs/docker-production.md`

- [ ] **Step 1: Write the guide:**

````markdown
# Running in production (Docker)

The production stack is three containers — MySQL, the production PHP image,
and nginx serving the compiled app with `/api` handled same-origin — defined
in [`docker-compose.prod.yml`](../docker-compose.prod.yml). It is completely
separate from the [development stack](local-docker.md): its own compose file,
its own project name (`simple-feed-reader-prod`), its own volumes. Both can
run on the same machine.

**Why the separation is strict:** the dev stack injects
`MAILER_DSN=smtp://mailpit:1025` — every mail lands in a local inbox
(Mailpit) and never reaches a real mailbox. That is perfect for development
and catastrophic in production, where registration, admin approval, and
password reset all depend on real delivery (issue #65). The production stack
therefore *requires* a real mail transport before it starts, and Mailpit is
unreachable from it by construction.

---

## 1. Install

One command, on a machine with [Docker](https://docs.docker.com/get-docker/)
and [git](https://git-scm.com/downloads):

```bash
curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
```

The installer clones the project, checks out the latest release, generates
every secret it can (database passwords, signing keys), and asks for the few
values only you know:

- **The public URL** — how users reach the instance. `http://localhost` (the
  default) is fine for a local or LAN instance. For OAuth sign-in, and for
  Safari, use a real HTTPS origin.
- **How to send mail** — an SMTP relay (your mail provider's host, port,
  username, password), or the machine's own MTA if it runs one. There is no
  default: a feed reader that cannot send mail cannot register users or
  reset passwords, so this is asked up front. You can also answer "later";
  the installer then stops and tells you how to finish by hand.

At the end it offers to send a **test mail** — accept, and a wrong relay
password surfaces immediately instead of at the first lost registration.

Prefer doing it manually? Clone the repository, check out the latest
`vX.Y.Z` tag, copy `.env.prod.example` to `.env.prod`, fill it in (the
comments explain every value), and run `./scripts/prod-start.sh`.

## 2. TLS

The stack serves TLS itself when you give it a certificate, and plain HTTP
when you do not:

- **Bring a certificate** (recommended when nothing else terminates TLS):
  put `fullchain.pem` and `privkey.pem` — the Let's Encrypt names — into
  `docker/certs-prod/`, then run `./scripts/prod-start.sh` again. Port 443
  serves the app; port 80 redirects to it. After a certificate renewal,
  re-run `./scripts/prod-start.sh` (or `docker compose -p
  simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod
  restart web`) to load the new files.
- **Or put a reverse proxy in front** (Caddy, Traefik, nginx): leave
  `docker/certs-prod/` empty; the stack serves plain HTTP on port 80. In
  `.env.prod`, move the port off 80 (`WEB_HTTP_PORT=8080`) and bind it to
  loopback (`WEB_BIND_ADDRESS=127.0.0.1`) so only the proxy on this machine
  can reach it. Example Caddyfile:

  ```
  reader.example.org {
      reverse_proxy 127.0.0.1:8080
  }
  ```

Either way, set `PUBLIC_URL` in `.env.prod` to the HTTPS origin users
actually use — mail links and OAuth redirects are built from it.

## 3. First admin

A fresh instance has no administrator. Create the first one over the shell:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console app:admin:create you@example.com
```

The alternatives (and why the path is gated) are in
[first-run-setup.md](first-run-setup.md).

## 4. Verify mail delivery

Repeatable at any time, through the real configured transport:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console mailer:test you@example.com
```

The mail must arrive in that inbox (check spam on the first try). Common
`MAILER_DSN` shapes — set in `.env.prod`, URL-encode the username and
password if you write one by hand:

| Setup | DSN |
|---|---|
| Provider SMTP, STARTTLS | `smtp://user%40example.org:PASSWORD@smtp.example.org:587` |
| Provider SMTP, implicit TLS | `smtps://user%40example.org:PASSWORD@smtp.example.org:465` |
| MTA on the Docker host | `smtp://host.docker.internal:25` |

The host-MTA option delivers only as well as that MTA is set up (SPF, DKIM,
reverse DNS). If mail lands in spam, fix the MTA's reputation or switch to a
relay.

**Mailpit is a development tool.** It exists only in the dev stack's compose
file; the production stack cannot reach it, and no production configuration
should ever point at it.

## 5. Update

```bash
cd simple-feed-reader && ./scripts/update.sh
```

This checks out the newest release and re-runs the production bring-up
(rebuild, migrate, health check). Data is kept. `prod-start.sh` is
idempotent — running it again is always safe.

## 6. Backup

Everything worth keeping lives in three named volumes: the database
(`mysql-data`), logs and cache pools (`php-var`), and the JWT signing keys
(`jwt-keys`). A database dump before major updates:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec mysql sh -c 'exec mysqldump -ufeedreader -p"$MYSQL_PASSWORD" feedreader' > backup.sql
```

Losing `jwt-keys` is not fatal — new keys are generated on the next start —
but it signs every user out.

## 7. Troubleshooting

- **Compose refuses to start and names a variable** — that value is empty in
  `.env.prod`. The comments in `.env.prod.example` explain each one.
- **Every request answers 500** — the runtime guard refuses to serve while a
  committed placeholder is in use (`ALTCHA_HMAC_KEY`, `MAILER_DSN`). The log
  names the variable:
  `docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod exec php tail -n 50 var/log/prod.log`
- **Mail says sent but never arrives** — run the `mailer:test` check above;
  then check the spam folder, then the transport's own logs. With the
  host-MTA DSN, check the host's mail queue (`mailq`).
- **Port 80/443 already taken** — set `WEB_HTTP_PORT` / `WEB_TLS_PORT` in
  `.env.prod` and re-run `./scripts/prod-start.sh`.
````

- [ ] **Step 2: Commit**

```bash
git add docs/docker-production.md
git commit -m "docs(#65): production install guide"
```

---

### Task 9: Documentation sweep — README, local-docker, first-run-setup, frontend README

**Files:**
- Modify: `README.md`
- Modify: `docs/local-docker.md`
- Modify: `docs/first-run-setup.md`
- Modify: `frontend/README.md`

- [ ] **Step 1: README.md.** Replace the whole `## Quick start (Docker)` section (lines 15–55) with:

````markdown
## Run it (Docker)

Run your own instance with one command. You need
[Docker](https://docs.docker.com/get-docker/) (running) and
[git](https://git-scm.com/downloads). Then:

```bash
curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install.sh | bash
```

The installer clones the project into `./simple-feed-reader`, checks out the
latest release, generates the secrets it can, asks for the two things only
you know (your public URL and how to send mail), and starts the production
stack. The full guide — TLS, reverse proxies, mail verification, backups —
is [docs/docker-production.md](docs/docker-production.md).

> **Read before you pipe to bash.** You can inspect exactly what runs at
> [scripts/install.sh](scripts/install.sh). The installer never deletes data.

### Developing

For the development stack — live-reloading frontend, xdebug, and
[Mailpit](https://mailpit.axllent.org/) catching all outgoing mail locally —
use the dev installer instead (it additionally needs
[mkcert](https://github.com/FiloSottile/mkcert#installation)):

```bash
curl -fsSL https://raw.githubusercontent.com/larspohlmann/simple-feed-reader/main/scripts/install-dev.sh | bash
```

The manual walkthrough lives in [docs/local-docker.md](docs/local-docker.md).

### Everyday scripts

Run these from inside the `simple-feed-reader` directory:

| Task | Command |
|---|---|
| Update to the latest release (prod and/or dev) | `./scripts/update.sh` |
| Start / stop the production stack | `./scripts/prod-start.sh` / `./scripts/prod-stop.sh` |
| Start / stop the dev frontend (:4200) | `./scripts/frontend-start.sh` / `./scripts/frontend-stop.sh` |
| Stop the dev stack (keeps your data) | `docker compose down` |
````

In the `## Documentation` list, add after the local Docker bullet:

```markdown
- [Running in production (Docker)](docs/docker-production.md) — the prod
  stack: real mail transport, TLS or reverse proxy, updates, backups.
```

- [ ] **Step 2: docs/local-docker.md.**
  - §1: delete the paragraph about the sixth `prod`-profile service (lines 21–23) and the `8444` mention in §2 (line 43–44).
  - §3: change the callout's install URL from `scripts/install.sh` to `scripts/install-dev.sh`, and its lead sentence to "If you just want the development stack running, use the one-line dev installer instead …". In the same callout, change "and the four `frontend-*.sh` scripts" to "and the two `frontend-*.sh` scripts".
  - §8 "Extension points": replace the "**Production image.**" bullet with:
    ```markdown
    - **Production image.** Delivered — `docker/php/Dockerfile` has a `prod`
      target (no dev deps, baked-in source, tuned opcache) driven by
      `docker-compose.prod.yml`. See [docker-production.md](docker-production.md).
    ```
  - §9: delete the entire "### Production preview (`prod` profile)" subsection and replace it with:
    ```markdown
    ### Previewing the production topology

    The old `prod` profile (a production bundle served over the dev backend)
    is gone. To preview the real thing, run the actual production stack
    locally — [docker-production.md](docker-production.md) — with mkcert
    certificates: `mkcert -cert-file docker/certs-prod/fullchain.pem
    -key-file docker/certs-prod/privkey.pem localhost 127.0.0.1 ::1`, a
    `.env.prod` with test values (ports moved off 80/443 to avoid clashing
    with the dev stack), then `./scripts/prod-start.sh`. Unlike the old
    preview, this exercises the production PHP runtime too — `APP_ENV=prod`,
    no Mailpit, no xdebug.
    ```
  - Also delete the "OAuth caveat" paragraph that referenced `:8444` (it applied to the deleted preview).

- [ ] **Step 3: docs/first-run-setup.md.** After the existing Option 1 code block (`docker compose exec php bin/console app:admin:create you@example.com`), add:

````markdown
On the production stack the same command needs the prod project's flags:

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -u www-data php bin/console app:admin:create you@example.com
```
````

- [ ] **Step 4: frontend/README.md.** Replace the `prod` profile paragraph and code block (lines 36–45) with:

```markdown
The **production stack** serves the compiled bundle same-origin behind nginx —
see [docs/docker-production.md](../docs/docker-production.md); it can be run
locally with mkcert certificates to preview the production topology.
```

(Keep the surrounding dev-server prose; drop the `--profile prod` command and the `:8444` references.)

- [ ] **Step 5: Verify no stale references remain**

Run: `grep -rn "frontend-prod\|8444\|--profile prod" README.md docs/*.md frontend/README.md scripts/ docker/ docker-compose.yml | grep -v superpowers`
Expected: no output.

- [ ] **Step 6: Commit**

```bash
git add README.md docs/local-docker.md docs/first-run-setup.md frontend/README.md
git commit -m "docs(#65): user docs follow the prod path -- README, local-docker, first-run, frontend"
```

---

### Task 12: `prod-configure.sh` — reconfigure the installer's answers

**Execution order note:** added mid-plan at the user's request; executes after Task 9 and BEFORE Tasks 10–11 (the live verification and PR must include it).

**Files:**
- Create: `scripts/prod-configure.sh`
- Modify: `scripts/lib.sh` (shared configure functions; `confirm`)
- Modify: `scripts/install.sh` (sections 5 and 7 now call the shared functions)
- Modify: `scripts/prod-start.sh` (missing-values message mentions the new script)
- Modify: `docs/docker-production.md` (new Reconfigure section), `README.md` (script table row)
- NOT touched: `scripts/install-dev.sh` (stays a byte-copy of the old installer apart from its header)

**Interfaces:**
- Consumes: `env_prod_get/set`, `prompt_*`, `url_encode`, `prod_compose`, `ENV_PROD_FILE`, `say/ok/warn/die` from lib.sh; `scripts/prod-start.sh`.
- Produces: lib.sh functions `configure_public_url`, `configure_mail` (sets `CONFIGURED_MAIL_CHOICE`), `offer_mail_check`, `confirm`, `mask_dsn_password` — used by both `install.sh` and `prod-configure.sh`.

**Scope guard (deliberate):** the script re-asks only the operator values (public URL, mail transport, From: address), each defaulting to the current `.env.prod` value. Secrets are NOT touched: regenerating `JWT_PASSPHRASE` would lock the existing signing key, and the MySQL passwords already initialized the database volume. Ports/optional values stay hand-edits.

- [ ] **Step 1: Add the shared configure functions to `scripts/lib.sh`** (append to the prod-helper section):

```bash
# Ask a yes/no question on the terminal. Mirrors the installers' bootstrap
# helper so post-clone code can rely on it via lib.sh alone.
confirm() {
  local prompt="$1" answer
  if [ ! -r /dev/tty ]; then
    return 1
  fi
  printf '%s [y/N] ' "${prompt}" >/dev/tty
  read -r answer </dev/tty || return 1
  case "${answer}" in
    [yY] | [yY][eE][sS]) return 0 ;;
    *) return 1 ;;
  esac
}

# A DSN safe to print: the password between the userinfo ':' and the '@' is
# replaced with ***. DSNs without credentials pass through unchanged.
mask_dsn_password() {
  local dsn=$1 scheme rest userinfo hostpart
  case "${dsn}" in
    *://*@*)
      scheme=${dsn%%://*}
      rest=${dsn#*://}
      userinfo=${rest%@*}
      hostpart=${rest##*@}
      case "${userinfo}" in
        *:*) userinfo="${userinfo%%:*}:***" ;;
      esac
      printf '%s://%s@%s' "${scheme}" "${userinfo}" "${hostpart}"
      ;;
    *)
      printf '%s' "${dsn}"
      ;;
  esac
}

# The interactive configuration the installer and prod-configure.sh share.
# Each question defaults to the CURRENT .env.prod value where one exists and
# writes the answer back with env_prod_set. Without a terminal the prompts
# degrade to their defaults (configure_mail changes nothing at all), so the
# installer's two-step fallback keeps working.

configure_public_url() {
  local current public_url
  current=$(env_prod_get PUBLIC_URL)
  public_url=$(prompt_with_default 'Public URL of this instance (as users will reach it)' "${current:-http://localhost}")
  env_prod_set PUBLIC_URL "${public_url%/}"
}

# Which transport the last configure_mail round set: 1 relay, 2 host MTA,
# empty = left as it was. offer_mail_check reads it.
CONFIGURED_MAIL_CHOICE=''

configure_mail() {
  local current_dsn choice smtp_host smtp_port smtp_user smtp_password
  local public_url mail_host current_from mail_from
  CONFIGURED_MAIL_CHOICE=''
  if [ ! -r /dev/tty ]; then
    return 0
  fi
  current_dsn=$(env_prod_get MAILER_DSN)
  say 'How should the app send mail? Registration and password reset depend on it.'
  if [ -n "${current_dsn}" ]; then
    say "Currently: $(mask_dsn_password "${current_dsn}")"
  fi
  printf '  1) An SMTP relay (your mail provider): host, port, user, password\n' >/dev/tty
  printf "  2) This server's own MTA (postfix/exim listening on localhost:25)\n" >/dev/tty
  printf '  3) Skip: leave the mail transport as it is\n' >/dev/tty
  choice=$(prompt_with_default 'Choice' '1')
  case "${choice}" in
    1)
      smtp_host=$(prompt_value 'SMTP host (e.g. smtp.example.org)')
      smtp_port=$(prompt_with_default 'SMTP port' '587')
      smtp_user=$(prompt_value 'SMTP username')
      smtp_password=$(prompt_secret_value 'SMTP password (not echoed)')
      if [ -n "${smtp_host}" ] && [ -n "${smtp_user}" ] && [ -n "${smtp_password}" ]; then
        env_prod_set MAILER_DSN "smtp://$(url_encode "${smtp_user}"):$(url_encode "${smtp_password}")@${smtp_host}:${smtp_port}"
        CONFIGURED_MAIL_CHOICE=1
      else
        warn 'Incomplete SMTP details -- leaving MAILER_DSN unchanged.'
      fi
      ;;
    2)
      env_prod_set MAILER_DSN 'smtp://host.docker.internal:25'
      CONFIGURED_MAIL_CHOICE=2
      say 'Using the MTA on this machine. Delivery is only as good as its setup'
      say '(SPF, DKIM, reverse DNS) -- watch the first real mail.'
      ;;
    *)
      : # keep the current transport
      ;;
  esac
  if [ -n "${CONFIGURED_MAIL_CHOICE}" ]; then
    public_url=$(env_prod_get PUBLIC_URL)
    mail_host=${public_url#*://}
    mail_host=${mail_host%%/*}
    mail_host=${mail_host%%:*}
    current_from=$(env_prod_get MAIL_FROM)
    mail_from=$(prompt_with_default 'From: address for account mail' "${current_from:-simple-feed-reader@${mail_host}}")
    if [ -n "${mail_from}" ]; then
      env_prod_set MAIL_FROM "${mail_from}"
    fi
  fi
}

# Offer a live delivery check when configure_mail just set a transport. A
# wrong relay password should surface NOW, not at the first lost mail.
offer_mail_check() {
  local recipient
  if [ -z "${CONFIGURED_MAIL_CHOICE}" ]; then
    return 0
  fi
  if ! confirm 'Send a test mail now to verify delivery?'; then
    return 0
  fi
  recipient=$(prompt_value 'Recipient address')
  if [ -n "${recipient}" ]; then
    prod_compose exec -T -u www-data php bin/console mailer:test "${recipient}"
    ok "Test mail handed to the transport. Check the ${recipient} inbox (and its spam folder)."
  fi
}
```

- [ ] **Step 2: Create `scripts/prod-configure.sh`** (executable):

```bash
#!/usr/bin/env bash
set -euo pipefail

# Reconfigure an existing production install: re-ask the questions
# scripts/install.sh asked -- the public URL, how mail is sent, the From:
# address -- each defaulting to the current .env.prod value, then apply by
# re-running prod-start.sh and offer the same mail-delivery check.
#
# Secrets and passwords are deliberately NOT touched. Regenerating
# JWT_PASSPHRASE would lock the existing signing key, and the MySQL
# passwords initialized the database volume -- changing them here would not
# change them inside MySQL. Ports and optional values are a hand edit in
# .env.prod (see .env.prod.example), applied with ./scripts/prod-start.sh.

_dir=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# shellcheck source=scripts/lib.sh
source "${_dir}/lib.sh"

ensure_docker

if [ ! -f "${ENV_PROD_FILE}" ]; then
  die 'No .env.prod found -- nothing to reconfigure. Run scripts/install.sh first, or copy .env.prod.example to .env.prod.'
fi

if [ ! -r /dev/tty ]; then
  die 'prod-configure.sh is interactive and needs a terminal.'
fi

configure_public_url
configure_mail

say 'Applying the configuration ...'
"${REPO_ROOT}/scripts/prod-start.sh"

offer_mail_check
```

- [ ] **Step 3: Refactor `scripts/install.sh` onto the shared functions.** Replace section 5 (everything from `# --- 5. the values only the operator knows` up to but excluding `# --- 6.`) with:

```bash
# --- 5. the values only the operator knows ----------------------------------
configure_public_url
configure_mail
```

In section 6, replace the two-step instruction lines with:

```bash
  say "Finish the setup in two steps:"
  say "  1. Run:  cd ${TARGET_DIR} && ./scripts/prod-configure.sh   (asks again, then starts)"
  say "     or edit ${TARGET_DIR}/.env.prod by hand (the comments explain every value)."
  say "  2. Hand-edited? Then run:  cd ${TARGET_DIR} && ./scripts/prod-start.sh"
```

Replace section 7 (everything from `# --- 7. verify mail delivery` to the end) with:

```bash
# --- 7. verify mail delivery ------------------------------------------------
offer_mail_check
```

The bootstrap `confirm()` at the top of install.sh stays (needed pre-clone; lib.sh's identical definition takes over after sourcing). The now-unused `mail_choice`/`mail_host` locals disappear with section 5.

- [ ] **Step 4: `scripts/prod-start.sh` message.** Change the missing-values `die` line to:

```bash
  die 'Fill them in: run ./scripts/prod-configure.sh, or edit .env.prod (see .env.prod.example), then re-run.'
```

- [ ] **Step 5: Docs.** In `docs/docker-production.md`, insert after the `## 5. Update` section:

````markdown
## 6. Reconfigure

To change the public URL or the mail settings later, re-run the installer's
questions against the existing install:

```bash
cd simple-feed-reader && ./scripts/prod-configure.sh
```

Each question defaults to the current value; the script applies the answers
(the same idempotent bring-up as an update) and offers the mail check from
§4. Secrets and passwords are deliberately not touched: regenerating
`JWT_PASSPHRASE` would lock the existing signing key, and the MySQL
passwords already initialized the database volume. Ports and optional
values are a hand edit in `.env.prod` (the comments in `.env.prod.example`
explain each one), applied with `./scripts/prod-start.sh`.
````

Renumber the following sections (Backup → 7, Troubleshooting → 8). In `README.md`'s Everyday-scripts table, add after the prod start/stop row:

```markdown
| Change the public URL / mail settings | `./scripts/prod-configure.sh` |
```

- [ ] **Step 6: Verify**

Run: `bash -n scripts/*.sh && shellcheck scripts/*.sh`
Expected: clean at every severity.

Run (refusal without .env.prod — none exists in the checkout): `./scripts/prod-configure.sh; echo "exit=$?"`
Expected: `error: No .env.prod found -- nothing to reconfigure...` and `exit=1`.

Run (shared-function unit checks, from the repo root):

```bash
bash -c '
  set -euo pipefail
  source scripts/lib.sh
  [ "$(mask_dsn_password "smtp://user:secret@smtp.example.org:587")" = "smtp://user:***@smtp.example.org:587" ]
  [ "$(mask_dsn_password "smtp://host.docker.internal:25")" = "smtp://host.docker.internal:25" ]
  ENV_PROD_FILE=$(mktemp)
  printf "PUBLIC_URL=\nMAILER_DSN=\n" > "${ENV_PROD_FILE}"
  configure_public_url </dev/null
  [ "$(env_prod_get PUBLIC_URL)" = "http://localhost" ] || [ -n "$(env_prod_get PUBLIC_URL)" ]
  echo CONFIGURE-HELPERS-OK
'
```

Expected: `CONFIGURE-HELPERS-OK`. (Note: with a readable /dev/tty this test would prompt; if it does, answer with Enter — the assertion accepts the default or a typed value. In a non-TTY run it passes silently.)

Run (`install-dev.sh` untouched): `git diff --stat HEAD -- scripts/install-dev.sh`
Expected: no output.

- [ ] **Step 7: Commit**

```bash
git add scripts/lib.sh scripts/prod-configure.sh scripts/install.sh scripts/prod-start.sh docs/docker-production.md README.md
git commit -m "feat(#65): prod-configure.sh reconfigures URL and mail; shared prompt flow in lib.sh"
```

---

### Task 10: Live verification of the whole path

No new files — this task proves the stack on the running Docker host and cleans up after itself. It needs the Docker daemon and takes several minutes (two image builds).

- [ ] **Step 1: Static gate over everything**

```bash
shellcheck scripts/*.sh && bash -n scripts/*.sh
docker compose config > /dev/null
```

Expected: clean.

- [ ] **Step 2: Prepare an isolated test config.** Ports are moved so the dev stack can keep running; mail goes to a throwaway catcher started just for this test:

```bash
cd "$(git rev-parse --show-toplevel)"
docker run -d --name sfr-prod-mailcheck -p 127.0.0.1:11025:1025 -p 127.0.0.1:11080:8025 axllent/mailpit:latest
cat > .env.prod <<EOF
PUBLIC_URL=http://localhost:8081
MAILER_DSN=smtp://host.docker.internal:11025
MAIL_FROM=prod-smoke@example.org
MAIL_FROM_NAME="Prod Smoke"
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)
MYSQL_PASSWORD=$(openssl rand -hex 16)
MYSQL_USER=feedreader
MYSQL_DATABASE=feedreader
APP_SECRET=$(openssl rand -hex 32)
ALTCHA_HMAC_KEY=$(openssl rand -hex 32)
JWT_PASSPHRASE=$(openssl rand -hex 32)
WEB_HTTP_PORT=8081
WEB_TLS_PORT=8445
WEB_BIND_ADDRESS=127.0.0.1
WEB_MODE=auto
ADMIN_SETUP_SECRET=$(openssl rand -hex 32)
EOF
```

(The catcher stands in for "an SMTP endpoint set explicitly in `.env.prod`" — mail arriving THERE proves the DSN is honoured and nothing falls back to a hidden default.)

- [ ] **Step 3: Bring the prod stack up (HTTP mode)**

Run: `./scripts/prod-start.sh`
Expected: "HTTP mode" message; both builds succeed; JWT keys generated; migrations applied; health OK; the prod summary prints.

Run: `curl -fs http://localhost:8081/api/health && curl -fs http://localhost:8081/ | head -c 200`
Expected: `{"status":"ok"}`; the second returns the SPA's `index.html`.

- [ ] **Step 4: Prove prod is prod**

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -T php php -r 'echo getenv("APP_ENV"), " xdebug:", extension_loaded("xdebug") ? "yes" : "no", PHP_EOL;'
```

Expected: `prod xdebug:no`

Run (the minimal-port-surface check — the user requirement that only `web` publishes anything):

```bash
docker ps --filter label=com.docker.compose.project=simple-feed-reader-prod --format '{{.Names}}\t{{.Ports}}'
```

Expected: the `web` container shows exactly the two loopback mappings (`127.0.0.1:8081->80`, `127.0.0.1:8445->443`); the `mysql` and `php` lines show no host mappings at all.

- [ ] **Step 5: Prove the mail transport is honoured (the #65 check)**

```bash
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod \
  exec -T -u www-data php bin/console mailer:test smoke-recipient@example.org
sleep 2
curl -fs http://127.0.0.1:11080/api/v1/messages | grep -c 'smoke-recipient@example.org'
```

Expected: `mailer:test` succeeds; the grep count is at least `1` — the message arrived at the explicitly configured endpoint.

- [ ] **Step 6: First-admin bootstrap works on the prod stack.** `app:admin:create` reads the password via `askHidden`, which returns nothing over piped stdin — so the scripted smoke uses the #64 web setup endpoint instead (which this also regression-tests on a real prod runtime; `ADMIN_SETUP_SECRET` is in the test env from Step 2):

```bash
secret=$(grep '^ADMIN_SETUP_SECRET=' .env.prod | cut -d= -f2-)
curl -fs -X POST http://localhost:8081/api/setup/admin \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"prod-smoke-admin@example.org\",\"password\":\"test-admin-password-123\",\"secret\":\"${secret}\"}"
```

Expected: a 2xx JSON response (the bootstrap admin is created). `app:admin:create` itself stays the documented interactive path — operators have a TTY.

- [ ] **Step 7: TLS mode**

```bash
mkcert -cert-file docker/certs-prod/fullchain.pem -key-file docker/certs-prod/privkey.pem localhost 127.0.0.1 ::1
./scripts/prod-start.sh
curl -fsk https://localhost:8445/api/health
curl -fsk -o /dev/null -w '%{http_code} %{redirect_url}\n' http://localhost:8081/api/health
```

Expected: "TLS mode" message; health `{"status":"ok"}` over https; the http request answers `301 https://localhost/api/health` — note: no port, because nginx's `$host` strips it. On a real deployment (default ports) that target is exactly right; with shifted smoke ports the redirect target is not followable, which is fine for this check.

- [ ] **Step 8: Idempotency + stop/start**

Run: `./scripts/prod-start.sh` (again, no changes)
Expected: completes quickly, no errors, no duplicate work visible (keypair "skipped", migrations "already at the latest version").

Run: `./scripts/prod-stop.sh && ./scripts/prod-start.sh && curl -fsk https://localhost:8445/api/health`
Expected: stack stops and comes back healthy; data survived — repeating the Step 6 `POST /api/setup/admin` now answers a non-2xx `application/problem+json` (an admin already exists, so setup is unavailable).

- [ ] **Step 9: Dev-stack regression**

```bash
docker compose up -d
docker compose exec -T php vendor/bin/phpunit
cd backend && composer e2e && cd ..
```

Expected: dev stack healthy alongside the prod stack; MySQL suite green (the known order-dependent rate-limiter flakes pass in isolation and are not a regression); e2e green.

- [ ] **Step 10: Clean up the test artifacts**

```bash
./scripts/prod-stop.sh
docker compose -p simple-feed-reader-prod -f docker-compose.prod.yml --env-file .env.prod down -v || true
docker rm -f sfr-prod-mailcheck
rm -f .env.prod docker/certs-prod/fullchain.pem docker/certs-prod/privkey.pem
```

(`down -v` is correct HERE and only here: this is the throwaway smoke-test database on the developer machine, never a user's. The helper scripts themselves still never run it.)

- [ ] **Step 11: Commit anything the verification required fixing; otherwise nothing to commit.**

---

### Task 11: PR

- [ ] **Step 1:** Push the branch and open a PR against `develop` titled `feat(#65): genuine Docker production path — prod image, prod stack, prod installer`. Body: what changed (the table of new/deleted files, including `prod-configure.sh` from Task 12), the #65 acceptance-criteria mapping from the spec, the verification evidence from Task 10, and the **release-lag note**: the rewritten `install.sh`/`update.sh` resolve the latest release tag — and NO plain `vX.Y.Z` release exists yet (only `-dev.N` deploy tags), so the one-liner delivers the prod path only after the FIRST release is cut from a `main` that contains it. Include `Closes #65`.

- [ ] **Step 2:** Wait for CI (Backend sqlite, Backend mysql, Frontend, Shell scripts) — re-read the conclusions, do not trust `gh run watch --exit-status`.
