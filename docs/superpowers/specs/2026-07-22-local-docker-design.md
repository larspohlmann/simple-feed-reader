# Local Docker Development Environment — Design

**Date:** 2026-07-22
**Status:** Approved (brainstormed with Lars)

## Purpose

Run simple-feed-reader locally in Docker as a daily development environment, shaped so
the same images can later become a containerized production deployment option.
Production stays on Strato shared hosting for now; Docker must remain **additive** —
the existing native workflow (PHP 8.3 on the host, SQLite, `php -S` / symfony CLI)
keeps working unchanged.

Decisions this design encodes (user choices, 2026-07-22):

- **Role:** both dev environment and future-prod seed, dev-first.
- **Database:** MySQL 8.4 container — same image as the CI MySQL leg and the eventual
  production DB, so collation/dialect issues surface during normal dev.
- **Supporting services:** Mailpit and local HTTPS. No Adminer, no worker/cron
  container (deferred to plans 4/6).
- **Topology:** nginx + PHP-FPM in separate containers.

## Architecture

One `docker-compose.yml` at the repo root; container build/config files under
`docker/`. Four services:

### 1. `php`

- Built from `docker/php/Dockerfile`, base image `php:8.3-fpm-alpine`.
  8.3 matches `composer config.platform` — code that only parses on 8.4 must fail
  here, exactly as it would in CI and on Strato.
- Extensions: `pdo_mysql`, `intl`, `opcache`, `zip`. Composer binary copied from the
  official `composer` image.
- `backend/` bind-mounted to `/app` — host edits are live, no rebuild per change.
- Tests, `composer cs` / `composer stan`, and console commands run via
  `docker compose exec php …`.

### 2. `nginx`

- `nginx:1.27-alpine`, config at `docker/nginx/default.conf`.
- Serves `backend/public`, fastcgi-passes `index.php` to `php:9000`.
- TLS terminates here: **https://localhost:8443**, certificate generated with
  **mkcert** into git-ignored `docker/certs/`. A browser-trusted `Secure` context is
  required by the `__Host-oauth_flow` cookie (Safari does not grant `Secure`-cookie
  leniency to plain `http://localhost` the way Chrome does).
- Plain HTTP on :8080 redirects to https.

### 3. `mysql`

- `mysql:8.4` (identical to the CI matrix leg), named volume for data.
- Healthcheck-gated; `php` waits for healthy before starting.

### 4. `mailpit`

- Catches all outgoing mail (double opt-in, password reset, admin notifications).
- Web inbox at http://localhost:8025, SMTP on `mailpit:1025`.

## Configuration strategy

Compose injects `DATABASE_URL` (→ mysql) and `MAILER_DSN` (→ mailpit) as container
environment variables. Committed `.env` / `.env.test` files stay untouched: outside
Docker nothing changes, inside Docker real env vars override file values (Symfony
precedence). No `.env.local` editing required.

Concrete dev-only values (never reused anywhere real): database `feedreader`, user
`feedreader`, password `feedreader`. In the test environment Doctrine's configured
`dbname_suffix` appends `_test` (the same mechanism CI relies on), so in-container
PHPUnit runs hit `feedreader_test` and never truncate the dev data; the MySQL init
step must create both databases and grant the user rights on both.

## Deliberately out of scope (YAGNI)

- **No prod image stage yet.** The Dockerfile is written so a multi-stage `prod`
  target can be added when plan 6 revisits deployment.
- **No worker/cron container** for feed refresh — arrives with plans 4/6.
- **No Xdebug**, no Adminer, no Angular frontend service (plan 5 will add the
  frontend; nginx or a dedicated service can then split paths on the same origin,
  which is the same-site layout the OAuth cookie needs).

## Documentation

`docs/local-docker.md`: prerequisites (Docker Desktop, mkcert), one-time cert
generation, first-run steps (`composer install`, `doctrine:migrations:migrate`),
command cheat-sheet, and a note on the future prod-stage/frontend extension points.

## Definition of done

1. `docker compose up` from a clean checkout (after documented one-time steps) serves
   **https://localhost:8443/api/health** as healthy against MySQL.
2. A registration mail lands in the Mailpit inbox.
3. The full PHPUnit suite passes **inside the container on the MySQL leg**.
4. Native workflow untouched: host-side SQLite tests still pass with no config edits.
