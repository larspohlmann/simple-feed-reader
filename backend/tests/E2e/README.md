# End-to-end tests

This directory is a black-box test suite: it speaks only HTTP, over real TLS,
to the running Docker stack — nginx → PHP-FPM → MySQL, with Mailpit standing
in for outbound mail. It never boots the Symfony kernel and never touches
SQLite, which makes it structurally distinct from `backend/tests/` (kernel
boot, SQLite schema built from Doctrine metadata).

That distinction is the point. The in-kernel suite is fast and exhaustive for
business logic, but it cannot see a whole class of faults: a route that only
breaks through nginx's real rewrite rules, a cookie attribute a TLS terminator
strips, SQL behaviour SQLite tolerates and MySQL does not, or a mailer DSN
that never actually reaches an SMTP server. This suite exists to catch
exactly those, by exercising the same stack a real client would.

## Prerequisites

1. The stack is up: `docker compose up -d` from the repository root (see
   [`docs/local-docker.md`](../../../docs/local-docker.md) for first-run
   setup).
2. mkcert is installed and trusted (`mkcert -install`), so the stack's TLS
   certificate is one your machine actually trusts. The suite verifies TLS
   fully — it never disables or weakens certificate checking — so an
   untrusted cert makes every test fail on the connection, not the assertion.

## How to run

From `backend/`:

```bash
composer e2e
```

This suite is **excluded** from the default `vendor/bin/phpunit` run (the main
`testsuites` config excludes `tests/E2e`), because that run must stay fast and
stack-free. `composer e2e` is the only way to execute it.

Two environment variables let you point the suite elsewhere — at a staging
deployment, for instance — without editing anything:

- `E2E_BASE_URL` (default `https://localhost:8443`) — the API origin.
- `E2E_MAILPIT_URL` (default `http://localhost:8025`) — Mailpit's HTTP API,
  used to fetch verification/reset tokens out of delivered mail.

## What it covers

- **`HealthE2eTest`** — the health endpoint answers over real TLS; the canary
  for "is the stack even up" before trusting any other failure.
- **`OnboardingJourneyE2eTest`** — the full account lifecycle: register,
  verify via the token pulled from Mailpit, admin approval, login, and an
  authenticated call to `/api/me`.
- **`PasswordResetE2eTest`** — a password reset revokes JWTs issued before it
  (via `password_changed_at`), while the new password logs in fine.
- **`OAuthProvidersE2eTest`** — the public OAuth providers list is readable
  without credentials.
- **`ErrorContractE2eTest`** — the API's failure contract holds through nginx:
  an unsolved ALTCHA is a 422 `problem+json`, and an unauthenticated
  `/api/me` is a 401 `problem+json`.

**Every new feature or endpoint gets an e2e test added here.** When you add a
flow, add its happy path and at least one guard (a rejection, an
unauthenticated call, an invalid input) — the in-kernel tests already cover
the logic in depth; what belongs here is proof that the flow survives the
real stack end to end.

## Fixtures & repeatability

The suite runs against the shared dev database, not a throwaway one, so it is
designed to be run repeatedly without ever resetting it:

- **The admin account.** There is no admin-creation endpoint (by design), so
  approving a registration needs one out-of-band fixture: `bin/e2e.sh` runs
  `docker compose exec php bin/console app:e2e:seed-admin` before every run.
  That command (`App\Command\E2eSeedAdminCommand`) refuses to run under
  `APP_ENV=prod` and is idempotent — a second run promotes/re-hashes the same
  account rather than duplicating it. It seeds
  `e2e-admin@example.com` / `e2e-admin-password-123`.
- **Rate limits.** Registration and login are rate-limited per IP, and every
  e2e run hits them from the same machine, so `bin/e2e.sh` clears the
  `cache.rate_limiter` and `altcha.replay.cache` pools before running —
  otherwise a second run in the same window would fail on 429s that have
  nothing to do with the code under test. That clear happens once per run, so
  the **whole suite must stay under the registration cap within a single run**
  (currently 5 requests per IP / 15 min; the suite makes 4). When you add a
  test that registers, keep the total under the cap — the pool reset saves you
  between runs, not mid-run.
- **TLS trust for PHP CLI.** Homebrew PHP on macOS pins its own static CA
  bundle and ignores the system keychain, so `mkcert -install` trusting the
  root at the OS level isn't enough for PHP's HTTP client. `bin/e2e.sh` builds
  a temporary bundle (system CAs + the mkcert root) and points PHP's
  `curl.cainfo` / `openssl.cafile` at it for the run via `-d`. This only adds
  trust for one extra root — `verify_peer` is never disabled, so the suite
  still fails against a genuinely bad certificate.
- **No DB reset needed.** Every test that needs an account calls
  `uniqueEmail()`, which mints a fresh `e2e-<random>@example.com` address per
  call. Runs never collide with each other's data, so there is nothing to
  clean up between runs.
