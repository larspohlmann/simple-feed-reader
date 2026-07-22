# End-to-End Test Suite — Design

**Date:** 2026-07-22
**Status:** Approved (brainstormed with Lars)

## Purpose

A small black-box end-to-end suite that exercises the backend's HTTP API through
the **real local Docker stack** (nginx TLS → php-fpm → MySQL → Mailpit), covering
the features built so far. It is distinct in kind from the existing PHPUnit
functional tests, which are white-box: they boot the kernel, reach into container
services, and run against SQLite. The e2e suite talks only HTTP over
`https://localhost:8443` and asserts on real responses, real MySQL behaviour,
real SMTP delivery to Mailpit, and real TLS/cookie handling — catching
integration faults the in-kernel tests structurally cannot.

Decisions this design encodes (user choices, 2026-07-22):

- **Tooling:** PHP + PHPUnit, black-box via Symfony HttpClient, run from the host.
- **Where it runs:** local-only, invoked explicitly; excluded from the default
  `vendor/bin/phpunit` run and from the existing CI legs. A CI job may be added
  later (plan 6).
- **Coverage:** core happy paths plus a few key guards (~10 tests).
- **Standing rule:** the suite is kept up to date — every new feature/endpoint
  gets e2e coverage added here as development continues.

## Architecture

### Runner & isolation

- New `backend/tests/E2e/` directory with its own `backend/phpunit-e2e.xml.dist`.
  A **separate testsuite** so the default `vendor/bin/phpunit` (host, SQLite, 542
  tests) is byte-for-byte unaffected and never needs the Docker stack.
- One entry point, `backend/bin/e2e.sh` (also exposed as a `composer e2e`
  script), which:
  1. asserts the stack is up and healthy (fails fast with a clear message if not),
  2. runs the fixtures step (below),
  3. runs the e2e testsuite from the host against `https://localhost:8443`.
- TLS is verified normally (mkcert root is trusted on the host); no `-k` /
  `verify_peer: false`. A TLS failure is a real finding, not something to mask.
- Base URL is read from `E2E_BASE_URL` (default `https://localhost:8443`) and the
  Mailpit base from `E2E_MAILPIT_URL` (default `http://localhost:8025`), so the
  same suite can later point at a staging host.

### Fixtures (the pieces the API cannot create for itself)

- **`app:e2e:seed-admin`** — a new console command that idempotently creates or
  promotes one known active admin (email + password from args/env with e2e
  defaults) via the ORM, so schema changes never break it. It **refuses to run
  when `APP_ENV=prod`**, so it can never become a production backdoor. This
  unblocks the approve/suspend admin flows, for which there is otherwise no way
  in — the app ships no admin-creation endpoint or command.
- **Cache-pool reset** — before the run, clear the rate-limiter and ALTCHA-replay
  cache pools (`cache.rate_limiter`, the login-throttling pool, `altcha.replay.cache`).
  Repeated runs from one host IP otherwise trip the per-IP limiters on
  `/register` and `/login` and the suite goes flaky. This mirrors what the
  in-kernel tests neutralise in `setUp`.

### Test harness

- `E2eTestCase` (base): a shared `HttpClient` to the app and a `MailpitClient`
  to Mailpit's API; a unique-email generator (`e2e-<uniqid>@example.com`) so runs
  never collide and no DB reset is required.
- `MailpitClient`: thin wrapper over `GET /api/v1/search?query=to:<addr>` and
  `GET /api/v1/message/<id>` to fetch the latest message for a recipient and read
  its text body.
- `AltchaSolver` (HTTP variant): fetch `GET /api/auth/altcha-challenge`, brute-force
  the number `n` where `sha256(salt . n) === challenge`, and assemble the base64
  `altcha` payload the API expects. Pure HTTP — no container access.
- Helpers: `register()`, `tokenFromMail(recipient)` (scrape the plain token out
  of the emailed link), `login() → JWT`, `authGet()/authPost()`.

## Tests (core happy paths + key guards, ~10)

1. **Health** — `GET /api/health` → 200 `{"status":"ok"}` over real TLS.
2. **Onboarding journey** — altcha-challenge → `POST /api/auth/register` (202) →
   verification email in Mailpit → `POST /api/auth/verify-email` (status
   `pending_approval`) → admin `POST /api/admin/users/{id}/approve` (status
   `active`) → `POST /api/auth/login` → JWT → `GET /api/me`.
3. **Password reset across the stack** — activate a user, log in (JWT₁), request
   a reset, read the reset token from Mailpit, `POST /api/auth/password-reset`,
   then assert JWT₁ is now **rejected** on `/api/me` (the pre-reset-token
   revocation property, `password_changed_at`) and the new password logs in.
4. **OAuth providers** — `GET /api/auth/oauth/providers` → 200 JSON list (empty
   without configured credentials is a valid result).
5. **Guards**:
   - Unsolved ALTCHA on `/register` → 422 with `content-type:
     application/problem+json` and an `errors.altcha` entry; and it creates no
     user (a subsequent solved registration for the same address still succeeds).
   - An unauthenticated `GET /api/me` is rejected with 401. Whether the firewall
     entry point emits the RFC 7807 `application/problem+json` shape is verified
     against the running app first (the implementer checks the real response) and
     asserted only if so; the unsolved-ALTCHA 422 above is the guaranteed
     problem+json assertion, so the error contract is covered regardless.

The admin id in test 2 comes from the admin users list
(`GET /api/admin/users`) filtered to the freshly registered address, so nothing
is assumed about auto-increment values.

## Documentation & the standing rule

- `backend/tests/E2e/README.md`: prerequisites (stack up via `docker compose up
  -d`, mkcert installed), how to run (`composer e2e`), the env-var overrides, and
  the explicit rule: **every new feature or endpoint gets an e2e test added
  here.** Recorded to memory so it carries across sessions and into the
  subagent-driven workflow (future feature tasks include "extend the e2e suite").

## Deliberately out of scope (YAGNI)

- No CI job yet (local-only; revisit with plan 6 deployment).
- No frontend/browser e2e (Playwright etc.) — that belongs with plan 5's SPA.
- No load/performance testing, no dedicated e2e database (unique emails +
  idempotent admin make the shared dev DB sufficient; the DB is never reset).

## Definition of done

1. `composer e2e` against the running stack goes green (all ~10 tests).
2. `vendor/bin/phpunit` is unaffected — still 542 tests, still needs no stack.
3. The existing CI legs are unchanged.
4. A second consecutive `composer e2e` run is also green (fixtures reset makes it
   idempotent; unique emails avoid collisions).
