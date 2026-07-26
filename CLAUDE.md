# simple-feed-reader

Multi-user RSS/Atom reader. **Symfony 7.4 LTS JSON API** in `backend/` (PHP 8.4),
**Angular 20 SPA** in `frontend/` (Node 22). MySQL in production and Docker,
SQLite for the native test run.

## Commands

Backend (from `backend/`):

```bash
composer cs          # PHP_CodeSniffer, PSR-12 (composer cs:fix to autofix)
composer stan        # PHPStan level max (needs a warm dev cache: bin/console cache:warmup)
composer md          # PHPMD, codesize ruleset
composer check       # cs + stan
php bin/phpunit      # unit/integration suite (SQLite natively)
composer e2e         # black-box e2e against the running Docker stack
```

Frontend (from `frontend/`):

```bash
npm ci
npm start            # dev server on :4200, talks to https://localhost:8443
npm run check        # ESLint + Prettier + Stylelint + Jest — the CI gate
npm run build
npm run e2e          # Playwright smokes; needs the Docker stack up
```

Docker stack (from the repo root) — see [docs/local-docker.md](docs/local-docker.md):

```bash
docker compose up -d
docker compose exec php vendor/bin/phpunit    # the MySQL leg of the suite
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

`docker compose down` is safe. **`docker compose down -v` deletes the MySQL volume.**

## Layout

| Path | What |
|---|---|
| `backend/src/Controller/Api`, `Controller/Admin` | HTTP entry points; thin, they delegate to services |
| `backend/src/Service/**` | The domain work, one subdirectory per concern (`Fetch`, `Parser`, `Scraper`, `Reader`, `Refresh`, `OAuth`, `Auth`, `Opml`, `Preview`, `Discovery`, `Subscription`, `Mail`) |
| `backend/src/Dto/**` | Request/response shapes, grouped by feature |
| `backend/src/Entity`, `Repository`, `Doctrine` | Persistence |
| `backend/tests/**` | Mirrors `src/`; `tests/E2e/` is the black-box suite |
| `frontend/src/app/reader`, `settings`, `admin`, `core`, `theme` | SPA feature areas |
| `docs/superpowers/plans/` | The implementation plans this project is built from |

## PHP code style — Clean Code is mandatory

**All PHP must follow Clean Code principles.** This is not advisory: a change that
passes the linters but leaves unclear, oversized, or duplicated code is not done.

Non-negotiables:

- **Names reveal intent.** No abbreviations, no `$data`/`$info`/`$tmp`, no
  encodings. If a name needs a comment to be understood, rename it.
- **Functions do one thing**, at a single level of abstraction, and stay short.
  Extract until each method reads as a sentence about *what*, not *how*.
- **Few parameters.** Three is a lot; more means a DTO or value object is missing.
  **No boolean flag parameters** — split the method instead.
- **Guard clauses over nesting.** Return early; never let indentation carry logic.
- **No hidden side effects.** A method named `get…` does not mutate or persist.
- **Immutability by default.** `final readonly class` with constructor promotion is
  the house style (the majority of `src/` already is); prefer new instances over
  setters. `final` unless the class is designed for extension.
- **Depend on interfaces, inject them.** No service locators in domain code, no
  `new` on a collaborator inside a method. Strategies get a tag + keyed locator
  (see `Service/Refresh/FeedBodyParser.php` for the pattern).
- **Errors are exceptions**, typed and namespaced next to their service
  (`Service/*/Exception/`). Never signal failure with `null` or a magic value.
- **Comments explain *why*, never *what*.** The codebase's existing comments are
  the standard: they justify a defensive branch or record a decision. Delete
  commented-out code.
- **DRY.** Third occurrence is a refactor, not a copy.
- **Tests are production code** — same naming, same structure, same standards.

Enforced mechanically by `composer check` and `composer md`:

- **PSR-12** (`phpcs.xml.dist`), `declare(strict_types=1)` in every file.
- **PHPStan level max** over `src` and `tests` — no new baselines, no
  `@phpstan-ignore` without a comment saying why.
- **PHPMD codesize** — cyclomatic/NPath complexity, method and class length,
  parameter/field counts. **Standing rule: every `src` file you touch must be
  PHPMD-clean before commit**, not merely free of *new* findings. Fix the design
  the metric is pointing at; do not tune the threshold.
- PhpStorm inspections (`mcp__phpstorm__lint_files`) on changed PHP: block on
  ERROR and WARNING; weak warnings are advisory.

## Frontend conventions

- Standalone components and signals; no NgModules.
- **Hex colours are forbidden in `.scss` outside `src/app/theme/`** (Stylelint
  `color-no-hex`). Component styles are inline in the `.ts` and kept token-only
  (`var(--…)`) by convention.
- Bearer JWT in `localStorage` is the entire auth story — no auth cookie, no
  session. A functional interceptor attaches it and clears it on `401`.

## Gotchas

- **Datetimes are stored as naive UTC.** Doctrine persists wall-clock values, so
  normalise anything incoming to UTC *before* persisting. Feed-supplied offsets
  that skip this make entries show up as "now".
- **Never nest `cdkDropList`s.** Nesting silently breaks cross-list drag; use
  sibling lists.
- **Migrations need their own verification.** `tests/bootstrap.php` builds the
  schema from ORM metadata, so no test ever executes a migration — a broken or
  dialect-wrong migration passes the whole suite green. CI has a dedicated leg
  that migrates from empty on both SQLite and MySQL, then runs
  `doctrine:schema:validate`. Keep it.
- **Run e2e through `composer e2e`, not raw phpunit.** Homebrew PHP ignores the
  macOS keychain, so the script builds a CA bundle containing the mkcert root;
  bare phpunit fails TLS verification against `https://localhost:8443`.
- **Scan `backend/var/log/dev.log`** after backend work — deprecations and
  swallowed errors surface there.
- Symfony's `SendmailTransport` hardcodes `-bs`; a sendmail DSN needs an explicit
  `?command=` or mail fails silently after a `202`.

## Standing architectural constraint

**Keep a native Swift iOS client viable.** Bearer-token auth, stateless requests,
JSON in and `application/problem+json` out, no CSRF token, no browser-only inputs,
no `text/html` fallback. Run the design-time checklist in
[docs/architecture.md](docs/architecture.md) §6 against any new client-facing
endpoint; flag browser-coupled patterns rather than baking them in.

The OIDC boundary (`IdToken` and friends in `Service/OAuth/Oidc/`) is a security
control, not ceremony — do not "simplify" it away, and do not delete
`OidcBoundaryTest`.

## Workflow

- **git-flow.** Feature branches off `develop`; PRs merge into `develop`, never
  `main`. Every merge to `develop` is shippable.
- **Branch names embed the GitHub issue number**: `feature/114-tag-triggered-deploy`,
  `fix/112-arbitrary-join-on`.
- Because PRs target `develop`, GitHub does **not** auto-close the issue —
  **close it manually when the PR merges.**
- **Deploys are tag-triggered**: pushing a `vX.Y.Z-dev.N` tag on a `develop` commit
  runs `.github/workflows/deploy-strato.yml`, which requires that the commit is on
  `develop` and that CI was green on that exact SHA. A tag push runs the workflow
  file *as it exists at that tag*.
- The global gitignore hides `composer.lock`; this repo re-includes it with
  `!/composer.lock`. Keep it committed.
- Concurrent Claude sessions can share this checkout — **check before any
  `checkout`, `reset`, or `stash`**; another session may be mid-edit.

## Testing

- Backend unit/integration: `php bin/phpunit` (SQLite) natively, or
  `docker compose exec php vendor/bin/phpunit` (MySQL). Run both legs before a PR.
- **Direct-invocation tests mislead.** A listener test that bypasses the dispatcher
  can assert something the real wiring makes impossible — back it with a
  functional test.
- Frontend unit: `npm test` (Jest, jsdom). Playwright smokes need Docker and are
  deliberately outside the CI gate.
