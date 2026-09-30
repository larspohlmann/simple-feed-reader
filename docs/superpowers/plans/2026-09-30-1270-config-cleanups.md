# Config Cleanups: Stale CastInt Ignores, Public Test Services, a Config-Comment Gate (#1270) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1270 in one PR (`chore/1270-config-cleanups`, `Closes #1270`):
- **Item 1:** `backend/infection.json5` ignores `CastInt` only in the two repository methods that still cast a `COUNT()`, and says why.
- **Item 2:** `backend/config/services_test.yaml` keeps `public: true` only where a test needs it. The source predicts that one of its 68 `public: true` entries stays; the executor proves each entry by removing it and running the tests that fetch it.
- **Item 3:** the one-off #1171 config-comment survey becomes `backend/bin/check-config-comments.sh`. `composer comments:config` runs it, as do `composer check` and CI. It exits 1 on any backend config comment block of more than three prose lines, and it still skips the lines a Symfony recipe installed.

**Architecture:**
- **The test container reaches every private service that something in the app depends on.** `public: true` is only for an id nothing live depends on (D3).
- **An entry that only makes a class public goes whole.** Removing only `public: true` would leave an `autowire: true` entry that still strips the services.yaml definition of its autoconfigure and binds (D5).
- **The gate is the survey that #1171 PR W already trusted.** It keeps the same awk and perl, the same files and the same recipe list. The changes are a fixed root, the recipe list in a heredoc, and an exit status (D8).
- **Guards land with a deletion check.** Each scanner of the gate (`#`, `//`, XML) fails on a four-line probe. It passes at three lines, and it fails on the recipe blocks once the exclusion is removed.

**Tech Stack:** Symfony 7.4 DI (`TestContainer`, `TestServiceContainerWeakRefPass`/`RealRefPass`, `RemoveUnusedDefinitionsPass`, `InlineServiceDefinitionsPass`), Infection 0.34 (`CastInt`), PHPUnit 12.5 (several paths per run), bash (3.2-safe), awk (BSD, mawk), perl, ShellCheck, GitHub Actions.

**Spec:**
- GitHub issue #1270 (`gh issue view 1270 --repo larspohlmann/simple-feed-reader`).
- CLAUDE.md: "Default to no comment", "One line. Three at the absolute most", `CommentBlockLengthRule`, and "Mutation testing gates changed files".
- The survey's origin: `docs/superpowers/plans/2026-09-29-1171-comment-rules.md`, Task W0 Step 6 (the script) and Task W7 Step 3 (its clean run and the recipe control).

## Decisions

- **D1 (item 1, what is stale):** at bf742411, four of the six `CastInt` ignores name a method with no `(int)` cast. They are `SavedSearchController::list` (`src/Controller/Api/SavedSearchController.php:32`–`47`), `SubscriptionController::list` (`src/Controller/Api/SubscriptionController.php:46`–`52`) and `SavedSearchTallies::forAll`/`forOne` (`src/Service/Search/SavedSearchTallies.php:29`–`45`). `grep -n "(int)"` over those three files prints nothing. The same grep on `src/Repository/SavedSearchEntryRepository.php`, the positive control, prints line 126. Both repository ignores cast a `COUNT()`:
  - `SavedSearchEntryRepository.php:126`: `$countsBySearch[$row['searchId']] = (int) $row['memberCount'];`
  - `SubscriptionRepository.php:193`: `$counts[$row['subscriptionId']] = (int) $row['entryCount'];`

  The issue names only `memberCountsBySavedSearch` as the one to keep. Its own rule, "remove each ignore whose method has no `(int)` cast", keeps `entryCountsForUser` too, so both stay. The stale `(int) $entity->getId()` comment is replaced by the `COUNT()` reason. The inner two-line comment folds into it.
- **D2 (item 1, mutants):** `CastInt` mutates only `(int)` casts, and an ignore applies to one mutator in one method. Removing the four ignores therefore cannot expose a mutant: no method is at risk, and no test needs to be written. Task 2 proves this with a `CastInt`-only Infection run over the three files, which should generate 0 mutations. Its positive control runs over `SubscriptionRepository.php` and should generate 2 mutations: lines 26 and 75, with line 193's method ignored. Task 2 Step 4 says what to do if a later merge has put a cast into one of the three files.
- **D3 (item 2, the Symfony rule, read from `backend/vendor` at the locked versions):**
  - `TestServiceContainerWeakRefPass` puts every private definition and every private alias into `test.private_services_locator` by a weak (`IGNORE_ON_UNINITIALIZED_REFERENCE`) reference. `TestServiceContainerRealRefPass` keeps an entry only if its target survived compilation.
  - `RemoveUnusedDefinitionsPass::processValue()` does not follow weak references. A private service that nothing live references is therefore removed, and `get()` answers "has been removed or inlined when the container was compiled".
  - `InlineServiceDefinitionsPass::isInlineableDefinition()` returns false on any weak or lazy in-edge (`if ($edge->isWeak() || $edge->isLazy()) { return false; }`). The locator gives every private service such an edge, so in the test env a private service that is used is never inlined.
  - `TestContainer::set()` stores a replacement for a private service in the container's `privates`, which is where its consumers read it. The limit is the one a public service has: the service must not be initialized yet.
  - The suite already relies on this rule:
    - `tests/Service/Passkey/AttestationVerifierTest.php:127` fetches `AttestationVerifier`, whose one consumer is `PasskeyController.php:35`.
    - `tests/Service/Refresh/UserRefreshScopeTest.php:101` fetches `UserRefreshScope`, whose one consumer is `RefreshController.php:26`.
    - `tests/Controller/Admin/AdminCatalogControllerTest.php:40` replaces `FaviconResolver`.
    - Tests call `get('security.user_password_hasher')` five times.

    None of these services is public.
- **D4 (item 2, the count):** the issue's "up to 11" undercounts. Of the 68 `public: true` entries, 67 have a live dependant once their entry is gone, and each Pnn step below names it. The one without is the `CompletionStreamHeartbeatInterface` alias. Its only consumer, `OpenAiCompatibleChatClient` (`src/Service/Ai/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php:61`), is itself removed in the test env, because the test alias sends `ChatCompletionClientInterface` to `StubChatClient`. Nothing else in `src` names that client or `CompositeCompletionStreamHeartbeat`. These are predictions; the per-entry run decides. P01 is removed first, as the procedure's positive control: it must fail with the "removed or inlined" message.
- **D5 (item 2, whole entries):** a class entry that exists only to add `public: true` is deleted whole. Leaving `autowire: true` alone would still replace the services.yaml definition, and this file has no `_defaults`, so the class would keep losing its autoconfigure and binds. At bf742411, that gap leaves these untagged in the test env:
  - the three `#[AsMessageHandler]` worker handlers;
  - the `#[AsSchedule('worker')]` `WorkerSchedule`;
  - `EntryListRepository`, which has no `doctrine.repository_service` tag.

  Deleting the entry restores the prod wiring, and the per-entry runs and the full suite catch any test that relied on the gap. Eight aliases go whole too:
  - six that repeat a `services.yaml` alias with the same target (`services.yaml:74`, `75`, `80`, `151`, `162`, `174`);
  - `LockFactory` and `UserPasswordHasherInterface`, which FrameworkBundle and SecurityBundle already alias.

  The seventh repeat, of `services.yaml:156`, is P01, the one entry predicted to stay public (D4).

  The test-only definitions and aliases keep their entry and lose only `public: true`: `StubChatClient`, the `ChatCompletionClientInterface` alias, `QueryRecorder`, and the three `test.cache.*` pool aliases that tests use.
- **D6 (item 2, dead ids):** no file names `test.cache.altcha_replay`, `test.cache.oauth_state` or `test.cache.oauth_login_code`. `git grep` at bf742411 finds each only in `services_test.yaml` (lines 106, 117, 125), against `RefreshControllerTest.php`'s 1 hit for `test.cache.refresh_run`. All three are deleted whole (Task 3 Step 2).
- **D7 (item 2, test files that describe the old wiring):**
  - `FeedPreviewControllerTest.php:56` says "(public in services_test.yaml for this)". The parenthetical goes when P14 removes that alias.
  - `ActionTokenServiceTest::testTheServiceIsFetchableFromTheTestContainer` (lines 160–170) guards the very redefinition that P21 deletes. It goes, with its docblock, when P21 does. The service's functional tests fetch it from the container anyway (`PasswordResetTest`, `RegistrationTest`).
  - `RecommendationDrainOnTerminateListenerTest.php:33` names a "RecordingProcessLauncher (services_test.yaml)" that `services_test.yaml` never held: the test sets it itself, at line 50. The comment is corrected.
- **D8 (item 3, form and home):** the gate is a bash script at `backend/bin/check-config-comments.sh`, not PHP. It is the #1171 survey's proven awk and perl, with the recipe list in a heredoc so it stays one file, and an exit status. `bin/` is outside PHPCS and PHPStan (it holds Symfony recipe files), and a line scan gains nothing from PHPStan. The script needs bash, awk and perl, which the host (macOS) and CI (ubuntu-latest) have. `composer check` is a host command (CLAUDE.md, "Commands"), and the Alpine php container is not where it runs.
- **D9 (item 3, wiring):**
  - CI does not run `composer check`: the backend job runs `composer cs`, `stan`, `md` and `tramp` as separate steps (`.github/workflows/ci.yml:48`–`82`). It gets its own `composer comments:config` step after PHPCS.
  - The ShellCheck step lists its files explicitly and covers nothing under `backend/bin` (`ci.yml:246`). The new script is added to that list, which is where a ShellCheck finding would fail CI.
  - The three existing `backend/bin/*.sh` stay out of the list, as outside this issue.
  - In `composer check`, the new check runs second, after `@cs`, so a cheap failure comes before PHPStan.
- **D10 (item 3, scope and expected output):** the script reads the same files as the #1171 survey:
  - `config/*.yaml`, `config/packages/*.yaml`, `config/routes/*.yaml`;
  - `phpstan.dist.neon`, `.env`, `.env.dev`, `.env.test`;
  - `infection.json5`;
  - `phpcs.xml.dist`, `phpmd.xml.dist`, `phpunit.dist.xml`, `phpunit-e2e.xml.dist`.

  At bf742411 it reports nothing. #1171 PR W ran this survey to an empty result (that plan's Task W7 Step 3: "the first prints nothing"). The one later commit that touches a scanned file, `09d124da9`, shortened two `rate_limiter.yaml` blocks to one line each. `git log 09d124da9..bf742411` over the scanned files is empty. Task 1 Step 3 runs the check before any other change all the same.

## Questions for the planner

- **Q1:** Should the gate's scope be "backend config only, as the #1171 survey" or "every YAML and config comment in the repo"? Planned: backend only. Outside it, `.github/workflows/ci.yml:177`–`183` holds a six-prose-line block and `docker/php/Dockerfile:1`–`10` an eight-prose-line one, so widening the scope is its own sweep.
- **Q2:** For `ActionTokenServiceTest::testTheServiceIsFetchableFromTheTestContainer`, is it "delete the test with its docblock" or "keep the test, delete only the docblock"? Planned: delete (D7). Nothing redefines the service any more, and two functional tests fetch it.
- **Q3:** For the three `test.cache.*` aliases tests use, is it "keep them as private aliases" or "point the 22 test call sites at the pool ids and delete the aliases"? Planned: keep. The alias costs nothing, and the rewrite touches 22 test files for no change in behaviour.

## File map

- Create: `backend/bin/check-config-comments.sh` (mode 100755).
- Modify:
  - `backend/composer.json` (`comments:config`, `check`);
  - `.github/workflows/ci.yml` (a backend step, the ShellCheck list);
  - `CLAUDE.md` (the command list and the rule list);
  - `CONTRIBUTING.md` (the `composer check` line);
  - `backend/infection.json5` (the `CastInt` block);
  - `backend/config/services_test.yaml` (the header and the 68 entries);
  - `backend/tests/Controller/Api/FeedPreviewControllerTest.php` (docblock, if P14 lands);
  - `backend/tests/Service/Auth/ActionTokenServiceTest.php` (one test, if P21 lands);
  - `backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php` (class docblock).
- Unchanged: every `src/` file, `config/services.yaml`, every Symfony recipe file and its recipe lines.

## Global constraints

- Work from `backend/` unless a step says otherwise. The checkout is shared with other sessions: run `git status --short` before any `switch`, and never `stash`, `reset` or `checkout --`. Restore an edit by applying its Before again.
- Commits are `type(#1270): <what>`, with no attribution or co-author lines.
- Every comment written here obeys CLAUDE.md: default none, at most three lines, no narration.

---

### Task 0: Preflight, branch, plan copy

**Files:** create `docs/superpowers/plans/2026-09-30-1270-config-cleanups.md`.

- [ ] **Step 1: Preflight (read-only)**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git status --short
git fetch origin
git log -1 --format=%h origin/develop
git diff --stat bf742411 origin/develop -- backend/config backend/infection.json5 backend/composer.json backend/.env backend/.env.dev backend/.env.test backend/phpstan.dist.neon backend/phpcs.xml.dist backend/phpmd.xml.dist backend/phpunit.dist.xml backend/phpunit-e2e.xml.dist backend/bin .github/workflows/ci.yml CLAUDE.md CONTRIBUTING.md backend/tests/Controller/Api/FeedPreviewControllerTest.php backend/tests/Service/Auth/ActionTokenServiceTest.php backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php backend/src/Controller/Api/SavedSearchController.php backend/src/Controller/Api/SubscriptionController.php backend/src/Service/Search/SavedSearchTallies.php backend/src/Repository/SubscriptionRepository.php backend/src/Repository/SavedSearchEntryRepository.php
```

Expected: `git status` shows no change of yours. The diff prints nothing, which means every Before in this plan is still verbatim. If it prints a file, stop and report the file list to the planner, who reconciles the plan at the new SHA.

- [ ] **Step 2: Branch**

```bash
git switch -c chore/1270-config-cleanups origin/develop
```

- [ ] **Step 3: Copy this plan and commit it**

Copy this plan file verbatim to `docs/superpowers/plans/2026-09-30-1270-config-cleanups.md`.

```bash
git add docs/superpowers/plans/2026-09-30-1270-config-cleanups.md
git commit -m "docs(#1270): add plan"
```

---

### Task 1: The config-comment gate

**Files:**
- Create: `backend/bin/check-config-comments.sh`
- Modify: `backend/composer.json`, `.github/workflows/ci.yml`, `CLAUDE.md`, `CONTRIBUTING.md`

- [ ] **Step 1: Write the script**

Create `backend/bin/check-config-comments.sh`. Paste the heredoc body line for line: the recipe lines are matched verbatim, backslashes and spacing included.

```bash
#!/usr/bin/env bash
# Fails on a backend config comment block of more than three prose lines, the bar CommentBlockLengthRule sets for PHP.
# A blank line does not split a block, and a line a Symfony recipe installed (recipe_lines) is not counted.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

recipe_lines() {
    cat <<'RECIPE'
#
#    App\Entity\: []
#  * .env                contains default values for the environment variables needed by the app
#  * .env.$APP_ENV       committed environment-specific defaults
#  * .env.$APP_ENV.local uncommitted environment-specific overrides
#  * .env.local          uncommitted file with local overrides
# "TEST_TOKEN" is typically set by ParaTest
# APCu (not recommended with heavy random-write workloads as memory fragmentation can cause perf issues)
# Configure how to generate URLs in non-HTTP contexts, such as CLI commands.
# DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=10.11.2-MariaDB&charset=utf8mb4"
# DATABASE_URL="mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0.32&charset=utf8mb4"
# DO NOT DEFINE PRODUCTION SECRETS IN THIS FILE NOR IN ANY OTHER COMMITTED FILES.
# Enables validator auto-mapping support.
# Files in the packages/ subdirectory configure your dependencies.
# For instance, basic validation constraints will be inferred from Doctrine's metadata.
# Format described at https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url
# IMPORTANT: You MUST configure your server version,
# IMPORTANT: You MUST configure your server version, either here or in config/packages/doctrine.yaml
# In all environments, the following files are loaded if they exist,
# Namespaced pools use the above "app" backend by default
# Other options include:
# Put parameters here that don't need to change on each machine where the app is deployed
# Real environment variables win over .env files.
# Redis
# Run "composer dump-env prod" to compile .env files for production use (requires symfony/flex >=1.2).
# See https://symfony.com/doc/current/routing.html#generating-urls-in-commands
# The "app" cache stores to the filesystem by default.
# The data in this cache should persist between deploys.
# This file is the entry point to configure your own services.
# Unique name of your app: used to compute stable namespaces for cache keys.
# add more service definitions when explicit configuration is needed
# as migrations classes should NOT be autoloaded
# default configuration for services in *this* file
# define your env variables for the test env here
# either here or in the DATABASE_URL env var (see .env file)
# https://symfony.com/doc/current/best_practices.html#use-environment-variables-for-infrastructure-configuration
# https://symfony.com/doc/current/best_practices.html#use-parameters-for-application-configuration
# https://symfony.com/doc/current/configuration/secrets.html
# makes classes in src/ available to be used as services
# namespace is arbitrary but should be different from App\Migrations
# please note that last definitions always *replace* previous ones
# the latter taking precedence over the former:
# this creates a service per class whose id is the fully-qualified class name
###< doctrine/doctrine-bundle ###
###< symfony/framework-bundle ###
###< symfony/routing ###
###> doctrine/doctrine-bundle ###
###> symfony/framework-bundle ###
###> symfony/routing ###
#app: cache.adapter.apcu
#app: cache.adapter.redis
#auto_mapping:
#default_redis_provider: redis://localhost
#my.dedicated.cache: null
#pools:
#prefix_seed: your_vendor_name/app_name
#server_version: '16'
RECIPE
}

line_comment_blocks() {
    local marker=$1
    shift
    awk -v marker="$marker" -v root="$root" '
        function flush() {
            if (prose > 3) {
                printf "%s:%d: %d prose lines\n", name, first, prose
                found = 1
            }
            prose = 0
            first = 0
        }
        FILENAME == ARGV[1] { recipe[$0] = 1; next }
        FNR == 1 { flush(); name = substr(FILENAME, length(root) + 2) }
        { line = $0; sub(/^[ \t]+/, "", line) }
        line ~ ("^" marker) {
            if (first == 0) first = FNR
            if (line ~ /^###[<>] / || (line in recipe)) next
            text = line
            sub("^" marker "+[ \t]*", "", text)
            if (text != "") prose++
            next
        }
        line == "" { next }
        { flush() }
        END { flush(); exit found }
    ' <(recipe_lines) "$@"
}

xml_comment_blocks() {
    ROOT=$root perl -0ne '
        while (/<!--(.*?)-->/gs) {
            my ($text, $line) = ($1, 1 + (substr($_, 0, $-[0]) =~ tr/\n//));
            my $prose = grep { /\S/ } split /\n/, $text;
            (my $name = $ARGV) =~ s{^\Q$ENV{ROOT}\E/}{};
            if ($prose > 3) {
                print "$name:$line: $prose prose lines\n";
                $found = 1;
            }
        }
        END { $? = 1 if $found }
    ' "$@"
}

status=0
line_comment_blocks '#' "$root"/config/*.yaml "$root"/config/packages/*.yaml "$root"/config/routes/*.yaml \
    "$root"/phpstan.dist.neon "$root"/.env "$root"/.env.dev "$root"/.env.test || status=1
line_comment_blocks '//' "$root"/infection.json5 || status=1
xml_comment_blocks "$root"/phpcs.xml.dist "$root"/phpmd.xml.dist "$root"/phpunit.dist.xml \
    "$root"/phpunit-e2e.xml.dist || status=1

if ((status)); then
    echo 'Config comment blocks hold three prose lines at most (CLAUDE.md): shorten each block above.' >&2
fi
exit "$status"
```

Then make it executable and check that the heredoc is the #1171 list, line for line:

```bash
chmod +x bin/check-config-comments.sh
awk "/^    cat <<'RECIPE'\$/{on=1; next} /^RECIPE\$/{on=0} on" bin/check-config-comments.sh | wc -l
awk "/^    cat <<'RECIPE'\$/{on=1; next} /^RECIPE\$/{on=0} on" bin/check-config-comments.sh | grep -c -E '^#'
```

Expected: `57`, then `57`. The heredoc holds the 57 recipe lines, and every one starts with `#`, which is the positive control that the extraction reads the right span.

- [ ] **Step 2: Wire it into Composer**

`backend/composer.json`, Before:

```json
        "tramp:update": "composer update phptramp/phptramp --no-interaction --no-progress --no-scripts",
        "check": [
            "@cs",
            "@stan",
            "@tramp"
        ],
```

After:

```json
        "tramp:update": "composer update phptramp/phptramp --no-interaction --no-progress --no-scripts",
        "comments:config": "bin/check-config-comments.sh",
        "check": [
            "@cs",
            "@comments:config",
            "@stan",
            "@tramp"
        ],
```

- [ ] **Step 3: Run it on the tree (expected clean)**

```bash
composer comments:config; echo "exit=$?"
```

Expected: composer echoes `> bin/check-config-comments.sh`, the script prints nothing, and the last line is `exit=0` (D10). If it prints a finding, stop. Report the lines to the planner: a later merge added a block, and trimming it is the planner's ruling, not this task's.

- [ ] **Step 4: Deletion check: each scanner fails on four lines and passes on three**

Add three probes. `backend/config/packages/lock.yaml`, Before:

```yaml
framework:
    lock: 'doctrine.dbal.default_connection'
```

After:

```yaml
# Probe one.
# Probe two.
# Probe three.
# Probe four.
framework:
    lock: 'doctrine.dbal.default_connection'
```

`backend/infection.json5`, Before (lines 1–2):

```json5
{
    $schema: 'vendor/infection/infection/resources/schema.json',
```

After:

```json5
{
    // Probe one.
    // Probe two.
    // Probe three.
    // Probe four.
    $schema: 'vendor/infection/infection/resources/schema.json',
```

`backend/phpcs.xml.dist`, Before:

```xml
    <description>PSR-12 for the backend.</description>
    <arg name="extensions" value="php"/>
```

After:

```xml
    <description>PSR-12 for the backend.</description>
    <!-- Probe one.
         Probe two.
         Probe three.
         Probe four. -->
    <arg name="extensions" value="php"/>
```

Run:

```bash
composer comments:config; echo "exit=$?"
```

Expected FAIL. The script prints these three lines in this order:

```
config/packages/lock.yaml:1: 4 prose lines
infection.json5:2: 4 prose lines
phpcs.xml.dist:4: 4 prose lines
```

It then prints `Config comment blocks hold three prose lines at most (CLAUDE.md): shorten each block above.`, and Composer prints `Script bin/check-config-comments.sh handling the comments:config event returned with error code 1`. The last line is `exit=1`.

Now delete the `# Probe four.` line, the `// Probe four.` line, and in the XML replace `         Probe three.\n         Probe four. -->` with `         Probe three. -->`. Run the same command again. Expected: nothing from the script, `exit=0`. Three lines pass, so the bar is `> 3`.

Restore all three files by applying their Before again (edit, not `git checkout`), then run:

```bash
git diff --stat -- config/packages/lock.yaml infection.json5 phpcs.xml.dist
```

Expected: no output.

- [ ] **Step 5: Deletion check: the recipe exclusion is live**

In `backend/bin/check-config-comments.sh`, Before:

```
            if (line ~ /^###[<>] / || (line in recipe)) next
```

After (temporary):

```
            if (line ~ /^###[<>] /) next
```

Run:

```bash
bin/check-config-comments.sh; echo "exit=$?"
bin/check-config-comments.sh 2>/dev/null | grep -c -E '^(config/services\.yaml:1: 4|config/packages/cache\.yaml:3: 10|config/packages/validator\.yaml:3: 4|\.env:1: 11) prose lines$'
```

Expected FAIL. The first command prints `config/services.yaml:1: 4 prose lines`, `config/packages/cache.yaml:3: 10 prose lines`, `config/packages/validator.yaml:3: 4 prose lines` and `.env:1: 11 prose lines`, then the message and `exit=1`. These four are the recipe blocks that the #1171 plan's Task W7 Step 3 named. The second command prints `4`. Quote the first command's output in the task report.

Restore the Before line exactly, then run `bin/check-config-comments.sh; echo "exit=$?"`. Expected: nothing, then `exit=0`.

- [ ] **Step 6: ShellCheck**

From the repository root:

```bash
shellcheck backend/bin/check-config-comments.sh; echo "exit=$?"
```

Expected: no output, `exit=0`. ShellCheck does not flag `$` inside the single-quoted program of `awk` or `perl`. If it does report SC2016 on one of them, add `# shellcheck disable=SC2016` on the line directly above that `awk` or `ROOT=$root perl` line (a tool directive, not prose) and run again. If `shellcheck` is not installed, run `brew install shellcheck` first. CI runs it in any case (Step 7).

- [ ] **Step 7: CI runs it, and ShellCheck covers it**

`.github/workflows/ci.yml`, Before:

```yaml
      - name: PHPCS (PSR-12)
        run: composer cs

      - name: Warm cache for PHPStan
```

After:

```yaml
      - name: PHPCS (PSR-12)
        run: composer cs

      - name: Config comments (three prose lines at most)
        run: composer comments:config

      - name: Warm cache for PHPStan
```

Before:

```yaml
        run: shellcheck scripts/*.sh scripts/test/*.sh docker/php/entrypoint-prod.sh docker/web/10-select-mode.sh
```

After:

```yaml
        run: shellcheck scripts/*.sh scripts/test/*.sh docker/php/entrypoint-prod.sh docker/web/10-select-mode.sh backend/bin/check-config-comments.sh
```

Check that the workflow still parses:

```bash
php -r 'require "vendor/autoload.php"; $jobs = Symfony\Component\Yaml\Yaml::parseFile("../.github/workflows/ci.yml")["jobs"]; echo implode(",", array_keys($jobs)), "\n"; echo count(array_filter($jobs["backend"]["steps"], fn ($step) => ($step["run"] ?? "") === "composer comments:config")), "\n";'
```

Expected: `backend,mutation,frontend,scripts` (the four job keys at bf742411, the positive control), then `1`.

- [ ] **Step 8: Document the gate**

`CLAUDE.md`, Before:

```
composer tramp:update     # re-resolve phptramp to the tip of its develop branch
composer check       # cs + stan + tramp
```

After:

```
composer tramp:update     # re-resolve phptramp to the tip of its develop branch
composer comments:config  # backend config comment blocks keep to three prose lines
composer check       # cs + comments:config + stan + tramp
```

`CLAUDE.md`, Before:

```
- **`CommentBlockLengthRule`** (`tests/PhpStan/CommentBlockLengthRule.php`) — a comment block holds three lines
  of prose at most; PHPDoc types and tool directives are not prose, and a blank line does not split a block.
```

After:

```
- **`CommentBlockLengthRule`** (`tests/PhpStan/CommentBlockLengthRule.php`) — a comment block holds three lines
  of prose at most; PHPDoc types and tool directives are not prose, and a blank line does not split a block.
- **`bin/check-config-comments.sh`** (`composer comments:config`, and CI) — the same bar for backend config:
  `config/`, `.env*`, `infection.json5`, `phpstan.dist.neon` and the PHPCS, PHPMD and PHPUnit XML. Lines a
  Symfony recipe installed are not counted; the script lists them.
```

`CONTRIBUTING.md`, Before:

```
composer check       # PHP_CodeSniffer (PSR-12) + PHPStan level max
```

After:

```
composer check       # PHPCS (PSR-12), config comments, PHPStan level max, phptramp
```

- [ ] **Step 9: Verify and commit**

```bash
composer comments:config; echo "exit=$?"
composer run-script --list | grep -E '^ +(check|comments:config)( |$)'
git add bin/check-config-comments.sh && git ls-files -s bin/check-config-comments.sh
```

Expected: `exit=0`. The list shows two lines, `check` and `comments:config`, and `check` (present before this task) is the grep's positive control. The last command prints a line that starts with `100755`: the executable bit is staged.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add backend/bin/check-config-comments.sh backend/composer.json .github/workflows/ci.yml CLAUDE.md CONTRIBUTING.md
git commit -m "chore(#1270): config comment blocks keep to three prose lines under composer check and CI"
```

---

### Task 2: `CastInt` ignores only where a cast is

**Files:** modify `backend/infection.json5`.

- [ ] **Step 1: Prove which methods cast (grep with a positive control)**

```bash
grep -n "(int)" src/Controller/Api/SavedSearchController.php src/Controller/Api/SubscriptionController.php src/Service/Search/SavedSearchTallies.php; echo "exit=$?"
grep -n "(int)" src/Repository/SavedSearchEntryRepository.php src/Repository/SubscriptionRepository.php
```

Expected: the first grep prints nothing, then `exit=1`. The second is the positive control. It prints `SavedSearchEntryRepository.php` lines 90, 126, 201 and 202, and `SubscriptionRepository.php` lines 26, 75 and 193. Lines 126 and 193 are the two ignored methods' `COUNT()` casts (D1).

- [ ] **Step 2: Edit the ignore list**

`backend/infection.json5`, Before:

```json5
        // Cast on (int) $entity->getId() as an array key only narrows ?int for
        // PHPStan; the id is always set, so the key is identical.
        CastInt: {
            ignore: [
                'App\\Controller\\Api\\SavedSearchController::list',
                'App\\Controller\\Api\\SubscriptionController::list',
                'App\\Service\\Search\\SavedSearchTallies::forAll',
                'App\\Service\\Search\\SavedSearchTallies::forOne',
                // MySQL returns COUNT() as numeric string, so cast stays;
                // SQLite returns int.
                'App\\Repository\\SavedSearchEntryRepository::memberCountsBySavedSearch',
                'App\\Repository\\SubscriptionRepository::entryCountsForUser',
            ],
        },
```

After:

```json5
        // The COUNT() cast matters on MySQL, which returns a numeric string; SQLite returns an int, so on the native
        // mutation run dropping it is equivalent.
        CastInt: {
            ignore: [
                'App\\Repository\\SavedSearchEntryRepository::memberCountsBySavedSearch',
                'App\\Repository\\SubscriptionRepository::entryCountsForUser',
            ],
        },
```

- [ ] **Step 3: The files that lost their ignore generate no `CastInt` mutant**

Infection needs pcov or xdebug natively (CLAUDE.md). It gives each thread its own `TEST_TOKEN` (`ParallelProcessRunner`), so `WorkerIsolation` keeps the workers apart.

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all vendor/bin/infection --filter=src/Controller/Api/SavedSearchController.php,src/Controller/Api/SubscriptionController.php,src/Service/Search/SavedSearchTallies.php --mutators=CastInt --threads=max --no-progress --ignore-msi-with-no-mutations; echo "exit=$?"
```

Expected: `0 mutations were generated:` and `exit=0`.

Positive control. The same mutator does generate over a file with casts, and the kept ignore still holds:

```bash
OTEL_PHP_DISABLED_INSTRUMENTATIONS=all vendor/bin/infection --filter=src/Repository/SubscriptionRepository.php --mutators=CastInt --threads=max --no-progress --min-msi=0; echo "exit=$?"
```

Expected: `2 mutations were generated:`. These are the casts in `existsForUserAndFeed()` (line 26) and `countForUser()` (line 75). `entryCountsForUser()` (line 193) stays ignored. Expected `exit=0`. Whether these two are killed is not this PR's question, because the PR does not touch that file. Record the counts in the task report.

- [ ] **Step 4: If a `CastInt` mutation shows up in Step 3's first run**

It cannot at bf742411 (Step 1). If the count is not 0, a later-merged change put an `(int)` cast into one of these methods. Do not restore the ignore. Quote the mutant (file, line, diff), then:
- **A cast on an array key**, the pattern the old ignore covered, gives an equivalent mutant that no test can kill: PHP stores the key `'5'` as `5`. Remove the cast itself, since `requireId()` already returns `int`, and run Step 3 again. Expect `0 mutations were generated:`.
- **A cast on any other value:** stop and report the quoted mutant to the planner, who plans the killing test at that SHA.

- [ ] **Step 5: The edited config keeps to the gate, then commit**

```bash
composer comments:config; echo "exit=$?"
git diff --stat
```

Expected: `exit=0`, and one file changed: `backend/infection.json5`.

```bash
git add infection.json5
git commit -m "chore(#1270): CastInt is ignored only where a COUNT() cast is"
```

---

### Task 3: `services_test.yaml` keeps `public: true` only where a test needs it

**Files:**
- Modify: `backend/config/services_test.yaml`
- Modify: `backend/tests/Controller/Api/FeedPreviewControllerTest.php` (if P14 lands), `backend/tests/Service/Auth/ActionTokenServiceTest.php` (if P21 lands), `backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php`

**The procedure, for each entry Pnn (Steps 3 and 4):**
1. Apply its edit (Before → After) in `backend/config/services_test.yaml`.
2. Run its command from `backend/`. The test container recompiles on its own when the file changes. If a result looks stale, `rm -rf var/cache/test` and run again.
3. **PASS**: keep the edit. Record `Pnn removed`.
4. **FAIL** whose message contains `has been removed or inlined when the container was compiled` or `is private, you cannot replace it`, naming the entry's id or its target: paste the Before back where it was, run again, and confirm PASS. Record `Pnn needs public:` plus the quoted message.
5. **Any other FAIL**: paste the Before back and run again.
   - If it passes now, the removal changed behaviour (for example, a tag that autoconfigure restored). Keep the Before and record `Pnn behaviour:` plus the quoted failure, for the planner.
   - If it fails with the Before too, the failure is unrelated. Stop and report it.

Record every Pnn outcome in the task report. The planner gets the list of entries that needed public, and of entries that changed behaviour.

- [ ] **Step 1: The header states the rule**

Before:

```yaml
    # Test-only: `public: true` keeps a service the compiler would inline or remove, so a test can fetch or replace
    # it through self::getContainer(). Naming a class here replaces its services.yaml definition, and this file has
    # no `_defaults`, so a class with constructor arguments needs `autowire: true`.
```

After (the trailing blank line separates the header from the first entry):

```yaml
    # Test doubles and test-only ids. The test container already reaches every private service the app depends on, so
    # an entry is `public: true` only when nothing in the app does. Naming a class here replaces its services.yaml
    # definition; this file has no `_defaults`, so a class with constructor arguments needs `autowire: true`.

```

- [ ] **Step 2: Three `test.cache.*` ids no file names (grep with a positive control)**

From the repository root:

```bash
git grep -n -E "test\.cache\.(altcha_replay|oauth_state|oauth_login_code)" -- . ':!docs/superpowers'
git grep -c -E "test\.cache\.refresh_run" -- backend/tests
```

Expected: the first prints only `backend/config/services_test.yaml` lines, one each for `test.cache.altcha_replay:`, `test.cache.oauth_state:` and `test.cache.oauth_login_code:`. The second, the positive control, prints `backend/tests/Controller/Api/RefreshControllerTest.php:1`.

- [ ] **Step 3: P01, the positive control: an entry that must stay public**

**P01. `App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface`.** In the test env nothing depends on it (D4).

Before:

```yaml
    App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface:
        alias: App\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeat
        public: true

```

After (temporary): the lines are gone. Run:

```bash
php bin/phpunit tests/Service/Ai/Completion/CompletionStreamHeartbeat/CompletionStreamHeartbeatWiringTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php
```

Expected FAIL: `Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: The "App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface" service or alias has been removed or inlined when the container was compiled.` This is the error the procedure's rule 4 looks for. Quote it in the report.

Restore it with the one fact a later reader would otherwise get wrong:

```yaml
    # Public: nothing here takes it, as StubChatClient replaces its one consumer, OpenAiCompatibleChatClient.
    App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface:
        alias: App\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeat
        public: true

```

Run the same command. Expected: PASS.

If the removal PASSES instead, the prediction was wrong. Keep the entry removed, leave out the comment, and report it with the PASS output: the planner then decides whether the procedure needs another positive control before its results are trusted. Step 7's counts become `0` and `8`.

- [ ] **Step 4: P02 to P68, one at a time, by the procedure**

Take them in this order; a later entry's Before does not depend on an earlier outcome.

**P02. `App\Service\Mail\MailFailureRecorder\MailDeliveryHealth`** (delete the entry). Reached through: `AdminMailController::errors()` takes it as an action argument (`src/Controller/Admin/AdminMailController.php:56`).

Before (the entry and the blank line after it):

```yaml
    App\Service\Mail\MailFailureRecorder\MailDeliveryHealth:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Admin/AdminMailErrorsControllerTest.php tests/EventListener/DeferredMailFlushHealthTest.php tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Service/Mail/MailFailureRecorder/MailDeliveryHealthTest.php
```

**P03. `App\Service\Mail\Settings\MailConnectionTester`** (delete the entry). Reached through: `AdminMailController::test()` takes it as an action argument (`AdminMailController.php:42`).

Before (the entry and the blank line after it):

```yaml
    App\Service\Mail\Settings\MailConnectionTester:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Mail/Settings/MailConnectionTesterTest.php
```

**P04. `App\Service\Recommendation\Run\WorkerPresence`** (delete the entry). Reached through: seven constructors, first `RecommendationSettingsController.php:24`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\WorkerPresence:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Command/RecommendationDrainCommandTest.php tests/EventListener/RecommendationDrainOnTerminateListenerTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php tests/Service/Recommendation/Run/RecommendationDrainSpawnerTest.php tests/Service/Recommendation/Run/WorkerPresenceTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Service/Worker/WorkerRunSweepTest.php
```

**P05. `App\Service\Reader\Media\EmbedProviders`** (delete the entry). Reached through: nine constructors, first `DumpEmbedFrameAllowlistCommand.php:26` (a command).

Before (the entry and the blank line after it):

```yaml
    App\Service\Reader\Media\EmbedProviders:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Reader/Media/EmbedFrameAllowlistTest.php tests/Service/Reader/Media/EmbedProvidersWiringTest.php
```

**P06. `App\Service\Reader\Media\PageMediaScanner`** (delete the entry). Reached through: `ArticlePageReader.php:23` ← `ArticleExtractor.php:38` ← `EntryReaderController`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Reader\Media\PageMediaScanner:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php tests/Service/Reader/Media/PageMediaScannerWiringTest.php
```

**P07. `App\Service\Ai\ModelCatalog\ModelCatalogInterface`** (delete the entry). Reached through: same target as `services.yaml:151`; `AiProviderConfigurator.php:37` ← `AiSettingsController.php:37`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Ai\ModelCatalog\ModelCatalogInterface:
        alias: App\Service\Ai\ModelCatalog\OpenAiCompatibleCatalog
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php tests/Service/Ai/AiProviderConfiguratorTest.php
```

**P08. `App\Service\Ai\AiProviderConfigurator`** (delete the entry). Reached through: `AiSettingsController.php:37` and four services.

Before (the entry and the blank line after it):

```yaml
    App\Service\Ai\AiProviderConfigurator:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Ai/AiProviderConfiguratorTest.php tests/Service/Recommendation/Run/DueRecommendationRunFinderTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
```

**P09. `App\Service\Ai\AiConfigurationForUser`** (delete the entry). Reached through: `AiSettingsController.php:39`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Ai\AiConfigurationForUser:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Ai/AiConfigurationForUserTest.php
```

**P10. `App\Service\Ai\Crypto\ApiKeyCipher`** (delete the entry). Reached through: `AiProviderConfigurator.php:38`, `AiConfigurationFactory.php:18`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Ai\Crypto\ApiKeyCipher:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Command/RecommendationDrainCommandTest.php tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/Api/RecommendationRunHistoryControllerTest.php tests/Controller/Api/RecommendationSettingsControllerTest.php tests/Repository/RecommendationItemRepositoryTest.php tests/Repository/RecommendationRunHistoryRepositoryTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Repository/RecommendationRunTimingRepositoryTest.php tests/Service/Account/AccountDeleterTest.php tests/Service/Recommendation/Feed/ForYouFeedTest.php tests/Service/Recommendation/Run/DueRecommendationRunFinderTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php tests/Service/Recommendation/Run/RecommendationEtaEstimatorTest.php tests/Service/Recommendation/Run/RecommendationPipelineTest.php tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Recommendation/Run/RecommendationRunPurgerTest.php tests/Service/Recommendation/Run/RecommendationRunStarterTest.php tests/Service/Recommendation/Run/WaveContextLoaderTest.php tests/Service/Recommendation/Settings/RecommendationSettingsResolverTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Service/Worker/StartDueRecommendationRunsHandlerTest.php tests/Service/Worker/WorkerRunSweepTest.php
```

**P11. `App\Service\Recommendation\Settings\RecommendationSettingsResolver`** (delete the entry). Reached through: `RecommendationSettingsController.php:22` and three services.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Settings\RecommendationSettingsResolver:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Recommendation/Feed/ForYouFeedTest.php tests/Service/Recommendation/Settings/RecommendationSettingsResolverTest.php tests/Service/Recommendation/Settings/RecommendationSettingsRoundTripTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/WaveContextLoaderTest.php
```

**P12. `App\Service\Recommendation\Prompt\RecommendationHistoryLoader`** (delete the entry). Reached through: `SnapshotPhase.php:22` and three more.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Prompt\RecommendationHistoryLoader:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php tests/Service/Recommendation/Run/WaveContextLoaderTest.php
```

**P13. `App\Service\Recommendation\Prompt\RecommendationCandidateLoader`** (delete the entry). Reached through: `SnapshotPhase.php:21` and two more.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Prompt\RecommendationCandidateLoader:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Recommendation/Prompt/RecommendationCandidateLoaderTest.php tests/Service/Recommendation/Run/WaveContextLoaderTest.php
```

**P14. `App\Service\Fetch\FeedFetcher\FeedFetcherInterface`** (delete the entry). Reached through: same target as `services.yaml:74`; `CommentsLoader.php:23` and four more.

Before (the entry and the blank line after it):

```yaml
    App\Service\Fetch\FeedFetcher\FeedFetcherInterface:
        alias: App\Service\Fetch\FeedFetcher\HttpFeedFetcher
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/EntryCommentsControllerTest.php tests/Controller/Api/FeedPreviewControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php tests/Service/Comments/CommentsLoaderTest.php
```

**P15. `App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface`** (delete the entry). Reached through: same target as `services.yaml:75`; `RefreshRunner.php:36` and three more.

Before (the entry and the blank line after it):

```yaml
    # HttpFeedFetcher sends through this too, so replacing it stubs discovery, preview and favicon fetches at once.
    App\Service\Fetch\BatchFeedFetcher\BatchFeedFetcherInterface:
        alias: App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Command/RefreshFeedsCommandTest.php tests/Controller/Api/RefreshControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/MaintenanceControllerTest.php tests/Service/Worker/RefreshDueFeedsHandlerTest.php
```

**P16. `App\Service\Reader\ArticleExtractor\ArticleExtractorInterface`** (delete the entry). Reached through: same target as `services.yaml:80`; `EntryReaderController.php:30`, `ReaderAuditRunner.php:24`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Reader\ArticleExtractor\ArticleExtractorInterface:
        alias: App\Service\Reader\ArticleExtractor\ArticleExtractor
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Command/ReaderAuditCommandTest.php tests/Controller/Api/EntryReaderControllerTest.php tests/Service/Reader/ReaderBodyCleanerWiringTest.php
```

**P17. `App\Service\Mail\AccountMailer\AccountMailerInterface`** (delete the entry). Reached through: same target as `services.yaml:174`; `RegistrationService.php:28` and three more.

Before (the entry and the blank line after it):

```yaml
    # services.yaml's target: #[AsDecorator] turns AccountMailer's id into MailGatedAccountMailer.
    App\Service\Mail\AccountMailer\AccountMailerInterface:
        alias: App\Service\Mail\AccountMailer\AccountMailer
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Admin/UserStatusChangerTest.php tests/Service/Auth/RegistrationServiceTest.php tests/Service/Mail/AccountMailer/MailGatedAccountMailerWiringTest.php
```

**P18. `Symfony\Component\Lock\LockFactory`** (delete the entry). Reached through: FrameworkBundle's own private alias (`LockFactory` → `lock.factory` → `lock.default.factory`); `RecommendationDrainCommand.php:52` and two more.

Before (the entry and the blank line after it):

```yaml
    # Tests acquire the refresh lock up front to assert the "busy" path.
    Symfony\Component\Lock\LockFactory:
        alias: lock.default.factory
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Command/RecommendationDrainCommandTest.php tests/Command/RefreshFeedsCommandTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/MaintenanceControllerTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
```

**P19. `test.cache.rate_limiter`** (drop `public: true`). Reached through: a private alias of `cache.rate_limiter`, which every limiter in `rate_limiter.yaml` uses.

Before:

```yaml
    # login_throttling's FILESYSTEM pool outlives the kernel reboot and the test run; tests clear it between cases,
    # or the per-IP limiter fills up and every login answers 429.
    test.cache.rate_limiter:
        alias: cache.rate_limiter
        public: true
```

After:

```yaml
    # login_throttling's FILESYSTEM pool outlives the kernel reboot and the test run; tests clear it between cases,
    # or the per-IP limiter fills up and every login answers 429.
    test.cache.rate_limiter:
        alias: cache.rate_limiter
```

Run:

```bash
php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php tests/Controller/Api/AuthJourneyTest.php tests/Controller/Api/ClientErrorControllerTest.php tests/Controller/Api/EntryCommentsControllerTest.php tests/Controller/Api/EntryReaderControllerTest.php tests/Controller/Api/FeedPreviewControllerTest.php tests/Controller/Api/JwtAccessTest.php tests/Controller/Api/LoginTest.php tests/Controller/Api/MeDigestTestControllerTest.php tests/Controller/Api/MeResendVerificationTest.php tests/Controller/Api/OAuthFlowTest.php tests/Controller/Api/PasskeyLoginOptionsTest.php tests/Controller/Api/PasskeyLoginTest.php tests/Controller/Api/PasswordResetTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/Api/RefreshControllerTest.php tests/Controller/Api/RegistrationTest.php tests/Controller/Api/SetupControllerTest.php tests/EventListener/DeferredMailFlushListenerTest.php
```

**P20. `Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface`** (delete the entry). Reached through: SecurityBundle's own private alias of `security.user_password_hasher`; `BootstrapAdminProvisioner.php:22` and three more (five tests already `get('security.user_password_hasher')` privately).

Before (the entry and the blank line after it):

```yaml
    Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface:
        alias: security.user_password_hasher
        public: true

```

After: the lines are gone.

Run: `php bin/phpunit` (the whole suite: `tests/Support/ApiTestCase.php` and `tests/Support/SeedsUsers.php` fetch it for nearly every functional test).

**P21. `App\Service\Auth\ActionTokenService`** (delete the entry). Reached through: `EmailVerifier.php:18`, `RegistrationService.php:27`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Auth\ActionTokenService:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/PasswordResetTest.php tests/Controller/Api/RegistrationTest.php tests/Service/Auth/ActionTokenServiceTest.php tests/Service/Auth/EmailVerifierTest.php tests/Service/Auth/RegistrationServiceTest.php
```

**P22. `App\Service\Auth\AltchaService`** (delete the entry). Reached through: `AuthController.php:34`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Auth\AltchaService:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/AuthJourneyTest.php tests/Controller/Api/PasswordResetTest.php tests/Controller/Api/RegistrationTest.php tests/EventListener/DeferredMailFlushListenerTest.php
```

**P23. `App\Service\Auth\RegistrationService`** (delete the entry). Reached through: `AuthController.php:31`, `MeController.php:32`; no test fetches it.

Before (the entry and the blank line after it):

```yaml
    App\Service\Auth\RegistrationService:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/RegistrationTest.php tests/Controller/Api/MeResendVerificationTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P24. `App\Security\PasswordWorkEqualizer`** (delete the entry). Reached through: through `PasswordWorkEqualizerInterface` (`services.yaml:72`): `LoginTimingEqualizer.php:20`, `RegistrationService.php:29`.

Before (the entry and the blank line after it):

```yaml
    App\Security\PasswordWorkEqualizer:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/LoginTest.php tests/Service/Auth/RegistrationServiceTest.php
```

**P25. `App\Service\Mail\DeferredMailer`** (delete the entry). Reached through: `DeferredMailFlushListener.php:28` and `AccountMailer`'s `$mailer` (`services.yaml:192`).

Before (the entry and the blank line after it):

```yaml
    App\Service\Mail\DeferredMailer:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/EventListener/DeferredMailFlushHealthTest.php tests/EventListener/DeferredMailFlushListenerTest.php tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
```

**P26. `test.cache.altcha_replay`** (delete the entry: Step 2 shows no file names the id).

Before (the entry and the blank line after it):

```yaml
    test.cache.altcha_replay:
        alias: altcha.replay.cache
        public: true

```

After: the lines are gone.

Run: nothing (no test can fetch an id no file names).

**P27. `App\Tests\Support\QueryRecorder`** (drop `public: true`). Reached through: DoctrineBundle's `MiddlewaresPass` wires the per-connection clone `QueryRecorder.default` (the id tests fetch, `QueryRecorder::SERVICE_ID`) into `doctrine.dbal.default_connection.configuration`.

Before:

```yaml
    # tests/ is outside services.yaml's App\ resource, so the tag is explicit. At the default priority the recorder
    # wraps dama's StaticDriver (priority 100) from outside: it records the app's statements, not dama's savepoints.
    App\Tests\Support\QueryRecorder:
        public: true
        tags:
            - { name: doctrine.middleware }
```

After:

```yaml
    # tests/ is outside services.yaml's App\ resource, so the tag is explicit. At the default priority the recorder
    # wraps dama's StaticDriver (priority 100) from outside: it records the app's statements, not dama's savepoints.
    App\Tests\Support\QueryRecorder:
        tags:
            - { name: doctrine.middleware }
```

Run:

```bash
php bin/phpunit tests/Controller/Admin/AdminUserControllerTest.php tests/Controller/Api/SubscriptionBulkTest.php tests/Repository/CategoryRepositoryTest.php tests/Repository/EntryCategoryLoaderTest.php tests/Repository/EntryListTest.php tests/Repository/EntryRowsByIdsTest.php tests/Repository/RowIdsTest.php tests/Repository/SubscriptionCountsByUserIdTest.php tests/Service/Ingest/EntryCategoryWriterTest.php tests/Service/Reading/EntryStateResolverTest.php tests/Service/Recommendation/Prompt/RecommendationCandidateLoaderTest.php tests/Service/Settings/InstanceSettingsTest.php tests/Service/Subscription/BulkSubscriberTest.php tests/Service/Subscription/OwnedTagsCacheTest.php tests/Service/Subscription/UnsubscribeAllTest.php
```

**P28. `test.cache.oauth_state`** (delete the entry: Step 2 shows no file names the id).

Before (the entry and the blank line after it):

```yaml
    test.cache.oauth_state:
        alias: oauth.state.cache
        public: true

```

After: the lines are gone.

Run: nothing (no test can fetch an id no file names).

**P29. `App\Service\OAuth\OAuthStateStore`** (delete the entry). Reached through: `OAuthController.php:46`, `OAuthCallback.php:21`.

Before (the entry and the blank line after it):

```yaml
    App\Service\OAuth\OAuthStateStore:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/OAuth/OAuthCallbackTest.php tests/Controller/Api/OAuthFlowTest.php
```

**P30. `test.cache.oauth_login_code`** (delete the entry: Step 2 shows no file names the id).

Before (the entry and the blank line after it):

```yaml
    test.cache.oauth_login_code:
        alias: oauth.login_code.cache
        public: true

```

After: the lines are gone.

Run: nothing (no test can fetch an id no file names).

**P31. `App\Service\OAuth\LoginCodeStore`** (delete the entry). Reached through: `OAuthSignIn.php:26`; no test fetches it.

Before (the entry and the blank line after it):

```yaml
    App\Service\OAuth\LoginCodeStore:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/OAuthFlowTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P32. `App\Service\OAuth\OAuthAccountLinker`** (delete the entry). Reached through: `OAuthSignIn.php:25`; no test fetches it.

Before (the entry and the blank line after it):

```yaml
    App\Service\OAuth\OAuthAccountLinker:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth/OAuthAccountLinkerTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P33. `App\Service\Scraper\HtmlItemExtractor`** (delete the entry). Reached through: `ScrapedBodyParser.php:18`, `FeedDiscovery.php:43`, `FeedPreviewService.php:43`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Scraper\HtmlItemExtractor:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Discovery/FeedDiscovery/FeedDiscoveryTest.php tests/Service/Discovery/SubstackProfileDiscoveryTest.php tests/Service/Preview/FeedPreviewServiceTest.php tests/Service/Scraper/HtmlItemExtractorWiringTest.php tests/Controller/MaintenanceControllerTest.php tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Refresh/RefreshRunner/RefreshRunnerConcurrentFetchTest.php tests/Service/Refresh/RefreshRunner/RefreshRunnerOrphanSweepTest.php tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php
```

**P34. `App\Service\Refresh\FeedBodyParser`** (delete the entry). Reached through: `FeedOutcomePersister.php:36` ← `RefreshRunner`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Refresh\FeedBodyParser:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/DependencyInjection/EveryApplicationServiceBuildsTest.php tests/Service/Refresh/FeedBodyParserWiringTest.php tests/Service/Refresh/FeedOutcomePersisterTest.php
```

**P35. `App\Service\OAuth\OAuthProviderRegistry`** (delete the entry). Reached through: `OAuthController.php:45`, `OAuthCallback.php:22`.

Before (the entry and the blank line after it):

```yaml
    App\Service\OAuth\OAuthProviderRegistry:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth/OAuthProviderWiringTest.php
```

**P36. `App\Service\Catalog\CatalogDocument`** (delete the entry). Reached through: `AdminCatalogImportController.php:23` and two more.

Before (the entry and the blank line after it):

```yaml
    App\Service\Catalog\CatalogDocument:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Catalog/BundledCatalogTest.php tests/Service/Catalog/CatalogImporterTest.php
```

**P37. `App\Service\Catalog\CatalogImporter`** (delete the entry). Reached through: `AdminCatalogImportController.php:22`, `ImportCatalogCommand.php:29`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Catalog\CatalogImporter:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Catalog/CatalogImporterTest.php
```

**P38. `App\Service\Catalog\CatalogSubscriber`** (delete the entry). Reached through: `OnboardingController.php:20`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Catalog\CatalogSubscriber:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Catalog/CatalogSubscriberTest.php
```

**P39. `App\Service\Auth\BootstrapAdminProvisioner`** (delete the entry). Reached through: `CreateAdminCommand.php:30`, `WebAdminSetup.php:21`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Auth\BootstrapAdminProvisioner:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Auth/BootstrapAdminProvisionerTest.php tests/Service/Auth/WebAdminSetupTest.php
```

**P40. `App\Service\Mail\MailCapability`** (delete the entry). Reached through: `MeProfileJson.php:15` ← `MeController.php:37`, and five more.

Before (the entry and the blank line after it):

```yaml
    App\Service\Mail\MailCapability:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Http/MeProfileJsonTest.php tests/Service/Mail/MailCapabilityWiringTest.php tests/Service/Maintenance/MaintenanceTickTest.php
```

**P41. `App\Service\Settings\InstanceSettings`** (delete the entry). Reached through: `AdminSettingsController.php:20` and five more; services.yaml autoconfigures it as this entry does, so `kernel.reset` stays.

Before (the entry and the blank line after it):

```yaml
    App\Service\Settings\InstanceSettings:
        autowire: true
        autoconfigure: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php tests/Controller/Api/RegistrationTest.php tests/Controller/Api/SetupControllerTest.php tests/DependencyInjection/ResettableServicesAreTaggedTest.php tests/Service/Auth/RegistrationPolicyTest.php tests/Service/Passkey/PasskeySignInAvailabilityTest.php tests/Service/Settings/InstanceSettingsTest.php tests/Service/Settings/PasskeyRelyingParty/ConfiguredPasskeyRelyingPartyTest.php tests/Service/Settings/SettingsResetBetweenMessagesTest.php tests/Controller/Api/PasskeyListTest.php tests/Controller/Api/PasskeyRegistrationTest.php tests/Controller/Api/PasskeyLoginOptionsTest.php tests/Service/Passkey/AssertionVerifierTest.php tests/Service/Passkey/AttestationVerifierTest.php tests/Service/Auth/EmailVerifierTest.php tests/Service/Auth/Factory/SignupUserFactoryTest.php tests/Service/Auth/RegistrationServiceTest.php tests/Service/OAuth/Factory/OAuthUserFactoryTest.php tests/Service/OAuth/OAuthAccountLinkerTest.php
```

**P42. `App\Tests\Support\StubChatClient`** (drop `public: true`). Reached through: the test alias `ChatCompletionClientInterface` → `StubChatClient`, which `RateLimitedCompletion.php:26` takes.

Before:

```yaml
    App\Tests\Support\StubChatClient:
        autowire: true
        public: true
```

After:

```yaml
    App\Tests\Support\StubChatClient:
        autowire: true
```

Run:

```bash
php bin/phpunit tests/Command/RecommendationDrainCommandTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php tests/Service/Recommendation/Run/RecommendationConsolidationResolverTest.php tests/Service/Recommendation/Run/RecommendationPipelineTest.php tests/Service/Recommendation/Run/RecommendationProfileDistillerTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Service/Worker/WorkerRunSweepTest.php
```

**P43. `App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface`** (drop `public: true`). Reached through: `RateLimitedCompletion.php:26`; no test fetches the alias.

Before:

```yaml
    # Every test talks to StubChatClient, never to a real provider.
    App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface:
        alias: App\Tests\Support\StubChatClient
        public: true
```

After:

```yaml
    # Every test talks to StubChatClient, never to a real provider.
    App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface:
        alias: App\Tests\Support\StubChatClient
```

Run:

```bash
php bin/phpunit tests/Service/Recommendation/Run/RecommendationPipelineTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P44. `App\Service\Recommendation\Run\RecommendationRunStarter`** (delete the entry). Reached through: `RecommendationRunController.php:29`, `ForYouSweep.php:24`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\RecommendationRunStarter:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Command/RecommendationDrainCommandTest.php tests/Service/Recommendation/Run/DueRecommendationRunFinderTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php tests/Service/Recommendation/Run/RecommendationPipelineTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Recommendation/Run/RecommendationRunStarterTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Service/Worker/WorkerRunSweepTest.php
```

**P45. `App\Service\Recommendation\Run\RecommendationRunAdvancer`** (delete the entry). Reached through: `RecommendationPollDriver.php:22`, `ForYouSweep.php:25`, `WorkerRunSweep.php:29`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\RecommendationRunAdvancer:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Command/RecommendationDrainCommandTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php tests/Service/Recommendation/Run/RecommendationPipelineTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Service/Worker/WorkerRunSweepTest.php
```

**P46. `App\Service\Recommendation\Run\TickPhases`** (delete the entry). Reached through: `RecommendationRunAdvancer.php:49`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\TickPhases:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php
```

**P47. `App\Service\Recommendation\Run\RecommendationPollDriver`** (delete the entry). Reached through: `RecommendationRunController.php:30`; no test fetches it.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\RecommendationPollDriver:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/RecommendationRunControllerTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P48. `App\Service\Recommendation\Run\DueRecommendationRunFinder`** (delete the entry). Reached through: `ForYouSweep.php:23`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\DueRecommendationRunFinder:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Recommendation/Run/DueRecommendationRunFinderTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php
```

**P49. `App\Service\Recommendation\Run\ForYouSweep`** (delete the entry). Reached through: `MaintenanceController.php:27` and two more.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\ForYouSweep:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php
```

**P50. `App\Service\Worker\WorkerSchedule`** (delete the entry). Reached through: no constructor; once this entry goes, services.yaml autoconfigures its `#[AsSchedule('worker')]` (`WorkerSchedule.php:20`) and the scheduler holds it, as in prod.

Before (the entry and the blank line after it):

```yaml
    App\Service\Worker\WorkerSchedule:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Worker/WorkerScheduleWiringTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P51. `App\Service\Worker\Handler\AdvanceRecommendationRunsHandler`** (delete the entry). Reached through: no constructor; once this entry goes, services.yaml autoconfigures its `#[AsMessageHandler]` (`AdvanceRecommendationRunsHandler.php:16`) and the bus holds it, as in prod.

Before (the entry and the blank line after it):

```yaml
    App\Service\Worker\Handler\AdvanceRecommendationRunsHandler:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P52. `App\Service\Worker\Handler\RefreshDueFeedsHandler`** (delete the entry). Reached through: as above, `#[AsMessageHandler]` at `RefreshDueFeedsHandler.php:14`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Worker\Handler\RefreshDueFeedsHandler:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Worker/RefreshDueFeedsHandlerTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P53. `App\Service\Worker\Handler\PurgeFailedMessagesHandler`** (delete the entry). Reached through: as above, `#[AsMessageHandler]` at `PurgeFailedMessagesHandler.php:13`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Worker\Handler\PurgeFailedMessagesHandler:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Worker/PurgeFailedMessagesHandlerTest.php tests/DependencyInjection/EveryApplicationServiceBuildsTest.php
```

**P54. `App\Service\Process\DetachedProcessLauncher\DetachedProcessLauncherInterface`** (delete the entry). Reached through: same target as `services.yaml:162`; `RecommendationDrainSpawner.php:20` ← `RecommendationDrainOnTerminateListener.php:24`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Process\DetachedProcessLauncher\DetachedProcessLauncherInterface:
        alias: App\Service\Process\DetachedProcessLauncher\DetachedProcessLauncher
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/RecommendationRunControllerTest.php tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
```

**P55. `App\Service\Recommendation\Run\TickLockKeepalive`** (delete the entry). Reached through: `RecommendationRunAdvancer.php:48`, `RecommendationTickCheckpoint.php:22`; services.yaml autoconfigures it as this entry does.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\TickLockKeepalive:
        autowire: true
        autoconfigure: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Ai/Completion/CompletionStreamHeartbeat/CompletionStreamHeartbeatWiringTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/DependencyInjection/ResettableServicesAreTaggedTest.php
```

**P56. `App\Service\Recommendation\Run\SweepStreamHeartbeat`** (delete the entry). Reached through: `ForYouSweep.php:28`, `WorkerRunSweep.php:31`; services.yaml autoconfigures it as this entry does.

Before (the entry and the blank line after it):

```yaml
    App\Service\Recommendation\Run\SweepStreamHeartbeat:
        autowire: true
        autoconfigure: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Ai/Completion/CompletionStreamHeartbeat/CompletionStreamHeartbeatWiringTest.php tests/Service/Recommendation/Run/ForYouSweepTest.php tests/DependencyInjection/ResettableServicesAreTaggedTest.php
```

**P57. `App\Repository\EntryListRepository`** (delete the entry). Reached through: `EntryController.php:42` and seven more.

Before (the entry and the blank line after it):

```yaml
    App\Repository\EntryListRepository:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Repository/DuplicateCollapseTest.php tests/Repository/EntryListTest.php tests/Repository/EntryRowsByIdsTest.php tests/Repository/EntrySearchTest.php tests/Repository/UnreadMatchingEntryIdsForUserTest.php tests/Service/Mail/Digest/DigestComposerTest.php tests/Service/Mail/Digest/DigestEntryFinderTest.php tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Service/Mail/Digest/SendTestDigestTest.php tests/Service/Reading/EntryStateResolverTest.php tests/Service/Reading/EntryStateUpdaterTest.php tests/Service/Search/EntrySearch/EntrySearchWithFallbackTest.php tests/Service/Search/EntrySearch/IndexedEntrySearchTest.php tests/Service/Search/EntrySearch/LikeEntrySearchTest.php tests/Service/Worker/SendDueDigestsHandlerTest.php
```

**P58. `App\Service\Backup\AccountBackupExporter`** (delete the entry). Reached through: `AccountBackupController.php:25`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Backup\AccountBackupExporter:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/AccountBackupControllerTest.php tests/Service/Backup/AccountBackupExporterTest.php tests/Service/Backup/AccountRestorerTest.php tests/Service/Backup/EntryMediaBackupRoundTripTest.php tests/Service/Backup/Support/BackupSchemaCoverageTest.php
```

**P59. `App\Service\Account\AccountReset`** (delete the entry). Reached through: `AccountRestorer.php:25` ← `AccountBackupController.php:28`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Account\AccountReset:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Account/AccountResetTest.php
```

**P60. `App\Service\Backup\BackupFitCheck`** (delete the entry). Reached through: `AccountRestorer.php:24`, `RestorePreviewer.php:22`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Backup\BackupFitCheck:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Backup/RestorePreviewerTest.php
```

**P61. `App\Service\Backup\RestorePreviewer`** (delete the entry). Reached through: `AccountBackupController.php:27`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Backup\RestorePreviewer:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Backup/RestorePreviewerTest.php
```

**P62. `App\Repository\EntryBatchInserter`** (delete the entry). Reached through: `RestoreEntryLoaderFactory.php:28` ← `EntryPartRestorer.php:22`.

Before (the entry and the blank line after it):

```yaml
    App\Repository\EntryBatchInserter:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Repository/EntryBatchInserterTest.php tests/Service/Backup/EntryPartRestorerTest.php
```

**P63. `App\Service\Backup\AccountRestorer`** (delete the entry). Reached through: `AccountBackupController.php:28`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Backup\AccountRestorer:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Backup/AccountRestorerTest.php tests/Service/Backup/GoldenBackupRestoreTest.php
```

**P64. `App\Service\Backup\EntryPartRestorer`** (delete the entry). Reached through: `AccountBackupController.php:29`.

Before (the entry and the blank line after it):

```yaml
    App\Service\Backup\EntryPartRestorer:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Service/Backup/AccountRestorerTest.php tests/Service/Backup/GoldenBackupRestoreTest.php
```

**P65. `App\Service\Passkey\PasskeyChallengeStore`** (delete the entry). Reached through: `AttestationVerifier.php:33` and three more.

Before (the entry and the blank line after it):

```yaml
    App\Service\Passkey\PasskeyChallengeStore:
        autowire: true
        public: true

```

After: the lines are gone.

Run:

```bash
php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php tests/Controller/Api/PasskeyRegistrationTest.php tests/Service/Passkey/AssertionVerifierTest.php tests/Service/Passkey/AttestationVerifierTest.php
```

**P66. `test.cache.passkey_challenge`** (drop `public: true`). Reached through: a private alias of `passkey.challenge.cache`, bound to `$passkeyChallengeCache` (`services.yaml:37`).

Before:

```yaml
    test.cache.passkey_challenge:
        alias: passkey.challenge.cache
        public: true
```

After:

```yaml
    test.cache.passkey_challenge:
        alias: passkey.challenge.cache
```

Run:

```bash
php bin/phpunit tests/Controller/Api/PasskeyLoginTest.php tests/Controller/Api/PasskeyRegistrationTest.php
```

**P67. `test.cache.refresh_run`** (drop `public: true`). Reached through: a private alias of `refresh.run.cache`, bound to `$refreshRunCache` (`services.yaml:38`).

Before:

```yaml
    # A FILESYSTEM pool that outlives the test run while ids repeat after tests/bootstrap.php rebuilds the database;
    # RefreshControllerTest clears it, or a stale run could be resumed by a same-id user.
    test.cache.refresh_run:
        alias: refresh.run.cache
        public: true
```

After:

```yaml
    # A FILESYSTEM pool that outlives the test run while ids repeat after tests/bootstrap.php rebuilds the database;
    # RefreshControllerTest clears it, or a stale run could be resumed by a same-id user.
    test.cache.refresh_run:
        alias: refresh.run.cache
```

Run:

```bash
php bin/phpunit tests/Controller/Api/RefreshControllerTest.php
```

**P68. `App\Repository\SavedSearchMembershipLoader`** (delete the entry). Reached through: `EntryListRowEnricher.php:12` ← `EntryController`.

Before (the blank line before the entry, and the entry, the file's last lines):

```yaml

    App\Repository\SavedSearchMembershipLoader:
        autowire: true
        public: true
```

After: the lines are gone, and the file ends with the line that preceded them.

Run:

```bash
php bin/phpunit tests/Repository/SavedSearchMembershipLoaderTest.php
```

- [ ] **Step 5: The test files that describe the old wiring**

(a) Only if P14 was recorded `removed`: `backend/tests/Controller/Api/FeedPreviewControllerTest.php`, Before:

```php
    /**
     * FeedPreviewService is final and privately wired, so the test swaps its one I/O dependency, FeedFetcherInterface
     * (public in services_test.yaml for this), and lets the real service and parser run.
     */
```

After:

```php
    /**
     * FeedPreviewService is final and privately wired, so the test swaps its one I/O dependency,
     * FeedFetcherInterface, and lets the real service and parser run.
     */
```

(b) Only if P21 was recorded `removed`: `backend/tests/Service/Auth/ActionTokenServiceTest.php`, Before:

```php
        );
    }

    /**
     * Functional tests fetch this service from the test container. Redefining it in services_test.yaml replaces the
     * autowired definition, so a missing `autowire: true` would surface there as a baffling error.
     */
    public function testTheServiceIsFetchableFromTheTestContainer(): void
    {
        self::assertInstanceOf(
            ActionTokenService::class,
            self::getContainer()->get(ActionTokenService::class),
        );
    }
}
```

After:

```php
        );
    }
}
```

(c) Always: `backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php`, Before:

```php
/**
 * Drives the real kernel with the container's RecordingProcessLauncher (services_test.yaml): handle() never launches,
 * terminate() may, and a console exit never does.
 */
```

After:

```php
/**
 * Drives the real kernel with a RecordingProcessLauncher in the launcher's place: handle() never launches, terminate()
 * may, and a console exit never does.
 */
```

Run:

```bash
php -l tests/Controller/Api/FeedPreviewControllerTest.php
php -l tests/Service/Auth/ActionTokenServiceTest.php
php -l tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
vendor/bin/phpcs tests/Controller/Api/FeedPreviewControllerTest.php tests/Service/Auth/ActionTokenServiceTest.php tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
php bin/phpunit tests/Controller/Api/FeedPreviewControllerTest.php tests/Service/Auth/ActionTokenServiceTest.php tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
grep -rn -E "services_test" tests
git grep -c -E "services_test" -- ../docs/superpowers/plans/2026-09-27-1163-reader-module-split.md
```

Expected: three `No syntax errors detected`, PHPCS silent, and PHPUnit PASS. The first grep prints nothing when (a) and (b) both applied. Otherwise it prints only the line of the one that did not: `FeedPreviewControllerTest.php:56` or `ActionTokenServiceTest.php:161`. The second grep is its positive control and prints `../docs/superpowers/plans/2026-09-27-1163-reader-module-split.md:3`.

- [ ] **Step 6: The whole native suite**

```bash
php bin/phpunit
```

Expected: PASS. This run covers what the per-entry lists cannot: helper traits, `EveryApplicationServiceBuildsTest` and `ResettableServicesAreTaggedTest` over the final wiring. A failure naming an id this task removed goes by the procedure's rule 4 or 5: restore that Pnn, record it, and run again.

- [ ] **Step 7: The file matches the prediction, or the report says where not**

If every Pnn was recorded as planned (P01 kept, P02–P68 removed or dropped), `backend/config/services_test.yaml` now reads exactly:

```yaml
services:
    # Test doubles and test-only ids. The test container already reaches every private service the app depends on, so
    # an entry is `public: true` only when nothing in the app does. Naming a class here replaces its services.yaml
    # definition; this file has no `_defaults`, so a class with constructor arguments needs `autowire: true`.

    # login_throttling's FILESYSTEM pool outlives the kernel reboot and the test run; tests clear it between cases,
    # or the per-IP limiter fills up and every login answers 429.
    test.cache.rate_limiter:
        alias: cache.rate_limiter

    # tests/ is outside services.yaml's App\ resource, so the tag is explicit. At the default priority the recorder
    # wraps dama's StaticDriver (priority 100) from outside: it records the app's statements, not dama's savepoints.
    App\Tests\Support\QueryRecorder:
        tags:
            - { name: doctrine.middleware }

    App\Tests\Support\StubChatClient:
        autowire: true

    # Every test talks to StubChatClient, never to a real provider.
    App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface:
        alias: App\Tests\Support\StubChatClient

    # No container-wired code path may exec() a real drainer process against the test database.
    App\Tests\Support\NullShellCommandRunner:
        autowire: true
    App\Service\Process\ShellCommandRunner\ShellCommandRunnerInterface:
        alias: App\Tests\Support\NullShellCommandRunner

    # Public: nothing here takes it, as StubChatClient replaces its one consumer, OpenAiCompatibleChatClient.
    App\Service\Ai\Completion\CompletionStreamHeartbeat\CompletionStreamHeartbeatInterface:
        alias: App\Service\Ai\Completion\CompletionStreamHeartbeat\CompositeCompletionStreamHeartbeat
        public: true

    test.cache.passkey_challenge:
        alias: passkey.challenge.cache

    # A FILESYSTEM pool that outlives the test run while ids repeat after tests/bootstrap.php rebuilds the database;
    # RefreshControllerTest clears it, or a stale run could be resumed by a same-id user.
    test.cache.refresh_run:
        alias: refresh.run.cache
```

Check it:

```bash
grep -c -E '^        public: true$' config/services_test.yaml
grep -c -E '^    [^ #].*:$' config/services_test.yaml
```

Expected with every prediction held: `1`, then `9`. The second count is the positive control that the entry pattern matches: nine entry lines, from `test.cache.rate_limiter:` to `test.cache.refresh_run:`. A restored Pnn brings its Before back, `public: true` included, and moves the counts with it. The report states both numbers and the restored Pnn behind any difference, and `git diff` of the file against this listing shows exactly those Befores.

- [ ] **Step 8: The gate, then commit**

```bash
composer comments:config; echo "exit=$?"
```

Expected: `exit=0`.

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git add backend/config/services_test.yaml backend/tests/Controller/Api/FeedPreviewControllerTest.php backend/tests/Service/Auth/ActionTokenServiceTest.php backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php
git commit -m "chore(#1270): test services are public only when nothing in the app depends on them"
```

---

### Task 4: Gates and the PR

Per task, already run in the tasks: `php -l` and `vendor/bin/phpcs` on the changed PHP files, and PHPUnit on the touched tests (Task 3 Step 5). Tasks 1 and 2 change no PHP. Task 1 runs its own checks: the gate, its deletion checks, ShellCheck and the workflow parse. Task 2 runs Infection. Frontend is untouched.

- [ ] **Step 1: Per-PR gates (from `backend/`)**

```bash
composer check
composer md
php bin/phpunit
docker compose exec php composer test
composer infection:diff
```

`composer check` is PHPCS, the new comment gate, PHPStan and phptramp. PHPStan needs a warm dev cache first: `php bin/console cache:warmup`. Expected: all green.
- Before the MySQL leg, check that the container reads this checkout: `docker compose exec php grep -c -E '^        public: true$' config/services_test.yaml` prints the same number as Task 3 Step 7's first count on the host.
- `composer infection:diff` should report `0 mutations were generated`, because no `src` file changes. It passes on `--ignore-msi-with-no-mutations`.

Then run `mcp__phpstorm__lint_files` on the changed PHP files: `backend/tests/Controller/Api/FeedPreviewControllerTest.php` (if edited), `backend/tests/Service/Auth/ActionTokenServiceTest.php` (if edited), and `backend/tests/EventListener/RecommendationDrainOnTerminateListenerTest.php`. Block on ERROR or WARNING. Finally, run `shellcheck backend/bin/check-config-comments.sh` from the repository root: silent.

Scan today's dev log for new deprecations: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -r 'select(.level_name == "WARNING" or .level_name == "ERROR") | .message' | sort | uniq -c`. Expected: nothing this branch introduced.

- [ ] **Step 2: Push and open the PR**

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git push -u origin chore/1270-config-cleanups
gh pr create --base develop --title "chore(#1270): config cleanups: CastInt ignores, public test services, a config-comment gate" --body-file backend/var/pr-1270-body.md
```

Write the body to `backend/var/pr-1270-body.md` first (`var/` is git-ignored). It summarises:
1. The four stale `CastInt` ignores removed, the two `COUNT()` ignores kept (with their reason), and Task 2 Step 3's counts.
2. Task 3's ledger: how many entries were removed, how many lost `public: true`, which stayed public, and why. Include P01's quoted failure, and any `behaviour` records.
3. The gate: what it scans, that `composer check` and CI run it, and the three quoted deletion checks.
4. A "Refs #1224" paragraph (planner ruling R-1270-4; no closing keyword anywhere in it, not even in prose): which of the five tag gaps #1224 lists (the three `#[AsMessageHandler]` worker handlers, `WorkerSchedule`, `EntryListRepository`) this PR closes by removing their redefinitions, and which remain. Prove each with `bin/console debug:container --env=test --tag=messenger.message_handler` (and `--tag=doctrine.repository_service`, and the scheduler tag) before and after, quoting the relevant lines. #1224's tag-parity test stays for #1224.
5. The body ends with the line `Closes #1270`.

- [ ] **Step 3: Merge when CI is green, then report**

Before merging, check that `gh pr view <PR> --json closingIssuesReferences` lists #1270 and ONLY #1270 (if #1224 appears, reword the Refs paragraph); if #1270 is missing, re-save the body with `gh pr edit <PR> --body-file backend/var/pr-1270-body.md` and check again. Watch CI with a Monitor (`gh pr checks <PR> --watch --fail-fast`), then `gh pr merge <PR> --merge` (never `--auto`). Verify #1270 is CLOSED (COMPLETED) and #1224 is still OPEN; if GitHub did not close #1270, close it by hand with `--reason completed` and a comment naming the PR and merge SHA. Report the PR URL, merge SHA, the Task 3 ledger, the quoted deletion-check FAILs and the gate results to the planner.
