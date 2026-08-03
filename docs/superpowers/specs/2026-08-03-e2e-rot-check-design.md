# Weekly e2e rot check in CI (#96)

## Problem

`ci.yml` never runs either e2e suite. The Playwright specs
(`frontend/e2e/`) and the backend black-box suite (`backend/tests/E2e/`)
both drive the running Docker stack, so nothing in the fast CI path
executes them — and they rot silently. #93 sat broken for weeks and was
found by accident; worse, it had timed out on its *first* action, so every
assertion below it was unverified the whole time.

These suites are the only coverage for a whole class of defect. Jest runs
in jsdom, which applies no stylesheets at all, so layout regressions
(#85, #87) are invisible to 400+ passing unit tests. The e2e suites are
where that class gets caught, and today they run only when someone
remembers.

## Decision

### Report weekly, do not gate

Issue #96 posed the question "gate merges or just report". The answer is
**report, on a weekly schedule** — decided 2026-08-03:

- Running the stack on every PR push is too slow; PR feedback stays as it
  is today.
- A per-merge leg on `develop` pushes was considered and rejected: even
  once per merge is more Actions time than the risk justifies here.
- The deploy guard in `deploy-strato.yml` is **not** extended to consult
  this workflow. A stale-but-red weekly run must not block an unrelated
  deploy, and deploy tooling stays simple (standing preference). The
  safety net is the schedule plus the issue it opens.

### One workflow, one job, both suites

New workflow `.github/workflows/e2e-rot-check.yml`, modeled on
`catalog-rot-check.yml`:

- Triggers: `schedule` (weekly cron, a different slot than the catalog
  check's `17 4 * * 1`) and `workflow_dispatch`. A header comment records
  that a `pull_request` trigger is deliberately absent and why.
- Permissions: `contents: read`, `issues: write`.
- One job on `ubuntu-latest`. The stack boot dominates the cost, so the
  backend suite and the Playwright suite share the booted stack rather
  than paying for it twice.

### Stack boot

1. Checkout.
2. Install `mkcert` (`apt-get install mkcert libnss3-tools`), run
   `mkcert -install`, and generate the certificate pair exactly as
   [docs/local-docker.md](../../local-docker.md) prescribes:
   `mkcert -cert-file docker/certs/localhost.pem -key-file
   docker/certs/localhost-key.pem localhost 127.0.0.1 ::1`.
   The dev stack's nginx (`docker/nginx/default.conf`) reads those exact
   filenames. mkcert rather than a bare `openssl` self-signed pair,
   because `mkcert -install` puts the root in the runner's system store
   and `backend/bin/e2e.sh` already builds PHP's CA bundle from
   `mkcert -CAROOT` — the runner then behaves like a developer machine
   and **no script changes are needed**.
3. `docker compose up -d`, then poll `https://localhost:8443/api/health`
   and the Angular dev server on `:4200` until both answer, with a hard
   timeout so a dead stack fails loudly instead of hanging. The frontend
   container's first run installs `node_modules` into its named volume,
   so the `:4200` timeout is generous.
4. Migrate the fresh database:
   `docker compose exec -T php bin/console doctrine:migrations:migrate
   --no-interaction`.

### The two suites

5. Backend black-box e2e: PHP 8.4 on the runner
   (`shivammathur/setup-php`, as in `ci.yml`), `composer install`, then
   `composer e2e` from `backend/`. The script itself purges leftover
   fixture accounts, seeds the admin and its subscription, clears the
   `cache.rate_limiter` and `altcha.replay.cache` pools, and runs the
   suite against `https://localhost:8443` with full TLS verification.
6. Playwright: Node 22 with the npm 11 pin (same reason as `ci.yml`:
   the lockfile is authored by npm 11), `npm ci`,
   `npx playwright install --with-deps chromium`, `npm run e2e` from
   `frontend/`.

### The all-skip trap is a failure

Every Playwright spec skips cleanly when the fixture admin is missing, so
a leg whose setup silently failed reports green with zero tests executed —
reproducing the exact problem this workflow exists to solve. The job
therefore **fails when either suite executed zero tests**. This check is
part of the job, not optional hardening.

### On failure, open an issue

Same pattern as `catalog-rot-check.yml`: on any failure, open a GitHub
issue carrying the failing step's output. Before creating, search for an
open issue with the same title; when one exists, comment on it instead of
opening a duplicate — a suite that stays red for three weeks must not
produce three issues.

## Out of scope

- No `pull_request` trigger, now or later, without revisiting this
  decision.
- No change to `ci.yml` or `deploy-strato.yml`.
- No change to the e2e suites or scripts themselves; the workflow adapts
  to them, not the reverse.
