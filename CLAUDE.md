# simple-feed-reader

Multi-user RSS/Atom reader. **Symfony 7.4 LTS JSON API** in `backend/` (PHP 8.4),
**Angular 20 SPA** in `frontend/` (Node 22). MySQL in production and Docker,
SQLite for the native test run.

## Response language

Write all prose replies in **ASD-STE100 Simplified Technical English**: active
voice, short sentences, one instruction per sentence, approved words only, no
synonyms, no slang or idioms. This applies to prose only — do not change code,
code comments, commit messages, file contents, or command output.

## Commands

Backend (from `backend/`):

```bash
composer cs          # PHP_CodeSniffer, PSR-12 (composer cs:fix to autofix)
composer stan        # PHPStan level max (needs a warm dev cache: bin/console cache:warmup)
composer md          # PHPMD, codesize ruleset
composer tramp       # phptramp, tramp-data chains (thresholds in phptramp.dist.json)
composer tramp:update     # re-resolve phptramp to the tip of its develop branch
composer check       # cs + stan + tramp
php bin/phpunit      # unit/integration suite (SQLite natively)
composer infection   # mutation testing over all of src (needs pcov or xdebug)
composer infection:diff   # …over the files this branch changes — what CI gates
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
- **Default to no comment.** A clear name or a smaller method beats a sentence
  about the code. Write a comment only for the *why* the code cannot state: a
  non-obvious invariant, a defensive branch, a hard-won bug, a decision that
  costs more to rediscover than to read.
- **One line. Three at the absolute most.** More than three lines needs a VERY
  GOOD reason, and the reason goes in the comment. Holds for every language in
  the tree and for docblocks.
- **Delete on sight**, in code you write and code you touch: a comment that
  restates the next line, a `@param`/`@return` that repeats the signature, a
  docblock that repeats the class name, a section banner, a narration of the
  change or the review that caused it, and all commented-out code.
- **DRY.** Third occurrence is a refactor, not a copy.
- **Controllers hold no private methods that carry responsibility.** An action
  reads the request, delegates, and returns a response. Querying, response
  assembly, validation, entity mutation and security decisions belong in a
  service, a repository, or an `src/Http/*Json.php` mapper — never in a private
  method on the controller. Enforced by `ThinControllerRule` (PHPStan). The one
  permitted exception is a trivial single-expression helper used by exactly one
  action in exactly one controller; add it to the rule's allow-list with a
  comment that says why. The same helper in a second controller is duplication,
  and the exception no longer applies.
- **Tests are production code** — same naming, same structure, same standards.

Enforced mechanically by `composer check` and `composer md`:

- **PSR-12** (`phpcs.xml.dist`), `declare(strict_types=1)` in every file.
- **PHPStan level max** over `src` and `tests` — no new baselines, no
  `@phpstan-ignore` without a comment saying why.
- **`ThinControllerRule`** (`tests/PhpStan/ThinControllerRule.php`, run by
  `composer stan`) — controllers carry no private method that does real work; the
  allow-list of permitted trivial helpers lives in the rule and only ever shrinks.
- **PHPMD codesize** — cyclomatic/NPath complexity, method and class length,
  parameter/field counts. **Standing rule: every `src` file you touch must be
  PHPMD-clean before commit**, not merely free of *new* findings. Fix the design
  the metric is pointing at; do not tune the threshold.
- **phptramp** (`phptramp.dist.json`, run by `composer tramp` and CI) — a chain
  of **4** or more methods forwarding a parameter none of them reads fails the
  build; **3** hops warns. Only chains crossing **2** or more classes count
  (`minClasses`): a value threaded through one class's own private helpers is
  decomposition, and counting it buries the real tunnels. Tramp data means the
  value has no home: the fix is a context object or a per-pass collaborator that
  holds it as a field, not a longer signature (see `Service/Fetch/PageUrls.php`
  and `Service/Scraper/JsonLdArticles.php`). A ratchet like `minMsi` — tighten it
  as the tree catches up, never loosen it to make a branch pass, and never add a
  `--baseline` to a clean tree.
  **CI runs the tip of phptramp's `develop`, not the commit in `composer.lock`**
  — this repository doubles as that tool's proving ground, so a pin would report
  against stale code exactly while phptramp is being worked on. The cost is real:
  a red build here can be caused by a phptramp change with no commit in this repo
  to explain it. Check `composer show larspohlmann/phptramp` (CI prints it above
  the gate) before hunting for the cause in application code.
- PhpStorm inspections (`mcp__phpstorm__lint_files`) on changed PHP: block on
  ERROR and WARNING; weak warnings are advisory.

## Frontend conventions

- Standalone components and signals; no NgModules.
- **Offer the architectural fix, not just the patch.** When a frontend bug —
  however small — traces back to an architectural weakness, and a rework would
  both fix it and make future development simpler, propose that rework to the
  user alongside the narrow patch: what it deletes, what it risks, what the
  patch would leave behind. Let the user choose; don't silently default to the
  band-aid. (Case in point: #128 — three successive patches to the shared
  header state lost to one layer-isolation redesign.)
- **Hex colours are forbidden in `.scss` outside `src/app/theme/`** (Stylelint
  `color-no-hex`), and so are ad-hoc `px` spacing values and media-query
  literals — both fail `npm run check`.
- **Component styles live in a sibling `.scss` file** (`styleUrl`), never inline
  in the `.ts`: Stylelint has no TS syntax installed, so inline styles are
  silently unlinted.
- [docs/design-language.md](docs/design-language.md) is the source for tokens,
  the shared component catalog (`src/app/shared/`), the density/sticky/overlay
  conventions and the recorded exceptions. Read it before adding a surface.
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
- **e2e is black-box against the single shared Docker stack.** Run it only from
  the checkout that ran `docker compose up`; a run from another checkout (a
  worktree) tests the wrong code. Both suites preflight-guard against this and
  fail fast naming the owning path (#615, `backend/bin/e2e-preflight.sh`).
- **Scan the dev log** after backend work — deprecations and swallowed errors
  surface there. The `dev` and `test` handlers rotate daily and keep 3 files
  (`monolog.yaml`, #596), so the active file is
  `backend/var/log/dev-YYYY-MM-DD.log`, not `dev.log`. Scan today's file, e.g.
  `ls -t backend/var/log/dev-*.log | head -1`. `dev.log` reaches back 3 days;
  the level stays `debug`.
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
- `develop` is the repository's **default branch**, so a PR whose body says
  `Closes #NN` **auto-closes the issue on merge**. Keep writing `Closes #NN`;
  after a merge, verify the issue closed rather than closing it by hand.
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
- **Mutation testing gates changed files.** CI runs Infection over the files a PR
  touches (`composer infection:diff`), with `minMsi` in `infection.json5`. It is a
  ratchet: raise it as the tree catches up, never lower it to make a branch pass.
  A full sweep scores lower than the gate — that is expected and is not a
  regression. Escaped mutants arrive as PR annotations on the offending line.
- **Anything that runs tests in parallel must set `TEST_TOKEN`.** Workers share a
  SQLite file and the cache pools otherwise, and `tests/bootstrap.php` deletes the
  database at every process start — so siblings fail for reasons unrelated to the
  code under test. `tests/Support/WorkerIsolation.php` gives each token its own database
  and cache directory; `doctrine.yaml` covers the MySQL dbname. Note that a
  mutation run reads any failing test as a killed mutant, so broken isolation
  inflates the score rather than breaking the build (it read 97% instead of 74%).
  Prove isolation with `infection --noop`: noop mutants leave the code unchanged,
  so **every** one of them must survive. Any reported kill is a false one.
- Frontend unit: `docker compose exec -T frontend npm test` (Jest, jsdom). Always
  run frontend tests inside the Docker frontend container. Playwright smokes need
  Docker and are deliberately outside the CI gate.
- Both e2e suites run weekly in CI
  (`.github/workflows/e2e-rot-check.yml`) and open an `e2e-rot` issue when they
  break. Never give that workflow a `pull_request` trigger — the cost was
  weighed in #96.
- **An e2e spec must own the data it asserts on.** Reading whatever the seeded
  account happens to hold passes on a developer machine and fails on a fresh
  database; stub the route instead (see `frontend/e2e/magazine-kicker-one-line.spec.ts`),
  and leave the fixture behind exactly as it was found.
