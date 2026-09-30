# Tag parity between the test and prod containers (#1224) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Base:** origin/develop at **bd4de6c3c** (#1283 merged on top of 2042b59a1). Between the two SHAs only #1262's files changed (`docs/local-docker.md`, `docker-compose.yml`, the rot workflow, its plan); nothing this plan reads or edits.

**Goal:** Close #1224. PR #1275 (#1270) already closed the five tag gaps the issue lists, by deleting the redefinitions that dropped autoconfigure. What remains: (1) prove from the real containers that no `App\` service still differs in its tags between dev/prod and test, and (2) add the wiring test the issue asks for, so a future redefinition in `services_test.yaml` that drops a tag fails the build.

**Architecture:** One new test, `backend/tests/DependencyInjection/TestContainerTagParityTest.php`. It compiles the `prod` and the `test` container from the real `App\Kernel` and config, the way `debug:container` builds a container it has no dump for. It collects each `App\` definition's tag names and asserts that every service defined in both containers carries the same tag names in both. No config change is predicted. Task 1 measures that before anything is committed, and if the measurement disagrees, the executor stops and reports.

**Tech Stack:** Symfony 7.4 DependencyInjection (`ContainerBuilder`, `PassConfig`), FrameworkBundle `MicroKernelTrait`, PHPUnit 12, ParaTest (`composer test:parallel`), jq.

**Branch:** `test/1224-test-tag-parity` off `develop`. It is not `fix/…`: no config change is predicted. If Task 1 finds a gap, the executor stops before any config change (see Task 1 Step 4). One PR, body ends `Closes #1224`.

**Spec:** `gh issue view 1224`; PR #1275's body (`gh pr view 1275 --json body --jq .body`), section "Refs #1224"; CLAUDE.md "PHP code style" and "Testing".

## Decisions

- **D1: The issue's five gaps are closed, and no `App\` service is redefined any more.** PR #1275's body shows the evidence, from `debug:container --env=test --tag=…` before and after. After #1275, `messenger.message_handler` "also holds `AdvanceRecommendationRunsHandler`, `PurgeFailedMessagesHandler` and `RefreshDueFeedsHandler`". `scheduler.schedule_provider` shows `App\Service\Worker\WorkerSchedule  worker`, and `doctrine.repository_service` shows `App\Repository\EntryListRepository`. At bd4de6c3c, `backend/config/services_test.yaml` has nine top-level entries. The three class entries are the test doubles `App\Tests\Support\QueryRecorder` (`:13`), `App\Tests\Support\StubChatClient` (`:17`) and `App\Tests\Support\NullShellCommandRunner` (`:25`). The three `App\Service\…Interface` entries (`:21`, `:27`, `:31`) are `alias:` entries, and the other three are `test.cache.*` aliases. So the prediction is **no remaining gap**, and Task 1 checks it against both real containers. Item 2 of the issue ("decide per entry") therefore has nothing left to decide, unless Task 1 disagrees.
- **D2: No file-wide `_defaults` in `services_test.yaml`.** The issue asked for an explicit evaluation. It would now reach only the three `App\Tests\Support\*` doubles, which do not exist in prod, so it could not change parity. `QueryRecorder`'s one tag is explicit (`:14-15`). The new test, not a `_defaults` block, guards the next redefinition.
- **D3: The test compiles both containers itself, at `debug: false`, with the removing passes off.** The pattern is Symfony's own. `BuildDebugContainerTrait::getContainerBuilder()` (`vendor/symfony/framework-bundle/Command/BuildDebugContainerTrait.php:45-53`) does `$this->initializeBundles(); return $this->buildContainer();`, then `setRemovingPasses([])`, `setAfterRemovingPasses([])`, `compile()`. The test reaches the two protected methods through an anonymous subclass of `App\Kernel`. Symfony supports anonymous kernels explicitly: `MicroKernelTrait.php:171` `$kernelClass = str_contains(static::class, "@anonymous\0") ? parent::class : static::class;` and `Kernel.php:382` for the container class. Rejected options, with evidence:
  - **Boot a second kernel** (`new Kernel('prod', …)->boot()`). Rejected: it dumps the compiled prod container into `var/cache/prod` and runs every bundle's `boot()` inside the test process. FrameworkBundle's `boot()` re-registers the error handler.
  - **Read the XML dumps** (the `ResettableServicesAreTaggedTest` pattern) for both environments. Rejected: a dump exists only for a debug kernel. A debug compile inside the test writes files that parallel runs share: `FrameworkBundle.php:213-219` adds `PhpConfigReferenceDumpPass` (it writes `config/reference.php` with a plain `file_put_contents`, `PhpConfigReferenceDumpPass.php:204-206`) and `ContainerBuilderDebugDumpPass` only `if ($container->getParameter('kernel.debug'))`. Under ParaTest the workers share `var/cache/test` and `config/`, because `WorkerIsolation` isolates only `DATABASE_URL` and `CACHE_DIRECTORY`. Infection's `--threads=max` would also run the test concurrently.
  - **Why `debug: false` is safe under `TEST_TOKEN`/ParaTest.** With debug off, the compile writes no file. `Kernel::buildContainer()` only creates the cache and build directories, and that is race-guarded: `if (!@mkdir($dir, 0o777, true) && !is_dir($dir))`. The compile resolves no environment value, since `%env()%` stays a placeholder until runtime, so `TEST_TOKEN`, `DATABASE_URL` and `CACHE_DIRECTORY` cannot change its result. Task 2 Step 5 proves the "writes no file" claim.
  - **Why both sides at `debug: false`.** Debug changes some tags: `FrameworkExtension.php:1860` adds `validator.attribute_metadata` to constraint-attributed classes only when `!$container->getParameter('kernel.debug')`. Compiling both sides the same way keeps that out of the diff. Production runs at `debug: false`, so the prod side is exactly what ships.
  - **Why removal off.** `RemoveUnusedDefinitionsPass` would delete a private service that nothing uses in one environment. `OpenAiCompatibleChatClient` is an example: in test, `StubChatClient` replaces its only consumer. Such a service would drop out of the comparison without a word. With removal off, `ResolveHotPathPass` and `ResolveNoPreloadPass` (both after-removing, `PassConfig.php`) add no `container.hot_path`/`container.no_preload` noise either.
- **D4: What is compared.** The comparison covers `App\` ids that are **definitions in both** containers. Both directions count: a tag prod has and test lacks, and a tag test has and prod lacks. The test compares tag **names**, not attributes: a lost `autoconfigure` drops whole tags, which is what #1224 is about. Scoping to shared definitions leaves out the test-only `App\Tests\Support\*` doubles and every alias (`StubChatClient`'s `ChatCompletionClientInterface` alias among them). That is why the test needs no allow-list for them.
- **D5: No allow-list constant.** The issue expected "a small, commented allow-list for deliberate differences". Nothing differs deliberately (D1, confirmed by Task 1), and D4's scoping already covers the test-only services. An empty constant would be dead code. See Q1.
- **D6: Task 1 dumps without `--show-hidden`.** With the flag, `debug:container` lists **only** dot-prefixed ids: `JsonDescriptor.php:104` has `if ($showHidden xor '.' === ($serviceId[0] ?? null)) { continue; }`. So a `--show-hidden` dump would contain no `App\` service.
- **D7: Task 1 runs two comparisons.** dev↔test at `APP_DEBUG=1` is the issue's own comparison. prod↔test at `APP_DEBUG=0` is exactly what the new test compares. At debug 0, `debug:container` builds the container itself with removal off (`BuildDebugContainerTrait.php:42-53`), the same as the test.

## Questions for the planner

- **Q1:** Allow-list: "no constant while nothing differs deliberately (planned)" or "an empty, commented `DELIBERATE_DIFFERENCES = []` as the issue worded it".
- **Q2:** Compare "tag names only (planned)" or "tag names and their attributes (priority, bus, schedule name)". Attributes would also catch a redefinition that keeps a tag but changes its attributes. They also compare more fields that are allowed to differ.

## File map

| File | Change |
|---|---|
| `docs/superpowers/plans/2026-09-30-1224-test-tag-parity.md` | Create: this plan |
| `backend/tests/DependencyInjection/TestContainerTagParityTest.php` | Create: the parity test |
| `backend/var/tag-parity/compare.sh` | Create, git-ignored one-off (`backend/.gitignore:8` `/var/`); never committed |

## Global constraints

- Work from `backend/` unless a step says otherwise. Other sessions share the checkout: run `git status --short` before any `switch`, and never `stash`, `reset` or `checkout --`. Restore an edit by applying its Before again.
- Commits are `type(#1224): <what>`, with no attribution or co-author lines.
- Every comment obeys CLAUDE.md: default none, one line, three at most, no narration.
- `TEST_TOKEN` must be unset in the shell for the console runs in Task 1 and for the single-process phpunit runs, unless a step sets it.

---

### Task 0: Preflight, branch, plan copy

**Files:** create `docs/superpowers/plans/2026-09-30-1224-test-tag-parity.md`.

- [ ] **Step 1: Preflight (read-only).**
```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git status --short
git fetch -q origin
git log -1 --format=%h origin/develop
git diff --stat bd4de6c3c origin/develop -- backend/config backend/src/Kernel.php backend/tests/DependencyInjection backend/tests/bootstrap.php backend/tests/Support/WorkerIsolation.php backend/phpunit.dist.xml backend/composer.json backend/composer.lock backend/src/Service/Worker
```
Expected: `git status --short` prints nothing. The diff prints nothing, so every Before below is still verbatim. If the status shows changes, stop and report to the planner; do not stash. If the diff lists files, stop and report the list, and the planner reconciles the plan.

- [ ] **Step 2: Branch.**
```bash
git switch -c test/1224-test-tag-parity origin/develop
```

- [ ] **Step 3: Copy this plan verbatim to `docs/superpowers/plans/2026-09-30-1224-test-tag-parity.md` and commit it.**
```bash
git add docs/superpowers/plans/2026-09-30-1224-test-tag-parity.md
git commit -m "docs(#1224): add plan"
```

---

### Task 1: List every remaining tag gap (diagnostic, no commit)

**Files:** create `backend/var/tag-parity/compare.sh` (git-ignored).

- [ ] **Step 1: Write the one-off.** Create `backend/var/tag-parity/compare.sh` with exactly this content:
```bash
#!/usr/bin/env bash
set -euo pipefail
unset TEST_TOKEN
cd "$(dirname "$0")/../.."
out=var/tag-parity
mkdir -p "$out"

dump() {
  APP_ENV="$2" APP_DEBUG="$3" php bin/console debug:container --format=json > "$out/$1.json" 2> "$out/$1.err"
  jq -S '.definitions
    | with_entries(select((.key | startswith("App\\")) and (.key | startswith("App\\Tests\\") | not)))
    | map_values([(.tags // [])[].name] | unique)' "$out/$1.json" > "$out/$1-tags.json"
}

compare() {
  jq -n --slurpfile reference "$out/$1-tags.json" --slurpfile test "$out/$2-tags.json" '
    $reference[0] as $r | $test[0] as $t
    | {
        shared: ([$r | keys[] | select($t[.] != null)] | length),
        only_in_reference: [$r | keys[] | select($t[.] == null)],
        only_in_test: [$t | keys[] | select($r[.] == null)],
        tag_gaps: [$r | keys[] | select($t[.] != null and $r[.] != $t[.])
          | {id: ., missing_in_test: ($r[.] - $t[.]), extra_in_test: ($t[.] - $r[.])}]
      }'
}

dump dev dev 1
dump test-debug test 1
dump prod prod 0
dump test test 0
compare dev test-debug > "$out/dev-vs-test.json"
compare prod test > "$out/prod-vs-test.json"
```
Four console runs. The dev and test-debug dumps read each environment's debug container dump (all definitions, before removal). The prod and test dumps at `APP_DEBUG=0` build the container with removal off, as the new test does (D7). The runs write the usual `var/cache/<env>` files, and the debug runs may rewrite the git-ignored `config/reference.php`. Neither is tracked.

- [ ] **Step 2: Run it.**
```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
bash var/tag-parity/compare.sh
cat var/tag-parity/dev-vs-test.json var/tag-parity/prod-vs-test.json
```
Expected, for both files:
```json
{
  "shared": <a number of at least 1000>,
  "only_in_reference": [],
  "only_in_test": [],
  "tag_gaps": []
}
```
If the script exits non-zero, read the matching `var/tag-parity/<name>.err`, and fix the cause if it is in the one-off, not in the app. The OTel "no extension" autoload notice on stderr is expected natively.

- [ ] **Step 3: Positive controls: prove the dumps carry the tags that #1275 restored.**
```bash
for f in dev test-debug prod test; do
  jq -c --arg f "$f" '{file: $f,
    refresh: ."App\\Service\\Worker\\Handler\\RefreshDueFeedsHandler",
    purge: ."App\\Service\\Worker\\Handler\\PurgeFailedMessagesHandler",
    schedule: ."App\\Service\\Worker\\WorkerSchedule",
    entryList: (."App\\Repository\\EntryListRepository" | index("doctrine.repository_service") != null)}' \
    var/tag-parity/$f-tags.json
done
```
Expected, four lines, each with `"refresh":["messenger.message_handler"]`, `"purge":["messenger.message_handler"]`, `"schedule":["scheduler.schedule_provider"]` and `"entryList":true`. If a `refresh`, `purge` or `schedule` list shows extra tag names that are the same in all four lines, note them: Task 2's quoted FAILs then show those names too. If any value is `null` or differs between the lines, the jq filter or the dump is wrong. Fix the one-off before you trust Step 2.

- [ ] **Step 4: Branch on the result.**
  - **Predicted: all four lists in both files are empty.** Record both `shared` counts and the Step 3 output for the PR body (`backend/var/pr-1224-body.md`, Task 3). Go on to Task 2. `services_test.yaml` stays untouched.
  - **Anything else** (`tag_gaps` or an `only_in_*` list is not empty): stop. Do not edit config and do not write the test. Report to the planner (SendMessage) with both JSON files and, for every listed id, where it is defined:
    ```bash
    git grep -n -E '<id with each \ written as [\]>' -- config src/Kernel.php
    ```
    Positive control for that pattern style: `git grep -n -E 'App[\]Tests[\]Support[\]QueryRecorder' -- config` prints `config/services_test.yaml:13:`.
    Also report which tests fetch that id (`git grep -n -E '<short class name>' -- tests`). Per the issue, the planner decides for each entry: `autoconfigure: true`, or a deliberate difference.

---

### Task 2: The tag-parity test

**Files:** create `backend/tests/DependencyInjection/TestContainerTagParityTest.php`.

The test guards an invariant that holds already (Task 1), so it passes on its first run. The deletion checks in Steps 3 and 4 prove that it fails in both directions.

- [ ] **Step 1: Write the test.** Create `backend/tests/DependencyInjection/TestContainerTagParityTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use App\Kernel;
use App\Service\Worker\Handler\RefreshDueFeedsHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TestContainerTagParityTest extends TestCase
{
    private const int FEWEST_SHARED_SERVICES = 1000;

    public function testEveryApplicationServiceCarriesItsProductionTagsInTheTestContainer(): void
    {
        $production = self::tagNamesByApplicationService('prod');
        $test = self::tagNamesByApplicationService('test');

        self::assertContains('messenger.message_handler', $production[RefreshDueFeedsHandler::class] ?? []);
        self::assertGreaterThanOrEqual(self::FEWEST_SHARED_SERVICES, \count(array_intersect_key($production, $test)));
        self::assertSame([], self::tagDifferences($production, $test));
    }

    /**
     * @param array<string, list<string>> $production
     * @param array<string, list<string>> $test
     *
     * @return array<string, array{production: list<string>, test: list<string>}>
     */
    private static function tagDifferences(array $production, array $test): array
    {
        $differences = [];
        foreach (array_intersect_key($production, $test) as $id => $productionTags) {
            if ($productionTags !== $test[$id]) {
                $differences[$id] = ['production' => $productionTags, 'test' => $test[$id]];
            }
        }

        return $differences;
    }

    /** @return array<string, list<string>> */
    private static function tagNamesByApplicationService(string $environment): array
    {
        $tagNames = [];
        foreach (self::compiledContainer($environment)->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'App\\')) {
                continue;
            }
            $names = array_map(strval(...), array_keys($definition->getTags()));
            sort($names);
            $tagNames[$id] = $names;
        }

        return $tagNames;
    }

    private static function compiledContainer(string $environment): ContainerBuilder
    {
        // Not debug: a debug compile rewrites the container dump and config/reference.php that parallel runs share.
        $kernel = new class ($environment, debug: false) extends Kernel {
            public function compileWithEveryDefinition(): ContainerBuilder
            {
                $this->initializeBundles();
                $container = $this->buildContainer();
                // No removal, as in debug:container: an unused private service would drop out of one side unseen.
                $container->getCompilerPassConfig()->setRemovingPasses([]);
                $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
                $container->compile();

                return $container;
            }
        };

        return $kernel->compileWithEveryDefinition();
    }
}
```
Notes for the implementer. The two comments are the invariants a future reader would break (D3); keep them and add no others.
- `array_map(strval(...), …)`: `Definition::getTags()` is typed plain `array`, and `array_keys()` of it is `list<int|string>` to PHPStan.
- The positive controls: `RefreshDueFeedsHandler` proves the prod side reads tags, and the `FEWEST_SHARED_SERVICES` floor matches `EveryApplicationServiceBuildsTest::FEWEST_APPLICATION_SERVICES` and proves the comparison is not vacuous. A test side that read no tags fails the `assertSame` anyway.

- [ ] **Step 2: Run it. Expected: PASS.**
```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader/backend
php -l tests/DependencyInjection/TestContainerTagParityTest.php
vendor/bin/phpcs tests/DependencyInjection/TestContainerTagParityTest.php
vendor/bin/phpstan analyse --memory-limit=512M tests/DependencyInjection/TestContainerTagParityTest.php
php bin/phpunit tests/DependencyInjection/TestContainerTagParityTest.php
```
Expected: `No syntax errors detected`, phpcs silent, PHPStan `[OK] No errors`, and PHPUnit `OK (1 test, 3 assertions)`. Record PHPUnit's `Time:` for the PR body: the test compiles two full containers, so a few seconds each is expected. If it takes more than 30 s, say so in the report to the planner; it is not a stop condition.

If PHPStan objects to the anonymous class or to `array_map(strval(...), …)`, fix it in the test with a typed form, never an ignore, and rerun Step 2.

- [ ] **Step 3: Deletion check A: a redefinition that drops `autoconfigure` must fail.** Edit `backend/config/services_test.yaml`.

Before (end of file):
```yaml
    # A FILESYSTEM pool that outlives the test run while ids repeat after tests/bootstrap.php rebuilds the database;
    # RefreshControllerTest clears it, or a stale run could be resumed by a same-id user.
    test.cache.refresh_run:
        alias: refresh.run.cache
```
After (temporary):
```yaml
    # A FILESYSTEM pool that outlives the test run while ids repeat after tests/bootstrap.php rebuilds the database;
    # RefreshControllerTest clears it, or a stale run could be resumed by a same-id user.
    test.cache.refresh_run:
        alias: refresh.run.cache

    App\Service\Worker\Handler\PurgeFailedMessagesHandler:
        autowire: true
```
This is the pre-#1275 shape of #1224's bug.
```bash
php bin/phpunit tests/DependencyInjection/TestContainerTagParityTest.php
```
Expected: FAIL, `Failed asserting that two arrays are identical.`, with a diff whose `+` side holds one entry, about this shape:
```
+    'App\Service\Worker\Handler\PurgeFailedMessagesHandler' => Array &1 [
+        'production' => Array &2 [
+            0 => 'messenger.message_handler',
+        ],
+        'test' => Array &3 [],
+    ],
```
Quote the real output in the PR body. The `production` list is Task 1 Step 3's `purge` list, and `test` is empty. If the test passes instead, the guard is broken: stop and report.

Restore: apply the Before above again (delete the blank line and the two added lines).

- [ ] **Step 4: Deletion check B: a tag the test container adds must fail too.** Same Before as Step 3. After (temporary):
```yaml
    # A FILESYSTEM pool that outlives the test run while ids repeat after tests/bootstrap.php rebuilds the database;
    # RefreshControllerTest clears it, or a stale run could be resumed by a same-id user.
    test.cache.refresh_run:
        alias: refresh.run.cache

    App\Service\Worker\Handler\PurgeFailedMessagesHandler:
        autowire: true
        autoconfigure: true
        tags: ['app.deletion_check']
```
```bash
php bin/phpunit tests/DependencyInjection/TestContainerTagParityTest.php
```
Expected: FAIL, `Failed asserting that two arrays are identical.`, whose `+` side holds `'App\Service\Worker\Handler\PurgeFailedMessagesHandler'` with `'production'` holding `'messenger.message_handler'` and `'test'` holding `'app.deletion_check'` and `'messenger.message_handler'`, in that order. Quote it in the PR body.

Restore: apply the Before again. Then prove that the restore is exact and that the test is green again:
```bash
git diff --stat -- config/services_test.yaml
grep -c -E 'PurgeFailedMessagesHandler|app\.deletion_check' config/services_test.yaml
grep -c -E '^    test\.cache\.refresh_run:$' config/services_test.yaml
php bin/phpunit tests/DependencyInjection/TestContainerTagParityTest.php
```
Expected: the diff prints nothing; the first count prints `0`; the positive control prints `1`; PHPUnit `OK (1 test, 3 assertions)`.

- [ ] **Step 5: Prove that the test writes no file, and that it runs under a worker token and ParaTest.**
```bash
touch var/tag-parity/marker
TEST_TOKEN=tagparity php bin/phpunit tests/DependencyInjection/TestContainerTagParityTest.php
find config var/cache/prod -newer var/tag-parity/marker -type f
find var/cache/test -maxdepth 1 -newer var/tag-parity/marker -type f
touch var/tag-parity/after-marker
find var/tag-parity -newer var/tag-parity/marker -type f
vendor/bin/paratest tests/DependencyInjection
```
The first run above is the second run since the last config edit (Step 4 ran the test after the restore), so the bootstrap finds the test container fresh and recompiles nothing. `var/cache/test` is checked at depth 1 only: the compiled container and its dump live there, while the cache pools (`CACHE_DIRECTORY=%kernel.share_dir%/pools/app`, `backend/.env:108`) live in subdirectories that any test run writes. Expected:
- PHPUnit `OK (1 test, 3 assertions)`.
- The first two `find`s print nothing: no dump, no `config/reference.php`, no compiled container.
- The third `find` prints `var/tag-parity/after-marker`. This is the positive control: it proves that `find -newer` sees a file written after the marker.
- ParaTest: `OK`, with all tests of the directory.

If either of the first two `find`s lists a file, stop and report it, together with the file's name and timestamp: D3's "debug off writes nothing" claim would be wrong.

- [ ] **Step 6: Commit.**
```bash
git add tests/DependencyInjection/TestContainerTagParityTest.php
git status --short
git commit -m "test(#1224): the test container carries every application service's production tags"
```
Expected: before the commit, `git status --short` lists only `A  backend/tests/DependencyInjection/TestContainerTagParityTest.php`. `var/` is ignored.

---

### Task 2b: The extractor seam's docblock names how tests swap it today (planner addition)

**Files:**
- Modify: `backend/src/Service/Reader/ArticleExtractor/ArticleExtractorInterface.php`

#1275 removed the public alias this docblock names; tests now replace the service with the test container's `set()` (`tests/Command/ReaderAuditCommandTest.php:41` `self::getContainer()->set(ArticleExtractorInterface::class, $extractor);`).

- [ ] **Step 1: Edit**

Before:
```php
/** The reader endpoint's seam: tests swap in a fake through the public alias in services_test.yaml. */
```
After:
```php
/** The reader endpoint's seam: tests swap in a fake with the test container's set(). */
```

- [ ] **Step 2: Check and commit (from the repository root)**

```bash
git grep -n -E 'public alias in services_test' -- backend/src backend/tests
git grep -n -E "test container's set\(\)" -- backend/src/Service/Reader/ArticleExtractor/ArticleExtractorInterface.php
php -l backend/src/Service/Reader/ArticleExtractor/ArticleExtractorInterface.php
git add backend/src/Service/Reader/ArticleExtractor/ArticleExtractorInterface.php
git commit -m "docs(#1224): the extractor seam's docblock names how tests swap it today"
```
Expected: the first grep prints nothing; the second (positive control) prints the edited line; `No syntax errors detected`.

### Task 3: Gates, review, PR, merge, report

- [ ] **Step 1: Per-PR gates (from `backend/`).** Warm the dev cache for PHPStan first. Then run the first six commands in parallel. Run `composer infection:diff` only after the native leg has finished, because both use `TEST_TOKEN` workers.
```bash
php bin/console cache:warmup
composer cs
composer stan
composer md
composer tramp
composer test:parallel
docker compose exec php composer test:parallel
# after `composer test:parallel` has finished:
composer infection:diff
```
Expected: all green.
- `composer infection:diff` reports `0 mutations were generated`, because no `src` file changed. It passes on `--ignore-msi-with-no-mutations`.
- Before the MySQL leg, check that the container reads this checkout (STANDING RULE, "check the container is current"): `docker compose exec php test -f tests/DependencyInjection/TestContainerTagParityTest.php && echo present` prints `present`.
- In Docker the prod compile uses `APP_CACHE_DIR=/app/var/cache-docker` (`docker-compose.yml:47`), so it creates `/app/var/cache-docker/prod`. That is expected.

Then run `mcp__phpstorm__lint_files` on `backend/tests/DependencyInjection/TestContainerTagParityTest.php`, and block on ERROR or WARNING.

Scan today's dev log for anything new: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -r 'select(.level_name == "WARNING" or .level_name == "ERROR") | .message' | sort | uniq -c`. Expected: nothing this branch introduced.

- [ ] **Step 2: One independent review.** Dispatch one reviewer subagent (Agent tool, `model: sonnet`). Do not run `/simplify`. Give it the issue (`gh issue view 1224`), this plan's Decisions D1–D7, and `git diff origin/develop...HEAD`. Ask it to find correctness problems only:
  - can the test pass vacuously;
  - does it compare the containers it claims to;
  - can it race or leak state under ParaTest or Infection;
  - does it break a CLAUDE.md rule (names, comments, PHPStan rules).

  Fix each finding you accept, rerun the affected Task 2 checks and the gates it touches, and commit (`test(#1224): <what>`). Record any finding you reject, with its reason, for the report.

- [ ] **Step 3: Push and open the PR.**
```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader
git push -u origin test/1224-test-tag-parity
gh pr create --repo larspohlmann/simple-feed-reader --base develop --head test/1224-test-tag-parity \
  --title "test(#1224): pin tag parity between the test and prod containers" \
  --body-file backend/var/pr-1224-body.md
```
Write `backend/var/pr-1224-body.md` first. It holds:
1. #1275 closed the five gaps the issue lists, and `services_test.yaml` redefines no `App\` service any more (D1).
2. Task 1's result: both comparisons, their `shared` counts, the four empty lists, and the Step 3 controls.
3. The test's design in three lines (D3): compile prod and test at debug off, removal off, compare the tag names of the `App\` definitions both containers hold, in both directions. Also why not a booted kernel or the XML dumps, and the no-write proof from Task 2 Step 5.
4. Deletion checks A and B with the FAIL output quoted, and the test's `Time:`.
5. `_defaults` evaluated and rejected (D2); no allow-list (D5).
6. The gate results.
7. As its last line: `Closes #1224`.

- [ ] **Step 4: Check once that the PR closes the issue.**
```bash
gh pr view --repo larspohlmann/simple-feed-reader --json number,closingIssuesReferences -q '{number, closes: [.closingIssuesReferences[].number]}'
```
Expected: `"closes":[1224]`. If the list is empty, re-save the body once (`gh pr edit <PR> --repo larspohlmann/simple-feed-reader --body-file backend/var/pr-1224-body.md`) and check again. Do not loop.

- [ ] **Step 5: Wait for CI.** Use the Monitor tool with one bounded command that exits on its own: `gh pr checks <PR> --repo larspohlmann/simple-feed-reader --watch --interval 30`. Re-arm it if it times out. Do not write a poll loop.
  - If a check fails, read its log (`gh run view <id> --log-failed`).
  - Remember that CI runs phptramp's `develop` tip: check `composer show larspohlmann/phptramp` before you blame the branch.
  - Fix the cause on the branch, push, and wait again.

- [ ] **Step 6: Merge.** Merge only when every check is green, and never with `--auto`.
```bash
gh pr merge <PR> --repo larspohlmann/simple-feed-reader --merge
gh pr view <PR> --repo larspohlmann/simple-feed-reader --json state,mergeCommit -q '.state + " " + .mergeCommit.oid'
gh issue view 1224 --repo larspohlmann/simple-feed-reader --json state,stateReason -q '.state + " " + .stateReason'
```
Expected: `MERGED <sha>`, then `CLOSED COMPLETED`. If the issue is still open:
```bash
gh issue close 1224 --repo larspohlmann/simple-feed-reader --reason completed --comment "Done in #<PR>, merged as <sha>."
```

- [ ] **Step 7: Report to the planner** (SendMessage). Include:
  - the PR link, the merge SHA and #1224's final state;
  - Task 1's result, with both `shared` counts and the lists;
  - the quoted FAILs of deletion checks A and B;
  - the test's `Time:` and Task 2 Step 5's no-write result;
  - the gate results;
  - the reviewer's findings, fixed or rejected with reasons;
  - any stop condition you hit, with its evidence.
