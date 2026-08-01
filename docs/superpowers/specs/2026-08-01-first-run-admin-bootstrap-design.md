# First-run admin bootstrap — design

- **Issue:** [#64](https://github.com/larspohlmann/simple-feed-reader/issues/64)
- **Date:** 2026-08-01
- **Status:** approved (brainstorming)

## Problem

A fresh install has no supported, prod-safe way to create the first admin. The
only path to `ROLE_ADMIN` today is `app:e2e:seed-admin`, which refuses to run
under `APP_ENV=prod` by design. With no admin, no registered user can ever be
approved, so the instance is unusable.

The design goal is not only "make first-admin creation possible" but "make it
**hijack-resistant**". The hijack threat exists in exactly one window: while the
instance has **zero admins**. Whatever path creates that first admin, an
attacker who reaches it before the operator owns the instance.

## Core principle

The first-admin path must require **a secret only the server operator holds**.
Two postures are rejected because both are a land-grab race:

- First-registered-user becomes admin — hijackable.
- Open web setup wizard ("create admin if none exists") — hijackable.

The safe posture gates the path on something the attacker cannot have: shell
access (a CLI command) or an operator-provided setup secret in the environment.

## Threat model

- **In scope:** an internet-reachable fresh instance; an attacker who scans and
  reaches the app over HTTP before the operator finishes setup; brute-force of
  the setup secret.
- **Out of scope:** an attacker who already has shell/exec access or can read the
  host environment (they are the operator's equal by definition); host or TLS
  compromise.

## Two bootstrap paths — layered and opt-in

The two paths coexist. The web path is opt-in: it exists only when the operator
configures a setup secret. A shell operator leaves the secret unset and gets
**zero web hijack surface**.

### Path A — CLI command `app:admin:create` (shell / `docker exec`)

- Prod-safe: no `APP_ENV` refusal (this is its whole reason to exist next to
  `app:e2e:seed-admin`).
- Reuses the `E2eSeedAdminCommand` wiring: `UserPasswordHasherInterface`,
  injected `ClockInterface`, `UserRepository`, `EntityManagerInterface`.
- Provisions the account `UserStatus::Active` + `['ROLE_ADMIN']` with
  `approvedAt` set — bypasses email verification and the approval queue for this
  bootstrap account.
- **Password via hidden interactive prompt** (`SymfonyStyle::askHidden`), not a
  CLI argument. A password argument leaks into shell history and the process
  list. Email is a required argument.
- Guards on the shared invariant (below). Refuses with a non-zero exit when an
  admin already exists, unless `--force` is given.

### Path B — web setup wizard (no-shell operators)

Aimed at cheap Docker hosts (Railway, Render, Fly.io, DigitalOcean App Platform,
Coolify/Dokku, plain `docker run`). The lowest-common-denominator capability of
all of them is the **environment variable** — that is how a container receives
`DATABASE_URL`, `APP_SECRET`, and the mailer DSN. Shell/`exec` and post-deploy
file retrieval are not universal, so the setup secret is sourced from the
environment.

- Endpoint exists only when `ADMIN_SETUP_SECRET` is configured **and** no admin
  exists. Otherwise it returns `404`.
- `POST /api/setup/admin`, body `{ email, password, secret }`.
  - **Secret in the request body, never the URL** — no secret in query strings or
    logs.
  - **`hash_equals`** constant-time comparison against `ADMIN_SETUP_SECRET`.
  - **Rate-limited** with the existing limiter infrastructure (the pattern used
    for login throttling), to blunt brute-force of the secret.
  - The `hasAnyAdmin()` guard runs first, so the endpoint is **self-disabling**:
    dead the instant an admin exists, secret or not.
  - On success: create the same `Active` / `ROLE_ADMIN` / `approvedAt` account,
    then mint a JWT via the existing login success handler so the operator lands
    logged-in.
- Stateless, JSON in / `application/problem+json` out, bearer token out — clean
  against the native-iOS design checklist (docs/architecture.md §6).

## Shared invariant

Both paths and the endpoint's own existence gate on one question:

> Does any user with `ROLE_ADMIN` exist, in **any** status?

New `UserRepository::hasAnyAdmin(): bool`, role-aware across all statuses (not
just `Active`). Rationale: gating on active-only would let a hijacker re-open
setup by getting the sole admin suspended. `roles` is stored as portable
JSON-as-text (SQLite/MySQL), so the role match is done in PHP after a candidate
query, matching the existing `findActiveAdmins()` approach (a raw
`LIKE '%ROLE_ADMIN%'` would still need a recheck to reject a `ROLE_ADMINISTRATOR`
substring).

Behaviour:

- Empty instance → create allowed.
- Admin exists → CLI refuses (non-zero exit); web endpoint `404`. Idempotent and
  safe to re-run.
- CLI `--force` is the only override, for recovery.

## First-run guidance in the SPA

Public `GET /api/setup/status` → `{ needsSetup: bool }` (true only while
`hasAnyAdmin()` is false). It reveals only "no admin yet", which an attacker
learns by probing anyway; the secret remains the real gate.

- While `needsSetup` is true, the SPA **hides login and registration** and routes
  everything to a **setup screen**. The screen tells the operator to either run
  `app:admin:create`, or set `ADMIN_SETUP_SECRET` and complete the wizard here.
- **Security invariant — no auto-promote.** The first person to register is
  **never** promoted to admin. "First user is admin" is exactly the hijack this
  design prevents. Admin is granted only by Path A or Path B.
- Once an admin exists, `needsSetup` flips to false, the setup screen disappears,
  and normal login/registration resume.

## Documentation

Concise `docs/first-run-setup.md`, linked from the README, with two recipes:

- **Shell:** `docker compose exec php bin/console app:admin:create you@example.com`
  (prompts for the password).
- **No-shell:** generate a secret (`openssl rand -hex 32`), set
  `ADMIN_SETUP_SECRET`, open the setup screen, enter email + password + secret,
  then remove the secret.

Security notes: use a high-entropy secret; remove it after setup (the endpoint
self-disables regardless, but removing it is tidy).

## Components touched

| Area | Change |
|---|---|
| `backend/src/Command/CreateAdminCommand.php` | New `app:admin:create` command (Path A) |
| `backend/src/Controller/Api/SetupController.php` | New `GET /api/setup/status`, `POST /api/setup/admin` (Path B + status) |
| `backend/src/Service/Auth/...` | Shared "provision bootstrap admin" service used by both paths (no duplication) |
| `backend/src/Repository/UserRepository.php` | `hasAnyAdmin(): bool` |
| `backend/config/packages/security.yaml` | `^/api/setup/` public; verify it sits above the `^/api/` catch-all |
| `backend/config/packages/rate_limiter.yaml` | Limiter for the setup endpoint |
| `frontend/src/app/setup/` | Setup screen + `setup-api`; route guard that hides login/register while `needsSetup` |
| `docs/first-run-setup.md`, `README.md` | Documentation |

Both paths funnel through one service (`provisionBootstrapAdmin`) so the
`Active` + `ROLE_ADMIN` + `approvedAt` provisioning lives in exactly one place.

## Testing

- Admin created on an empty instance — CLI path.
- Admin created on an empty instance — web path with the correct secret.
- CLI refuses and web endpoint `404`s when an admin already exists.
- Web endpoint `404`s when `ADMIN_SETUP_SECRET` is unset.
- Wrong secret rejected; rate-limit engages under repeated wrong secrets.
- `GET /api/setup/status` returns `needsSetup: true` on empty, `false` after
  creation.
- `--force` overrides the CLI guard.
- The web `POST /api/setup/admin` is backed by a functional test through the real
  firewall (a direct controller call cannot prove the `^/api/setup/` access rule
  and the rate limiter are wired).

## Quality gates

`composer cs`, `composer stan` (PHPStan level max), `composer md` (PHPMD) clean on
every touched `src` file — not merely free of new findings. PhpStorm inspections
on changed PHP: block on ERROR/WARNING. Frontend `npm run check` clean.

## Explicitly out of scope (YAGNI)

- Seeding default instance config during onboarding (issue open question) — not
  needed for a usable first admin.
- App-generated setup token written to a file — rejected; container filesystems
  are ephemeral and rarely readable on cheap Docker hosts.
- Reusing `APP_SECRET` as the setup secret — rejected; it forces a long-lived
  signing key into a browser form, widening the blast radius.
