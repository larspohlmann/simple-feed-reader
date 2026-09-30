# Faster Backend CI Jobs (#1276) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1276 in one PR (`chore/1276-ci-backend-speed`, `Closes #1276`). The `Backend (mysql)` job drops from about 3m40s to about 1m30s, and CI wall-clock from about 3m40s to about 2m15s.

| Job | Before | Target |
|---|---|---|
| Backend (mysql) | ~3m40s | ~1m30s |
| Backend (sqlite) | ~3m10s | ~1m10s |
| Backend (static), new | – | ~1m30s cold, ~1m warm |
| Frontend (untouched) | ~2m10s | ~2m10s |

**Architecture:**
- **Static checks run once.** PHPCS, PHPStan, PHPMD and phptramp move out of the database matrix into a `Backend (static)` job (Task 1).
- **One setup for every backend job.** After the split, three jobs set up PHP and install Composer packages, so that sequence becomes a composite action, `.github/actions/setup-backend` (Task 1). Task 3 adds Composer's download cache to it once, for all three jobs.
- **MySQL boots while the job installs.** A `docker run --detach` step replaces the blocking `services:` container. The job waits for MySQL only right before the migration check. The SQLite leg starts no MySQL at all (Task 2).
- **The suite runs on all four CPUs.** ParaTest drives the existing worker isolation: `TEST_TOKEN`, `WorkerIsolation` and `doctrine.yaml`'s `dbname_suffix` (Task 4).

**Tech Stack:** GitHub Actions (composite actions, `actions/cache@v6`, `shivammathur/setup-php@v2`), Docker (`mysql:8.4`), PHPUnit 12.5.31, ParaTest 7.x, PHPStan 2.2.5, PHP_CodeSniffer 3.13.6, Composer.

**Spec:**
- GitHub issue #1276 (`gh issue view 1276 --repo larspohlmann/simple-feed-reader`). It holds the measured step times this plan argues from.
- CLAUDE.md: the "Commands" section, "Anything that runs tests in parallel must set `TEST_TOKEN`", and the OTel note under "Run the MySQL leg with `composer test`".

## Global Constraints

- PHP stays `'8.4'`. The extensions stay `intl, pdo_sqlite, pdo_mysql, zip` for every backend job, the mutation job included (it gains `pdo_mysql`, which is harmless).
- `mysql:8.4`, `MYSQL_ROOT_PASSWORD=root` and both DSNs stay exactly as they are. `DATABASE_URL` stays `mysql://root:root@127.0.0.1:3306/feedreader_test?serverVersion=8.4&charset=utf8mb4`.
- The migration check and `doctrine:schema:validate` keep running on **both** legs.
- phptramp keeps running at the tip of its `develop` branch (`composer tramp:update`), and **after** PHPStan (see D4).
- Keep the existing workflow comments word for word, except the clauses that the split makes false (Task 1 names them). New comments obey CLAUDE.md: one line, three at most, only when a reader would otherwise get the code wrong.
- Commit format: `chore(#1276): <lower-case summary>`.
- Nothing in `backend/src` or `backend/tests` changes.

## Decisions

- **D1 (renaming and splitting jobs is safe):** `develop` has no branch protection and no rulesets. `gh api repos/larspohlmann/simple-feed-reader/branches/develop/protection` answers 404, and `…/rulesets` answers `[]`. So no required check pins a job name. The deploy gate, `.github/workflows/deploy-strato.yml:114`–`137`, reads the conclusion of each `ci.yml` **run**, not of a job, so a new job is covered automatically.
- **D2 (composite action, not copy-paste):** after the split, the setup sequence appears in `backend-static`, `backend` and `mutation`. That is a third occurrence (CLAUDE.md: "Third occurrence is a refactor"). A composite action cannot inherit `defaults.run.working-directory`, so each of its `run` steps names `shell: bash` and `working-directory: backend` itself.
- **D3 (why `docker run`, and why wait over TCP):** a `services:` container blocks the job until its health check passes. On run 36690187934 that was 13s of image pull plus 14s of health waiting, because the first check fires after `--health-interval 10s`. A detached `docker run` as step one overlaps both with checkout, PHP setup and `composer install`.

  The `mysql` image's entrypoint initialises the data directory through a temporary server started with `--skip-networking`. A TCP connection to `127.0.0.1` inside the container therefore succeeds only once the real server is listening. The wait step uses that as its readiness proof.

  The start step runs before `actions/checkout`, when `backend/` does not exist yet. The job-level `working-directory: backend` default would fail that step, so the step overrides it with `${{ github.workspace }}`.
- **D4 (PHPStan result cache):**
  - PHPStan 2 keeps its result cache under `sys_get_temp_dir()/phpstan`, which is `/tmp/phpstan` on `ubuntu-latest`.
  - The cache key is `phpstan-${{ github.sha }}`, with `restore-keys: phpstan-`, so every run restores the newest earlier cache. PHPStan itself invalidates entries whose files, config or dependencies changed, so a stale restore costs time, never correctness.
  - PHPStan must stay **before** `composer tramp:update`. That step rewrites `vendor/` and `composer.lock`, and a changed dependency set invalidates the whole result cache.
  - If the warm run in Task 5 shows no drop in the PHPStan step, remove the cache step rather than keep dead config, and say so in the PR body.
- **D5 (PHPCS):** PHP_CodeSniffer 3.13.6 runs files in parallel through `pcntl` when `--parallel` is above 1. Homebrew PHP and setup-php both ship `pcntl`. `<arg name="parallel" value="4"/>` matches the runner's four vCPUs and speeds up the local run as well. The repo is public, so `ubuntu-latest` has 4 vCPUs.
- **D6 (ParaTest version):** ParaTest's latest release, v7.25.0, requires `phpunit/phpunit ^13.3.5`. The lock has PHPUnit 12.5.31. ParaTest v7.20.0 still accepts `^12.5.14 || ^13.0.5`. Run a plain `composer require --dev brianium/paratest`, **without** `-W`, and Composer picks the newest ParaTest that accepts the locked PHPUnit. PHPUnit must not move in this PR.
- **D7 (what the parallel workers share, and how the script deals with it):**
  - `tests/bootstrap.php` runs once in **every** worker.
  - With a `TEST_TOKEN` set, each worker rebuilds its own database: `feedreader_test_test<N>` on MySQL through `dbname_suffix`, `var/data_test<N>.db` on SQLite through `WorkerDatabaseUrl`. Each worker also gets its own cache-pool directory, through `WorkerIsolation`, so the rate limiters stay apart.
  - **The compiled test container is shared** (`var/cache/test`). `WorkerIsolation`'s own comment says so. Infection already relies on this with `--threads=max`.
  - **The JWT keypair is shared too** (`config/jwt/*.pem`, gitignored). In CI it is always missing. Four bootstraps would then each run `lexik:jwt:generate-keypair --overwrite` at once, and a worker could read one worker's private key next to another worker's public key.
  - So `composer test:parallel` warms the test container and generates the keypair once (`--skip-if-exists`, a real option of `GenerateKeyPairCommand.php:58`), before ParaTest starts any worker. The bootstraps then find both files and skip generation. Putting this in the script rather than in CI covers a fresh local clone too.
- **D8 (`composer test` stays the documented default):** CLAUDE.md's advice to run the native and Docker legs side by side stays as it is. `test:parallel` is what CI runs, and it is available locally, but it does not replace `composer test` in the docs. Two parallel legs on one laptop would compete for CPUs.
- **D9 (OTel):** CI runs without the `opentelemetry` extension, so the auto-instrumentation writes a warning to STDERR at autoload. `composer test:parallel` starts with `@putenv OTEL_PHP_DISABLED_INSTRUMENTATIONS=all`, like `composer test`. That covers ParaTest and every console command in the script.

## File Structure

| File | Change | Task |
|---|---|---|
| `.github/actions/setup-backend/action.yml` | Create: PHP setup + `composer install`. Task 3 adds the Composer download cache | 1, 3 |
| `.github/workflows/ci.yml` | `backend-static` job added; the `backend` matrix slimmed; MySQL started by hand; PHPStan cache; ParaTest; `mutation` uses the composite action | 1–4 |
| `backend/phpcs.xml.dist` | `<arg name="parallel" value="4"/>` | 3 |
| `backend/composer.json`, `backend/composer.lock` | `brianium/paratest` (dev) and the `test:parallel` script | 4 |
| `CLAUDE.md` | One command line for `composer test:parallel` | 4 |

---

### Task 0: Branch

- [ ] **Step 1: Check out the branch, safely**

The branch `chore/1276-ci-backend-speed` already exists on `origin`, with this plan as its only commit on top of `develop`. Other Claude sessions share this checkout (CLAUDE.md, "Workflow"). First run `git status --short --branch`. If the tree is dirty, or the branch is not one this session owns, **stop and ask**; do not stash.

```bash
git fetch origin
git checkout chore/1276-ci-backend-speed
```

---

### Task 1: Static checks in their own job, with a shared setup action

**Files:**
- Create: `.github/actions/setup-backend/action.yml`
- Modify: `.github/workflows/ci.yml:9`–`132` (the `backend` job) and `:161`–`172` (the mutation job's setup)

**Interfaces:**
- Produces: the composite action `./.github/actions/setup-backend`, with one input, `coverage` (default `none`). It leaves PHP 8.4 installed and `backend/vendor` populated. Tasks 2–4 rely on this action and on the job ids `backend-static` and `backend`.

- [ ] **Step 1: Create the composite action**

`.github/actions/setup-backend/action.yml`:

```yaml
name: Set up the backend
description: PHP 8.4 with the backend's extensions, and its Composer packages installed.

inputs:
  coverage:
    description: The coverage driver setup-php installs (none, pcov or xdebug).
    default: none

runs:
  using: composite
  steps:
    - name: Set up PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.4'
        extensions: intl, pdo_sqlite, pdo_mysql, zip
        coverage: ${{ inputs.coverage }}

    - name: Install dependencies
      shell: bash
      working-directory: backend
      run: composer install --prefer-dist --no-progress
```

- [ ] **Step 2: Replace the `backend` job (lines 9–132) with the static job and the slimmed matrix job**

The block below replaces everything from `  backend:` on line 9 up to and including the `PHPUnit` step on lines 131–132. The mutation comment block that starts on line 134 stays where it is.

Comment edits, all others kept word for word:
- The PHPMD comment loses `It is a static check, unaffected by the database matrix leg.`
- The tramp comment loses `Static, so the database matrix leg does not affect it.`

Both clauses explained why the steps sat in a matrix, which is no longer true.

```yaml
  # Nothing here reads a database, so it runs once instead of in each leg below.
  backend-static:
    name: Backend (static)
    runs-on: ubuntu-latest

    defaults:
      run:
        working-directory: backend

    steps:
      - uses: actions/checkout@v5

      - uses: ./.github/actions/setup-backend

      - name: PHPCS (PSR-12)
        run: composer cs

      - name: Warm cache for PHPStan
        run: php bin/console cache:warmup

      - name: PHPStan
        run: composer stan

      # PHPMD codesize was a local-only discipline gate and silently broke once
      # (pdepend could not parse PHP 8.4 syntax, #183) — so the sweep now runs
      # here too.
      - name: PHPMD (codesize)
        run: composer md

      # This repository doubles as phptramp's proving ground, so the gate runs
      # the tip of its develop branch rather than the commit in composer.lock —
      # a pin would report against stale code exactly while phptramp is being
      # worked on. Only that one package is re-resolved; everything else stays
      # lock-pinned and reproducible. The consequence is deliberate: CI here can
      # go red because phptramp changed, with no commit in this repo to explain
      # it (see #380).
      - name: Update phptramp to the tip of develop
        run: composer tramp:update

      # Tramp data: a parameter forwarded through a chain of methods that never
      # read it. #373 and #374 were both found by a manual sweep months after
      # the chains grew, which is the argument for a gate rather than a habit.
      # Thresholds live in phptramp.dist.json; --format github annotates the
      # offending hop inline, the way the mutation leg annotates escaped
      # mutants.
      - name: phptramp (tramp data)
        run: |
          composer show phptramp/phptramp | grep -E '^(name|source|versions)'
          composer tramp -- --format github

  backend:
    name: Backend (${{ matrix.database }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        database: [sqlite, mysql]

    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: feedreader_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd "mysqladmin ping -proot"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    defaults:
      run:
        working-directory: backend

    steps:
      - uses: actions/checkout@v5

      - uses: ./.github/actions/setup-backend


      # Both DSNs are chosen here, in one place. MIGRATION_DATABASE_URL is
      # deliberately a different database from the one PHPUnit uses, so the
      # migration check below cannot disturb the suite: it also runs in dev,
      # whereas doctrine.yaml's when@test dbname_suffix puts PHPUnit on
      # feedreader_test_test (MySQL) and var/data_test.db (SQLite).
      - name: Select database DSN
        run: |
          if [ "${{ matrix.database }}" = "mysql" ]; then
            echo 'DATABASE_URL=mysql://root:root@127.0.0.1:3306/feedreader_test?serverVersion=8.4&charset=utf8mb4' >> "$GITHUB_ENV"
            echo 'MIGRATION_DATABASE_URL=mysql://root:root@127.0.0.1:3306/feedreader_migrations?serverVersion=8.4&charset=utf8mb4' >> "$GITHUB_ENV"
          else
            echo 'MIGRATION_DATABASE_URL=sqlite:///%kernel.project_dir%/var/migration-check.db' >> "$GITHUB_ENV"
          fi

      # Proves what the PHPUnit step structurally cannot. tests/bootstrap.php
      # builds its schema with doctrine:schema:create, straight from ORM
      # metadata, so no test ever executes a migration — a migration that is
      # missing, broken, or written in the wrong SQL dialect passes the entire
      # suite on both legs. That is not hypothetical: the initial schema
      # migration was generated on SQLite and emitted SQLite-only DDL
      # (AUTOINCREMENT, CLOB, NOT DEFERRABLE). Production is MySQL, deploys run
      # migrations before flipping the `current` symlink, and the first deploy
      # would have died on the first CREATE TABLE behind a green matrix.
      #
      # Runs on both legs, because the point is that each dialect is executable
      # on the engine that will actually execute it.
      - name: Verify the migration chain builds the schema from empty
        env:
          DATABASE_URL: ${{ env.MIGRATION_DATABASE_URL }}
        run: |
          if [ "${{ matrix.database }}" = "mysql" ]; then
            php bin/console doctrine:database:drop --force --if-exists
            php bin/console doctrine:database:create
          else
            # SQLite cannot enumerate databases, which --if-exists needs, and
            # the file IS the database — so deleting it is the drop. Starting
            # from a file that does not exist is the strictest form of "empty".
            rm -f var/migration-check.db
          fi

          php bin/console doctrine:migrations:migrate --no-interaction

          # The schema migrations build (production) must match the schema
          # metadata builds (tests). Drift between the two is the class of bug
          # that ships green, so it fails the build here.
          php bin/console doctrine:schema:validate

      - name: PHPUnit
        run: php bin/phpunit
```

The `Select database DSN` and migration-check steps, with their comments, are develop's lines 83–129, unchanged.

- [ ] **Step 3: Point the mutation job at the composite action**

Replace develop's lines 161–172, the `Set up PHP` step with its comment, and the `Install dependencies` step, with:

```yaml
      # The one job that needs a coverage driver: Infection maps each mutant
      # to the tests that reach it. PCOV over Xdebug because it is several
      # times faster and this job runs the suite many times over.
      - uses: ./.github/actions/setup-backend
        with:
          coverage: pcov
```

The job's `actions/checkout` (with `fetch-depth: 0`) stays before it, and `Fetch the base branch` stays after it.

- [ ] **Step 4: Lint**

Run: `actionlint .github/workflows/ci.yml`
Expected: no output, exit 0. actionlint also resolves the local action's `inputs` and runs shellcheck over the `run:` blocks.

Run: `ruby -e 'require "yaml"; YAML.load_file(".github/actions/setup-backend/action.yml")' && echo ok`. actionlint does not lint composite actions on their own.
Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add .github/actions/setup-backend/action.yml .github/workflows/ci.yml
git commit -m "chore(#1276): static checks run once, in their own job"
```

---

### Task 2: MySQL boots while the job installs

**Files:**
- Modify: `.github/workflows/ci.yml` (the `backend` job from Task 1)

**Interfaces:**
- Consumes: the `backend` job as Task 1 left it.
- Produces: a container named `mysql`, publishing `3306`, on the MySQL leg only. Tasks 3–4 do not depend on it.

- [ ] **Step 1: Delete the `services:` block**

Remove the whole `services:` block from the `backend` job, from `    services:` down to `          --health-retries 5`, and the blank line after it.

- [ ] **Step 2: Start MySQL as the first step**

Insert this as the first entry under `steps:`, before `- uses: actions/checkout@v5`:

```yaml
      # Awaited only before its first use, so the pull and first boot overlap
      # checkout and install.
      - name: Start MySQL
        if: matrix.database == 'mysql'
        working-directory: ${{ github.workspace }}
        run: >-
          docker run --detach --name mysql
          --env MYSQL_ROOT_PASSWORD=root
          --env MYSQL_DATABASE=feedreader_test
          --publish 3306:3306
          mysql:8.4
```

- [ ] **Step 3: Wait for it before the migration check**

Insert this directly after the `Select database DSN` step and before the migration-check comment:

```yaml
      # The image first boots a temporary server with networking off, so only a
      # TCP connection proves the real one is up; `mysqladmin ping` answers early.
      - name: Wait for MySQL
        if: matrix.database == 'mysql'
        run: |
          for _ in $(seq 1 90); do
            if docker exec mysql mysql --host=127.0.0.1 --user=root --password=root --execute='SELECT 1' >/dev/null 2>&1; then
              exit 0
            fi
            sleep 1
          done
          docker logs mysql
          echo 'MySQL accepted no TCP connection within 90s.' >&2
          exit 1
```

- [ ] **Step 4: Lint**

Run: `actionlint .github/workflows/ci.yml`
Expected: no output, exit 0.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "chore(#1276): mysql boots while the job installs, and only on its own leg"
```

---

### Task 3: Caches and a parallel PHPCS

**Files:**
- Modify: `.github/actions/setup-backend/action.yml`
- Modify: `.github/workflows/ci.yml` (the `backend-static` job)
- Modify: `backend/phpcs.xml.dist:6`

**Interfaces:**
- Consumes: the composite action and the `backend-static` job from Task 1.
- Produces: nothing later tasks call.

- [ ] **Step 1: Composer's download cache in the composite action**

In `.github/actions/setup-backend/action.yml`, insert these two steps between `Set up PHP` and `Install dependencies`:

```yaml
    - name: Locate Composer's download cache
      id: composer-cache
      shell: bash
      working-directory: backend
      run: echo "directory=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"

    - name: Restore Composer's download cache
      uses: actions/cache@v6
      with:
        path: ${{ steps.composer-cache.outputs.directory }}
        key: composer-${{ runner.os }}-${{ hashFiles('backend/composer.lock') }}
        restore-keys: composer-${{ runner.os }}-
```

- [ ] **Step 2: PHPStan's result cache**

In the `backend-static` job, insert this step between `Warm cache for PHPStan` and `PHPStan`:

```yaml
      # PHPStan drops whatever a change invalidated, so any earlier run's cache
      # is a safe start. It must stay before tramp:update, which rewrites vendor.
      - name: Restore PHPStan's result cache
        uses: actions/cache@v6
        with:
          path: /tmp/phpstan
          key: phpstan-${{ github.sha }}
          restore-keys: phpstan-
```

- [ ] **Step 3: PHPCS in parallel**

In `backend/phpcs.xml.dist`, add a line after `<arg value="p"/>` (line 6):

```xml
    <arg name="parallel" value="4"/>
```

- [ ] **Step 4: Verify PHPCS locally**

Run, from `backend/`: `time composer cs`
Expected: exit 0 with no violations.

Run, from `backend/`: `time vendor/bin/phpcs --parallel=1`. A command-line argument overrides the ruleset's `<arg>`, so this is the serial baseline.
Expected: the same result, and a clearly longer wall time. Record both times for the PR body.

- [ ] **Step 5: Lint**

Run: `actionlint .github/workflows/ci.yml`
Expected: no output, exit 0.

- [ ] **Step 6: Commit**

```bash
git add .github/actions/setup-backend/action.yml .github/workflows/ci.yml backend/phpcs.xml.dist
git commit -m "chore(#1276): cache composer downloads and phpstan results, run phpcs in parallel"
```

---

### Task 4: ParaTest

**Files:**
- Modify: `backend/composer.json` (`require-dev`, `scripts`), `backend/composer.lock`
- Modify: `.github/workflows/ci.yml` (the `backend` job's `PHPUnit` step)
- Modify: `CLAUDE.md:19` (the Backend command list)

**Interfaces:**
- Consumes: `TEST_TOKEN` isolation as it exists: `tests/Support/WorkerIsolation.php`, `tests/Support/WorkerDatabaseUrl.php`, and `config/packages/doctrine.yaml:43`.
- Produces: `composer test:parallel`.

- [ ] **Step 1: Add the dependency, without moving PHPUnit (D6)**

From `backend/`:

```bash
composer require --dev brianium/paratest
```

Expected: Composer resolves a ParaTest 7.x whose `phpunit/phpunit` constraint admits 12.5.31. Then check that PHPUnit did not move:

Run: `composer show phpunit/phpunit | grep ^versions`
Expected: `versions : * 12.5.31`

If Composer reports that no version is installable without updating PHPUnit, **stop and report**. Do not add `-W`.

- [ ] **Step 2: Add the script**

In `backend/composer.json`, insert this after the `"test"` script (after line 137):

```json
        "test:parallel": [
            "@putenv OTEL_PHP_DISABLED_INSTRUMENTATIONS=all",
            "@php bin/console cache:warmup --env=test --quiet",
            "@php bin/console lexik:jwt:generate-keypair --env=test --skip-if-exists --quiet",
            "paratest"
        ],
```

The two console commands are D7: the workers share the compiled test container and the JWT keypair, so both are built once, before any worker starts.

- [ ] **Step 3: Run it natively (SQLite), three times**

From `backend/`:

```bash
for run in 1 2 3; do composer test:parallel || break; done
```

Expected, on every run: `OK` or `OK, but some tests were skipped!`, and `Tests: 6669` (or the current serial count; take it from `php bin/phpunit` if it differs). If the test count differs from the serial run, ParaTest is missing or duplicating tests: **stop and report**.

If a failure appears that `php bin/phpunit --filter '<that test>'` does not reproduce serially, it is an isolation bug. **Stop and report** the test and its message; do not add retries. A shared temporary file or a fixed cache key would be the usual suspects.

- [ ] **Step 4: Run it in Docker (MySQL), three times**

First check the container is current (memory: "Check the container is current"). Run `docker compose exec php printenv APP_CACHE_DIR`; it must print `/app/var/cache-docker`.

```bash
for run in 1 2 3; do docker compose exec -T php composer test:parallel || break; done
```

Expected: the same as Step 3. The container sees 10 CPUs, so ParaTest starts 10 workers and creates `feedreader_test_test1` … `feedreader_test_test10`. That is expected. Those are test databases, not the dev database.

- [ ] **Step 5: Use it in CI**

In the `backend` job of `.github/workflows/ci.yml`, replace:

```yaml
      - name: PHPUnit
        run: php bin/phpunit
```

with:

```yaml
      - name: PHPUnit (ParaTest)
        run: composer test:parallel
```

- [ ] **Step 6: Correct the DSN comment the workers make stale**

In the `backend` job's `Select database DSN` comment, replace the last two lines:

```yaml
      # whereas doctrine.yaml's when@test dbname_suffix puts PHPUnit on
      # feedreader_test_test (MySQL) and var/data_test.db (SQLite).
```

with:

```yaml
      # whereas doctrine.yaml's when@test dbname_suffix puts each ParaTest
      # worker on feedreader_test_test<N> (MySQL) or var/data_test<N>.db (SQLite).
```

- [ ] **Step 7: Document the command**

In `CLAUDE.md`, insert this after the `composer test` line (line 19), aligned like its neighbours:

```bash
composer test:parallel    # the suite over ParaTest, one TEST_TOKEN worker per CPU — what CI runs
```

- [ ] **Step 8: Lint and the backend gates**

Run: `actionlint .github/workflows/ci.yml`
Expected: no output, exit 0.

Run, from `backend/`: `composer validate --strict`
Expected: `./composer.json is valid`.

- [ ] **Step 9: Commit**

```bash
git add backend/composer.json backend/composer.lock .github/workflows/ci.yml CLAUDE.md
git commit -m "chore(#1276): run the suite over paratest, one worker per cpu"
```

---

> **Amended during execution (ruling):** ParaTest runs on the CI MySQL leg only, with the prep commands in the CI step and no `test:parallel` script or CLAUDE.md line. Natively on SQLite, ParaTest's main process runs `tests/bootstrap.php`, whose Dotenv exports `DATABASE_URL` and `SYMFONY_DOTENV_VARS`. The workers' `bin/console` children then reload `.env.test` and drop the per-worker file, so all workers share `var/data_test.db`. In Docker, the `feedreader` user is granted only `feedreader_test.*` (`docker/mysql/init.sql`), not `feedreader_test<N>`. Both are a follow-up.

### Task 5: Measure on CI, then open the PR

**Files:** none.

- [ ] **Step 1: Push and open the PR**

```bash
git push -u origin chore/1276-ci-backend-speed
```

Open a PR into `develop` whose body ends with `Closes #1276`. Bind it with the ccd_pr tools as the harness instructs.

- [ ] **Step 2: Measure the cold run**

When the run is complete (take its id from `gh pr checks` or `gh run list --branch chore/1276-ci-backend-speed --workflow ci.yml --limit 1`):

```bash
gh run view <run-id> --json jobs -q '.jobs[] | .name as $job | .steps[] | select(.completedAt != null and .startedAt != null) | [$job, .name, ((.completedAt|fromdateiso8601)-(.startedAt|fromdateiso8601))] | @tsv' | awk -F'\t' '$3>2'
```

This run is cold for both caches, since `develop` has never saved either.

- [ ] **Step 3: Measure a warm run**

```bash
gh run rerun <run-id>
```

When it completes, run the same measurement. Expected: `Install dependencies` drops, because the Composer cache hits. `PHPStan` drops well below its ~60s. If PHPStan does not drop, D4 says to remove its cache step, push, and note why in the PR body.

Two green runs also count as the parallel-suite flake check in the cold state, since `config/jwt` never exists on a runner (D7).

- [ ] **Step 4: Put the numbers in the PR body**

Add a table to the PR body. It shows each backend job's total and each step's time, before (from #1276) and after (the warm run). Include the PHPCS local serial-vs-parallel pair from Task 3 Step 4. If a target in the Goal table was missed by more than about 20s, say which step missed it and why.
