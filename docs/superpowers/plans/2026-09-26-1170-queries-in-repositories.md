# Queries Live in Repositories (#1170) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every DQL, QueryBuilder, native-SQL and DBAL statement in `backend/src` lives in `src/Repository/`, with the ORM extensions in `src/Doctrine/` as the only exception. A PHPStan rule keeps it that way. The two repositories with hidden side effects lose them.

**Architecture:**
- **The decision.** Queries live in `src/Repository/`. Services orchestrate: they call repository methods and own the unit of work (`persist`, `remove`, `flush`, `clear`, `getReference`, `wrapInTransaction`). An entity's own `ServiceEntityRepository` holds the queries central to that entity. A pipeline that serves one concern gets a **concern repository**: a flat `final readonly` class in `src/Repository/`, named for the concern, that injects `EntityManagerInterface`, or the DBAL `Connection` for raw SQL. The rule is written into `docs/architecture.md` §7 and a CLAUDE.md bullet.
- **The guard.** `QueriesLiveInRepositoriesRule` (PHPStan) starts with an allow-list seeded with today's 15 offenders. Each task deletes its own entries, and the list is empty when Task 8 ends. This is the `ThinControllerRule` pattern: the list only shrinks.
- **The moves are mechanical.** Every query literal is copied character for character, including the whitespace inside multi-line strings. Services keep the orchestration: which feeds, chunking, shuffling, mapping rows to domain values.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM 3.6 and DBAL 4, PHPUnit 12, PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, Infection.

**Spec:** GitHub issue #1170 (`gh issue view 1170`). It builds on **#1165, which has fully merged**: PR #1177 (`refactor/1165-typed-failures`) as `92f116af` and PR #1178 (`refactor/1165-require-id`) as `fcc1e6b8`. Every before-block and file:line below was verified against `origin/develop` @ `fcc1e6b8`. Task 0 checks that the branch starts at or after that commit.

## Global Constraints

- **No behaviour change.** Every moved query has identical SQL semantics, and its query text stays **byte-identical**:
  - Copy DQL/SQL string literals character for character, including the leading whitespace of continuation lines inside multi-line literals, even where it no longer lines up with the new indentation (Task 4's `MarkReadService` UPDATE is the one case).
  - Keep `sprintf` arguments, `setParameter` names, values and types, and QueryBuilder call order unchanged.
  - Task 9 has the only intended SQL changes: the heartbeat upsert, the heartbeat DELETE and the heartbeat array reads. The issue asks for those.
- **Before-blocks quote `origin/develop` @ `fcc1e6b8`**, after both #1165 PRs. That includes #1165's reshaping of `EntryPruner`, `RecommendationCandidateLoader`, `RecommendationHistoryLoader`, `PromptLine` and `RecommendationCallRecorder`, and its `requireId()` lines in `MarkReadService`. If a later commit on `develop` changes one of these files, Task 0 Step 5 catches it. In that case, copy develop's query text, keep this plan's method names and signatures, and record the difference in the commit body.
- **`EntityIdCoercionRule` (#1178) also runs over `tests/`.** Every test this plan adds or changes reads a persisted id with `requireId()`. It never uses `(int) $x->getId()`, `$x->getId() ?? …` or `$x->getId() ?: …`. An entity without the `PersistedId` trait, such as `RecommendationRunLog`, keeps its `getId()` plus `self::assertNotNull()`, as develop's tests do.
- **Boundary with #1157:** controllers' own `persist`/`flush`/`remove` calls stay where they are; #1157 moves them. This plan touches one controller, `HealthController`, and only to take its DBAL `SELECT 1` out.
- **Boundary with #1169:** `AbstractEntryProjectionRepository` stays exactly as it is. Turning it into a composed collaborator is #1169's job (Ruling 3). This plan does not touch it, `EntryListRepository` or `SavedSearchEntryRepository`.
- **Boundary with #1162/#1163/#1166:** do not restructure `Service/Recommendation` (#1162), do not converge the mark-read services or move them to `Service/Reading` (#1163), and do not split `RefreshRunner` or `RestoreEntryLoader` (#1166). `RecordedCall::settle(string, bool)` stays (#1162's).
- **Clean Code (CLAUDE.md):** `final readonly` where the class allows it. At most three parameters on a non-constructor method. No boolean flag parameters. Guard clauses.
- **Comments are one line at most, and only when a future reader would otherwise get the code wrong.** The after-code below already shows the reduced comments; don't reintroduce removed ones. Comments on members this plan does not otherwise change stay as they are.
- **Every touched `src` file must be PHPMD-clean** (`composer md`). Fix the design, never the threshold.
- **Gates**, all run from `backend/`:
  - `composer check` (cs, stan, tramp). `composer stan` needs a warm dev cache, so run `bin/console cache:warmup` after any service or constructor change.
  - `composer md`
  - `php bin/phpunit` (SQLite)
  - `docker compose exec php composer test` (MySQL)
  - `composer infection:diff` (after committing, because it ignores untracked files)
  - PhpStorm `mcp__phpstorm__lint_files` on the changed PHP; block on ERROR and WARNING.
  - Today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name=="ERROR" or .level_name=="CRITICAL")'`
- **Commits:** `refactor(#1170): …` (and `test(#1170): …` for a test-only commit).
- **Branch:** `refactor/1170-queries-in-repositories` off `origin/develop`, at or after `fcc1e6b8` (Task 0). Run `git status` before any branch operation: other sessions share this checkout. Never `git checkout --`, `reset` or `stash`. After a break-test, restore the code by hand.
- All paths and commands below are relative to `backend/` unless they start with `docs/` or `CLAUDE.md`.

## Rulings

The planner ruled on the draft's open decisions. These are settled; do not reopen them during implementation.

1. **Repositories stay flat: `final readonly *Repository` classes in `src/Repository/`**, not `src/Repository/Query/*Query`.
   - `*Query` already names request value objects: `EntryQuery`, `ForYouFeedQuery`, `SavedSearchListQuery`. A `*Query` that runs SQL would give one suffix two meanings.
   - Every existing query collaborator is flat: `DuplicateCollapseDql`, `EntryCategoryLoader`, `RecommendationRunTimingRepository`, `EntryMembershipSweepRepository`.
   - Plain `final readonly` instead of a second `ServiceEntityRepository`: the house style is `final readonly`, which a `ServiceEntityRepository` cannot be. Tests can also build a plain class with `new X($this->em)`.
2. **The heartbeat upsert (Task 9) stays, with explicit tests on both database legs.** `touch()` is one platform-switched upsert: `INSERT … ON DUPLICATE KEY UPDATE` on MySQL, `INSERT … ON CONFLICT (name) DO UPDATE SET` on SQLite. `forget()` becomes a DQL `DELETE`, and reads become array-hydrated.
   - Why not UPDATE-then-INSERT: MySQL reports 0 affected rows for an UPDATE that writes the same value, so two touches within one second would INSERT a duplicate key.
   - Why array reads: a managed `WorkerHeartbeat` would go stale behind a DBAL write.
   - The tests live in `tests/Repository/WorkerHeartbeatRepositoryTest.php` (Task 9 Step 2):
     - `testTouchInsertsARowForANewName`
     - `testTouchMovesAnExistingRowToTheNewInstant`
     - `testTouchingTwiceWithTheSameInstantKeepsOneRow`
     - `testAReadAfterATouchSeesTheNewInstantEvenAfterAnEarlierRead`
     - `testTouchLeavesSomeoneElsesPendingChangesUnflushed`
     - `testForgetLeavesSomeoneElsesPendingChangesUnflushed`
     - `testForgettingANameThatWasNeverTouchedChangesNothing`
   - Each database leg runs one arm of the platform switch, so both legs are mandatory. Natively, `php bin/phpunit` runs the SQLite arm. `docker compose exec php composer test` runs the MySQL arm. CI's `database: [sqlite, mysql]` matrix runs both. Task 9 Step 9 proves that each leg really exercises its own arm.
3. **Task 10 (`AbstractEntryProjectionRepository` → a composed `EntryProjection`) is deleted from this plan and moves to #1169.** This plan leaves `AbstractEntryProjectionRepository`, `EntryListRepository` and `SavedSearchEntryRepository` untouched. §7 of `docs/architecture.md` names the abstract class as the one remaining base repository, which #1169 retires.

Design choices this plan makes within those rulings:

- **Queries go to the entity's own repository only where there is headroom and cohesion.** PHPMD's `TooManyPublicMethods` (10) rules out the obvious homes. For example, `EntryStateRepository` already has 9, so the two read-flip UPDATEs get `EntryReadMarkRepository`.
- **Whole-class moves:** `DatabaseSavedSearchMatcher` and `EntryBatchInserter` are queries through and through, so each moves to `src/Repository/` unchanged apart from its namespace. Splitting them would leave an empty shell service. Precedent: `SavedSearchEntryMembershipRepository` implements the service-side `SavedSearchMembershipWriter` port.
- **`MailSendFailureRepository::add()` only persists and flushes.** `pruneToRetention()` becomes public, and `MailDeliveryHealth::recordFailure()`, the only production caller, calls it explicitly.
- **`AuditUserResolver` had no test.** Task 8 adds one, because its query moves and Infection would otherwise see every mutant escape.

## Inventory: every site, verified on `origin/develop` @ `fcc1e6b8`

| Site | What | Target | Task |
|---|---|---|---|
| `src/Service/Retention/EntryPruner.php:105` | stale-feed `SELECT DISTINCT` | `RetentionRepository::feedIdsFetchedBefore()` | 2 |
| `src/Service/Retention/EntryPruner.php:146` | over-cap `GROUP BY … HAVING` | `RetentionRepository::feedIdsOverCap()` | 2 |
| `src/Service/Retention/EntryPruner.php:179` (`deletablePastBoundary()` QueryBuilder, read through `idsPastBoundary()` :157, `staleIdsPastBoundary()` :163 and `idsOf()` :192) | ids past the boundary | `RetentionRepository::idsPastBoundary()` and `::staleIdsPastBoundary()` | 2 |
| `src/Service/Retention/EntryPruner.php:223` | `rankBoundaryBeyond()` | private `RetentionRepository::rankBoundaryBeyond()` | 2 |
| `src/Service/Retention/EntryPruner.php:260` | empty completed runs `DELETE` | `RetentionRepository::deleteEmptyCompletedRuns()` | 2 |
| `src/Service/Retention/EntryPruner.php:299` | entry chunk `DELETE` | `RetentionRepository::deleteEntries()` | 2 |
| `src/Service/OrphanedFeedReclaimer.php:49` | orphan `SELECT` | `OrphanedFeedRepository::orphanIds()` | 3 |
| `src/Service/OrphanedFeedReclaimer.php:69` | guarded chunk `DELETE` | `OrphanedFeedRepository::deleteOrphansAmong()` | 3 |
| `src/Service/Account/AccountReset.php:62` | run-children `DELETE` (×2 classes) | `AccountWipeRepository::deleteRecommendationData()` | 3 |
| `src/Service/Account/AccountReset.php:85` | per-user `DELETE` (×6 classes) | `AccountWipeRepository::deleteRecommendationData()`, `::deleteOwnedRows()` | 3 |
| `src/Service/Reader/MarkReadService.php:57` | feed-scoped read-flip `UPDATE` | `EntryReadMarkRepository::hideUnreadInFeedsUntil()` | 4 |
| `src/Service/Reader/BulkEntryReadMarker.php:58` | id-scoped read-flip `UPDATE` | `EntryReadMarkRepository::hideUnreadAmong()` | 4 |
| `src/Service/Recommendation/RecommendationCandidateLoader.php:161` (used at :50, :87, :114) | candidate QueryBuilder pipeline | `RecommendationCandidateRepository::newestPool()`, `::forIds()`, `::span()` | 5 |
| `src/Service/Recommendation/RecommendationHistoryLoader.php:86` (used at :43, :58, :74) | history QueryBuilder pipeline | `ReadingHistoryRepository::favorites()`, `::kept()`, `::viewed()` | 5 |
| `src/Service/Recommendation/RecordedCall.php:87, 97, 148, 166, 177, 200, 231` | DBAL `update()` and `executeStatement()` | `RecommendationCallRepository` | 6 |
| `src/Service/Recommendation/RecommendationCallRecorder.php:26, 42` | injects and forwards the `Connection` | injects `RecommendationCallRepository` | 6 |
| `src/Service/Search/Membership/DatabaseSavedSearchMatcher.php:55` | LIKE matcher QueryBuilder | whole class → `src/Repository/DatabaseSavedSearchMatcher.php` | 7 |
| `src/Service/Backup/EntryBatchInserter.php:69` | multi-row `INSERT` | whole class → `src/Repository/EntryBatchInserter.php` | 7 |
| `src/Service/ReaderAudit/AuditSampler.php:60, 114` | raw `SELECT`s | `ReaderAuditRepository::candidateRows()`, `::detailRows()` | 8 |
| `src/Service/ReaderAudit/AuditUserResolver.php:29, 39` | raw `SELECT`s | `ReaderAuditRepository::userIdNamed()`, `::widestSubscriberId()` | 8 |
| `src/Service/Worker/Handler/PurgeFailedMessagesHandler.php:36` | raw `DELETE` | `FailedMessageRepository::deleteFailedBefore()` | 8 |
| `src/Controller/Api/HealthController.php:18` | `SELECT 1` | `DatabaseHealthRepository::ping()` | 8 |
| `src/Repository/MailSendFailureRepository.php:27-34` | `add()` also prunes | `add()` pure; `pruneToRetention()` public | 9 |
| `src/Repository/WorkerHeartbeatRepository.php:26-38, 80-90` | `touch()`/`forget()` flush the whole EntityManager | upsert and DQL `DELETE`; array reads | 9 |

`src/Repository/AbstractEntryProjectionRepository.php` shares code through protected helpers. The issue lists it, but it is not in this plan: it moves to #1169 (Ruling 3).

**Stays, by the rule:**
- `src/Doctrine/*` (`MySqlCollationSchemaListener`, `NormalizeWordBoundariesFunction`, `SqliteConnectionSetupDriver`, `SqliteConnectionSetupMiddleware`, `EntryPlanHintWalker`) extends the ORM itself.
- Services that only *catch* `Doctrine\DBAL\Exception\*` (`RegistrationService`, `AttestationVerifier`, `RefreshRunner`, `RestoreEntryLoader`, `RestoreLoadPass`) run no query.
- Entities importing `Doctrine\DBAL\Types\Types` for mapping.

**Out of scope, noted:** `src/Service/Auth/ActionTokenService.php:81` calls `$this->em->getRepository()`. That is a service locator, not a query, so the rule does not cover it. Leave it.

## File Structure

| File | Responsibility |
|---|---|
| `tests/PhpStan/QueriesLiveInRepositoriesRule.php` | The guard: no query construction outside `App\Repository`, `App\Doctrine`, `App\Tests` |
| `tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`, `tests/PhpStan/data/queries-live-in-repositories-fixtures.php` | The rule's own test and fixtures |
| `src/Repository/RetentionRepository.php` | The retention passes' reads and bulk deletes |
| `src/Repository/EntryRankBoundary.php` | Moved from `Service/Retention`: the keyset boundary, now private to `RetentionRepository` |
| `src/Repository/OrphanedFeedRepository.php` | Feeds nobody subscribes to: find, and delete with the re-check |
| `src/Repository/AccountWipeRepository.php` | Bulk deletes of everything a user owns |
| `src/Repository/EntryReadMarkRepository.php`, `src/Repository/ReadMarking.php` | The two bulk read-flip UPDATEs, and who/when marks read |
| `src/Repository/RecommendationCandidateRepository.php` | The candidate pool and its re-resolution |
| `src/Repository/ReadingHistoryRepository.php` | The reader's favorites, kept and viewed history |
| `src/Repository/TitledEntry.php` | An entry plus the feed name the reader sees |
| `src/Repository/RecommendationCallRepository.php`, `src/Repository/CallSettlement.php` | A recorded provider call's immediate DBAL writes |
| `src/Repository/DatabaseSavedSearchMatcher.php` | Moved from `Service/Search/Membership` |
| `src/Repository/EntryBatchInserter.php` | Moved from `Service/Backup` |
| `src/Repository/ReaderAuditRepository.php` | The reader audit's raw SQL |
| `src/Repository/FailedMessageRepository.php` | Purging the failure transport's table |
| `src/Repository/DatabaseHealthRepository.php` | The health probe's round trip |
| `tests/Repository/WorkerHeartbeatRepositoryTest.php` | The heartbeat upsert, DELETE and array reads, on both database legs |
| `docs/architecture.md` §7, `CLAUDE.md` | The written rule |

---

### Task 0: Preconditions

**Files:** none changed.

- [ ] **Step 1: Confirm the develop head is at or after `fcc1e6b8`.**
  Run: `git fetch origin develop && git merge-base --is-ancestor fcc1e6b8 origin/develop && echo at-or-after`
  Expected: `at-or-after`. If nothing prints, **stop** and report back; do not start.
- [ ] **Step 2: Branch.**
  Run: `git status` (expected: clean; if another session has changes, stop and ask), then `git switch -c refactor/1170-queries-in-repositories origin/develop`.
- [ ] **Step 3: Baseline the gates.**
  Run: `bin/console cache:warmup && composer stan && composer md`
  Expected: both clean. Any finding here is pre-existing. Note it and do not fix it on this branch.
- [ ] **Step 4: Re-take the inventory.**
  Run: `git grep -nE 'createQueryBuilder|createQuery\(|createNativeQuery|getConnection\(|Doctrine\\DBAL\\Connection|Doctrine\\ORM\\QueryBuilder' -- src | grep -vE '^src/(Repository|Doctrine)/'`
  Expected: hits only in the 15 classes listed in Task 1's `ALLOW_LIST`. A new class means another branch added a site. Add it to the allow-list in Task 1 and to the task that owns its module, and tell the reviewer.
- [ ] **Step 5: Check whether a file this plan moves changed after `fcc1e6b8`.**
  Run: `git diff --stat fcc1e6b8 origin/develop -- src/Service/Retention src/Service/OrphanedFeedReclaimer.php src/Service/Account/AccountReset.php src/Service/Reader/MarkReadService.php src/Service/Reader/BulkEntryReadMarker.php src/Service/Recommendation/RecommendationCandidateLoader.php src/Service/Recommendation/RecommendationHistoryLoader.php src/Service/Recommendation/PromptLine.php src/Service/Recommendation/RecordedCall.php src/Service/Recommendation/RecommendationCallRecorder.php src/Service/Search/Membership src/Service/Backup/EntryBatchInserter.php src/Service/ReaderAudit src/Service/Worker/Handler/PurgeFailedMessagesHandler.php src/Controller/Api/HealthController.php src/Repository/MailSendFailureRepository.php src/Repository/WorkerHeartbeatRepository.php src/Service/Mail/MailDeliveryHealth.php phpstan.dist.neon config/services_test.yaml tests/Service/Retention tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Refresh tests/Service/OrphanedFeedReclaimerTest.php tests/Service/Subscription/SubscriptionServiceTest.php tests/Service/Reader/SearchMarkReadServiceTest.php tests/Service/Recommendation/RecordedCallTest.php tests/Service/Recommendation/RecommendationCallRecorderTest.php tests/Service/Search/Membership tests/Service/Backup tests/Service/ReaderAudit/AuditSamplerTest.php tests/Repository/MailSendFailureRepositoryTest.php tests/Service/Mail/MailDeliveryHealthTest.php ../docs/architecture.md ../CLAUDE.md`
  Expected: no output. Every before-block and file:line in Tasks 1–9 was verified at `fcc1e6b8`. For each file the command does list:
  - Re-check that task's before-blocks with `grep -nF '<first line of the block>' <file>`.
  - Take the current text from `develop`.
  - Keep this plan's after-code, method names and signatures.
  - Copy query literals from `develop`, not from this plan.

---

### Task 1: The rule, its test, and the written decision

**Files:**
- Create: `tests/PhpStan/QueriesLiveInRepositoriesRule.php`
- Create: `tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`
- Create: `tests/PhpStan/data/queries-live-in-repositories-fixtures.php`
- Modify: `phpstan.dist.neon` (services block)
- Modify: `docs/architecture.md` (append §7)
- Modify: `CLAUDE.md` (one bullet in "PHP code style")

**Interfaces:**
- Produces: `App\Tests\PhpStan\QueriesLiveInRepositoriesRule` with a private `ALLOW_LIST` const of fully qualified class names. Tasks 2–8 each delete their own entries.
- Produces: the error message `Queries live in src/Repository: <class> uses <what>. Move the query into a repository method (#1170).`, where `<what>` is `->createQuery()`, `->createQueryBuilder()`, `->createNativeQuery()`, `->getConnection()` or a type name.

- [ ] **Step 1: Write the fixtures** (`tests/PhpStan/data/queries-live-in-repositories-fixtures.php`). The line numbers matter: the test asserts 21, 24, 26, 32 and 80.

```php
<?php

declare(strict_types=1);

// Fixtures for QueriesLiveInRepositoriesRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use Doctrine\DBAL\Connection;
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\ORM\QueryBuilder;

    final readonly class BuildsDql
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function run(): mixed
        {
            return $this->em->createQuery('SELECT 1')->execute();
        }

        public function builder(): QueryBuilder
        {
            return $this->em->createQueryBuilder();
        }
    }

    final readonly class HoldsTheConnection
    {
        public function __construct(private Connection $connection)
        {
        }

        public function purge(): void
        {
            $this->connection->executeStatement('DELETE FROM messenger_messages');
        }
    }

    final readonly class OwnsTheUnitOfWork
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function save(object $entity): void
        {
            $this->em->wrapInTransaction(function () use ($entity): void {
                $this->em->persist($entity);
            });
        }
    }

    final readonly class LegacyQuery
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function run(): mixed
        {
            return $this->em->createQuery('SELECT 1')->execute();
        }
    }
}

namespace App\Controller\Fixtures {
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class ReachesForTheConnection
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function ping(): void
        {
            $this->em?->getConnection()->executeQuery('SELECT 1');
        }
    }
}

namespace App\Repository\Fixtures {
    use Doctrine\DBAL\Connection;
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class QueriesHere
    {
        public function __construct(private EntityManagerInterface $em, private Connection $connection)
        {
        }

        public function run(): mixed
        {
            $this->connection->executeStatement('DELETE FROM messenger_messages');

            return $this->em->createQueryBuilder()->getQuery()->execute();
        }
    }
}

namespace App\Doctrine\Fixtures {
    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\Platforms\SQLitePlatform;

    final readonly class ExtendsTheOrm
    {
        public function __construct(private Connection $connection)
        {
        }

        public function isSqlite(): bool
        {
            return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        }
    }
}

namespace App\Tests\Fixtures {
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class AssertsOnTheDatabase
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function count(): mixed
        {
            return $this->em->createQuery('SELECT COUNT(f.id) FROM App\Entity\Feed f')->getSingleScalarResult();
        }
    }
}
```

Check the lines with `grep -nE 'createQuery|QueryBuilder$|Connection \$connection\)$|getConnection' tests/PhpStan/data/queries-live-in-repositories-fixtures.php`. Expected: 21, 24, 26, 32, 64, 80, 91, 99, 110, 132. Of these, 64 is allow-listed, 91 and 99 are in a repository, 110 is under `App\Doctrine` and 132 is under `App\Tests`.

- [ ] **Step 2: Write the failing rule test** (`tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<QueriesLiveInRepositoriesRule> */
final class QueriesLiveInRepositoriesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new QueriesLiveInRepositoriesRule(new NodeFinder(), ['App\Service\Fixtures\LegacyQuery']);
    }

    public function testItReportsQueriesOutsideRepositoriesTheOrmExtensionsAndTests(): void
    {
        $this->analyse(
            [__DIR__ . '/data/queries-live-in-repositories-fixtures.php'],
            [
                [self::message('App\Service\Fixtures\BuildsDql', '->createQuery()'), 21],
                [self::message('App\Service\Fixtures\BuildsDql', 'Doctrine\ORM\QueryBuilder'), 24],
                [self::message('App\Service\Fixtures\BuildsDql', '->createQueryBuilder()'), 26],
                [self::message('App\Service\Fixtures\HoldsTheConnection', 'Doctrine\DBAL\Connection'), 32],
                [self::message('App\Controller\Fixtures\ReachesForTheConnection', '->getConnection()'), 80],
            ],
        );
    }

    private static function message(string $className, string $used): string
    {
        return sprintf(
            'Queries live in src/Repository: %s uses %s. Move the query into a repository method (#1170).',
            $className,
            $used,
        );
    }
}
```

- [ ] **Step 3: Run it and watch it fail.**
  Run: `php bin/phpunit tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`
  Expected: FAIL, because class `App\Tests\PhpStan\QueriesLiveInRepositoriesRule` is not found.

- [ ] **Step 4: Write the rule** (`tests/PhpStan/QueriesLiveInRepositoriesRule.php`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Queries live in src/Repository (#1170, docs/architecture.md §7): elsewhere no class builds DQL, opens a
 * QueryBuilder or holds the DBAL connection. src/Doctrine extends the ORM itself and is exempt, as are the tests.
 *
 * @implements Rule<InClassNode>
 */
final readonly class QueriesLiveInRepositoriesRule implements Rule
{
    private const array EXEMPT_NAMESPACES = ['App\\Repository\\', 'App\\Doctrine\\', 'App\\Tests\\'];

    private const array QUERY_METHODS = ['createNativeQuery', 'createQuery', 'createQueryBuilder', 'getConnection'];

    private const array QUERY_TYPES = [
        'Doctrine\\DBAL\\Connection',
        'Doctrine\\DBAL\\Query\\QueryBuilder',
        'Doctrine\\ORM\\NativeQuery',
        'Doctrine\\ORM\\Query',
        'Doctrine\\ORM\\QueryBuilder',
    ];

    /** Seeded with every offender the day the rule landed; each #1170 task deletes its own, so it only shrinks. */
    private const array ALLOW_LIST = [
        'App\\Controller\\Api\\HealthController',
        'App\\Service\\Account\\AccountReset',
        'App\\Service\\Backup\\EntryBatchInserter',
        'App\\Service\\OrphanedFeedReclaimer',
        'App\\Service\\Reader\\BulkEntryReadMarker',
        'App\\Service\\Reader\\MarkReadService',
        'App\\Service\\ReaderAudit\\AuditSampler',
        'App\\Service\\ReaderAudit\\AuditUserResolver',
        'App\\Service\\Recommendation\\RecommendationCallRecorder',
        'App\\Service\\Recommendation\\RecommendationCandidateLoader',
        'App\\Service\\Recommendation\\RecommendationHistoryLoader',
        'App\\Service\\Recommendation\\RecordedCall',
        'App\\Service\\Retention\\EntryPruner',
        'App\\Service\\Search\\Membership\\DatabaseSavedSearchMatcher',
        'App\\Service\\Worker\\Handler\\PurgeFailedMessagesHandler',
    ];

    /** @param list<string> $allowList fully qualified class names; overridable only for the rule's own test */
    public function __construct(private NodeFinder $finder, private array $allowList = self::ALLOW_LIST)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->getClassReflection()->getName();
        if (!$this->isGuarded($className)) {
            return [];
        }

        $errors = [];
        foreach ($this->finder->find($node->getOriginalNode()->stmts, self::isQueryAccess(...)) as $access) {
            $errors[] = self::error($className, $access);
        }

        return $errors;
    }

    private function isGuarded(string $className): bool
    {
        if (!str_starts_with($className, 'App\\') || \in_array($className, $this->allowList, true)) {
            return false;
        }

        foreach (self::EXEMPT_NAMESPACES as $exempt) {
            if (str_starts_with($className, $exempt)) {
                return false;
            }
        }

        return true;
    }

    private static function isQueryAccess(Node $node): bool
    {
        return \in_array(self::typeName($node), self::QUERY_TYPES, true)
            || \in_array(self::calledMethod($node), self::QUERY_METHODS, true);
    }

    private static function typeName(Node $node): ?string
    {
        return $node instanceof Name ? $node->toString() : null;
    }

    private static function calledMethod(Node $node): ?string
    {
        if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
            return null;
        }

        return $node->name instanceof Identifier ? $node->name->toString() : null;
    }

    private static function error(string $className, Node $access): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Queries live in src/Repository: %s uses %s. Move the query into a repository method (#1170).',
            $className,
            self::typeName($access) ?? '->' . self::calledMethod($access) . '()',
        ))
            ->identifier('simpleFeedReader.queriesLiveInRepositories')
            ->line($access->getStartLine())
            ->build();
    }
}
```

- [ ] **Step 5: Run the test.**
  Run: `php bin/phpunit tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`
  Expected: PASS. If the actual list is in a different order, the analyser sorts by line. Keep the expected list in ascending line order; do not reorder the rule.

- [ ] **Step 6: Register the rule.** In `phpstan.dist.neon`:

Before (the last service entry, added by #1178):
```neon
    -
        class: App\Tests\PhpStan\EntityIdCoercionRule
        tags:
            - phpstan.rules.rule
```
After:
```neon
    -
        class: App\Tests\PhpStan\EntityIdCoercionRule
        tags:
            - phpstan.rules.rule
    -
        class: App\Tests\PhpStan\QueriesLiveInRepositoriesRule
        tags:
            - phpstan.rules.rule
```

- [ ] **Step 7: Run the whole analysis.**
  Run: `composer stan`
  Expected: clean. The seeded allow-list covers every current site.

- [ ] **Step 8: Break it.** Delete the `'App\\Controller\\Api\\HealthController',` line from `ALLOW_LIST`. Run `composer stan` and expect exactly one new error: `Queries live in src/Repository: App\Controller\Api\HealthController uses Doctrine\DBAL\Connection.` on `src/Controller/Api/HealthController.php:15`. Restore the line by hand and re-run `composer stan` (clean).

- [ ] **Step 9: Write the decision into `docs/architecture.md`.** Append after the last line (`If every box is checked, the endpoint serves a native client unchanged.`):

```markdown

## 7. Where database access lives

Every query lives in `backend/src/Repository/`: DQL, the ORM and DBAL query builders, native SQL and DBAL statements
alike. Services orchestrate. They call repository methods and own the unit of work: `persist`, `remove`, `flush`,
`clear`, `getReference`, `wrapInTransaction`. Decided in #1170.

- **An entity's own repository** (`FeedRepository`, `EntryStateRepository`) holds the queries central to that entity.
- **A concern repository** holds a pipeline that serves one concern or spans entities. It is a flat `final readonly`
  class in `src/Repository/`, named for its concern (`RetentionRepository`, `ReadingHistoryRepository`,
  `RecommendationCallRepository`). It injects `EntityManagerInterface`, or the DBAL `Connection` for raw SQL. It
  returns entities, scalars or small row objects (`TitledEntry`); mapping those into domain values stays in the service.
- **`*Query` names are taken.** `EntryQuery`, `ForYouFeedQuery` and `SavedSearchListQuery` are request value objects.
  A class that runs queries is a `*Repository`, and there is no `Repository/Query/` subdirectory.
- **Composition, not inheritance.** Shared query construction is an injected collaborator (`EntryScopePredicates`,
  `DuplicateCollapseDql`), never an abstract base repository. `AbstractEntryProjectionRepository` is the last one
  left; #1169 replaces it with a collaborator.
- **No hidden side effects.** A write method does what its name says. `add()` does not prune. A single-row write is a
  targeted statement, never a whole-EntityManager `flush()` that commits someone else's pending changes.
- **`src/Doctrine/`** (DQL functions, SQL walkers, schema listeners, driver middleware) extends the ORM itself and may
  touch the connection.
- **Controllers** follow the same rule. Their remaining `persist`/`flush` calls are #1157's to move into services.

Enforced by `QueriesLiveInRepositoriesRule` (`backend/tests/PhpStan/`, run by `composer stan`). Outside `src/Repository`
and `src/Doctrine`, no class may call `createQuery`, `createQueryBuilder`, `createNativeQuery` or `getConnection`. It
also may not reference the DBAL `Connection`, an ORM or DBAL `QueryBuilder`, `Query` or `NativeQuery`.
```

- [ ] **Step 10: Add the CLAUDE.md bullet.** In "PHP code style — Clean Code is mandatory":

Before:
```markdown
- **Depend on interfaces, inject them.** No service locators in domain code, no
  `new` on a collaborator inside a method. Strategies get a tag + keyed locator
  (see `Service/Refresh/FeedBodyParser.php` for the pattern).
```
After:
```markdown
- **Depend on interfaces, inject them.** No service locators in domain code, no
  `new` on a collaborator inside a method. Strategies get a tag + keyed locator
  (see `Service/Refresh/FeedBodyParser.php` for the pattern).
- **Queries live in `src/Repository/`** — DQL, QueryBuilder, native SQL and
  DBAL alike; services orchestrate and own the unit of work
  ([docs/architecture.md](docs/architecture.md) §7, `QueriesLiveInRepositoriesRule`).
```

- [ ] **Step 11: Gates for this task.**
  Run: `composer cs && composer stan && php bin/phpunit tests/PhpStan`
  Expected: clean, PASS.

- [ ] **Step 12: Commit.**
```bash
git add tests/PhpStan/QueriesLiveInRepositoriesRule.php tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php tests/PhpStan/data/queries-live-in-repositories-fixtures.php phpstan.dist.neon ../docs/architecture.md ../CLAUDE.md
git commit -m "refactor(#1170): queries live in src/Repository, guarded by QueriesLiveInRepositoriesRule"
```

---
### Task 2: Retention queries move to `RetentionRepository`

**Files:**
- Create: `src/Repository/RetentionRepository.php`
- Move: `src/Service/Retention/EntryRankBoundary.php` → `src/Repository/EntryRankBoundary.php`
- Modify: `src/Service/Retention/EntryPruner.php` (whole file)
- Modify: `tests/PhpStan/QueriesLiveInRepositoriesRule.php` (allow-list)
- Test, constructor call sites only: `tests/Service/Retention/EntryPrunerTest.php`, `tests/Service/Maintenance/MaintenanceTickTest.php`, `tests/Service/Refresh/RefreshRunnerTest.php`, `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`, `tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php`

**Interfaces:**
- Consumes: develop's `EntryPruner` (as #1165 left it). One builder, `deletablePastBoundary(): ?QueryBuilder`, is read through `idsPastBoundary()`, `staleIdsPastBoundary()` (a nullsafe `?->andWhere()?->setParameter()` chain) and `idsOf(?QueryBuilder)`, which maps null to `[]`.
- Produces: `App\Repository\RetentionRepository`:
  - `__construct(EntityManagerInterface $em)`
  - `feedIdsFetchedBefore(\DateTimeImmutable $cutoff): list<int>`
  - `feedIdsOverCap(int $cap): list<int>`
  - `idsPastBoundary(int $feedId, int $keep): list<int>`
  - `staleIdsPastBoundary(int $feedId, int $keep, \DateTimeImmutable $cutoff): list<int>`
  - `deleteEntries(list<int> $ids): void`
  - `deleteEmptyCompletedRuns(): void`
- Produces: `EntryPruner::__construct(RetentionRepository $retention, ClockInterface $clock, EntryIndexer $indexer, int $maxEntriesPerFeed = 2000)`.

- [ ] **Step 1: Red.** Delete `'App\\Service\\Retention\\EntryPruner',` from `ALLOW_LIST` in `tests/PhpStan/QueriesLiveInRepositoriesRule.php`.
  Run: `composer stan`
  Expected: FAIL, with `Queries live in src/Repository: App\Service\Retention\EntryPruner uses …` errors for each query in the file.

- [ ] **Step 2: Characterize first.**
  Run: `php bin/phpunit tests/Service/Retention/EntryPrunerTest.php`
  Expected: PASS. This suite is the behaviour contract and must stay green, unchanged apart from its constructor calls.

- [ ] **Step 3: Move the boundary value.** Run `git mv src/Service/Retention/EntryRankBoundary.php src/Repository/EntryRankBoundary.php`, then replace the file's content with:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/** A feed's `keep`-th newest entry by (createdAt, id): the keyset the retention passes delete beyond. */
final readonly class EntryRankBoundary
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public int $id,
    ) {
    }
}
```

- [ ] **Step 4: Create `src/Repository/RetentionRepository.php`.** Every query literal and builder chain below is develop's `EntryPruner` text. That includes the nullsafe chain in `staleIdsPastBoundary()` and `idsOf(?QueryBuilder)`. The one change is that `staleIdsPastBoundary()` takes `$keep` as a parameter, because `MIN_ENTRIES_PER_FEED` stays in the service.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** The retention passes' queries; EntryPruner picks the feeds and chunks the deletes. */
final readonly class RetentionRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<int> */
    public function feedIdsFetchedBefore(\DateTimeImmutable $cutoff): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT DISTINCT IDENTITY(e.feed) FROM %s e WHERE e.createdAt < :cutoff',
            Entry::class,
        ))
            ->setParameter('cutoff', $cutoff)
            ->getSingleColumnResult();

        return $feedIds;
    }

    /** @return list<int> */
    public function feedIdsOverCap(int $cap): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->em->createQuery(sprintf(
            'SELECT IDENTITY(e.feed) FROM %s e GROUP BY e.feed HAVING COUNT(e.id) > :cap',
            Entry::class,
        ))
            ->setParameter('cap', $cap)
            ->getSingleColumnResult();

        return $feedIds;
    }

    /** @return list<int> the feed's unprotected entries older than its `keep`-th newest */
    public function idsPastBoundary(int $feedId, int $keep): array
    {
        return self::idsOf($this->deletablePastBoundary($feedId, $keep));
    }

    /** @return list<int> */
    public function staleIdsPastBoundary(int $feedId, int $keep, \DateTimeImmutable $cutoff): array
    {
        $query = $this->deletablePastBoundary($feedId, $keep)
            ?->andWhere('e.createdAt < :cutoff')?->setParameter('cutoff', $cutoff);

        return self::idsOf($query);
    }

    /** @param list<int> $ids */
    public function deleteEntries(array $ids): void
    {
        $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.id IN (:ids)', Entry::class))
            ->setParameter('ids', $ids)
            ->execute();
    }

    /** A completed run whose items were all pruned; pending and running runs legitimately have none yet. */
    public function deleteEmptyCompletedRuns(): void
    {
        $this->em->createQuery(sprintf(
            'DELETE FROM %s r WHERE r.status = :completed AND NOT EXISTS (SELECT i.id FROM %s i WHERE i.run = r)',
            RecommendationRun::class,
            RecommendationItem::class,
        ))
            ->setParameter('completed', RecommendationRun::STATUS_COMPLETED)
            ->execute();
    }

    /** Null when the feed holds no more than `keep` entries. */
    private function deletablePastBoundary(int $feedId, int $keep): ?QueryBuilder
    {
        $boundary = $this->rankBoundaryBeyond($feedId, $keep);
        if (null === $boundary) {
            return null;
        }

        return $this->em->createQueryBuilder()
            ->select('e.id')
            ->from(Entry::class, 'e')
            ->where('e.feed = :feed')
            ->andWhere($this->pastBoundaryDql())
            ->andWhere($this->notProtectedDql())
            ->setParameter('feed', $feedId)
            ->setParameter('boundaryCreatedAt', $boundary->createdAt)
            ->setParameter('boundaryId', $boundary->id)
            ->setParameter('true', true, Types::BOOLEAN);
    }

    /**
     * Ranks protected entries too, so they cannot shift the boundary. setFirstResult() walks `keep` rows of
     * idx_entry_feed_created and stops; a correlated COUNT re-scanned the whole feed per row (#384).
     */
    private function rankBoundaryBeyond(int $feedId, int $keep): ?EntryRankBoundary
    {
        /** @var list<array{createdAt: \DateTimeImmutable, id: int}> $rows */
        $rows = $this->em->createQuery(sprintf(
            'SELECT e.createdAt AS createdAt, e.id AS id FROM %s e
             WHERE e.feed = :feed
             ORDER BY e.createdAt DESC, e.id DESC',
            Entry::class,
        ))
            ->setParameter('feed', $feedId)
            ->setFirstResult($keep - 1)
            ->setMaxResults(1)
            ->getResult();

        if ($rows === []) {
            return null;
        }

        return new EntryRankBoundary($rows[0]['createdAt'], $rows[0]['id']);
    }

    /** Strictly older than the boundary: a keyset range that idx_entry_feed_created serves. */
    private function pastBoundaryDql(): string
    {
        return '(e.createdAt < :boundaryCreatedAt
                 OR (e.createdAt = :boundaryCreatedAt AND e.id < :boundaryId))';
    }

    /** An entry is protected iff any user favorited or kept it. */
    private function notProtectedDql(): string
    {
        return sprintf(
            'NOT EXISTS (
                SELECT IDENTITY(s.user) FROM %s s
                WHERE s.entry = e AND (s.isFavorite = :true OR s.isKept = :true)
            )',
            EntryState::class,
        );
    }

    /** @return list<int> */
    private static function idsOf(?QueryBuilder $query): array
    {
        if (null === $query) {
            return [];
        }

        /** @var list<int> $ids */
        $ids = $query->getQuery()->getSingleColumnResult();

        return $ids;
    }
}
```

`deleteEmptyCompletedRuns()` returns `void`. `EntryPruner::prune()` never read the old `pruneEmptyRuns(): int` result, so the `\is_int()` narrowing goes with it.

- [ ] **Step 5: Rewrite `src/Service/Retention/EntryPruner.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Retention;

use App\Repository\RetentionRepository;
use App\Service\Search\EntryIndexer;
use Symfony\Component\Clock\ClockInterface;

/**
 * Three passes: entries fetched over 90 days ago, a feed's entries beyond its cap, then completed runs left empty.
 * Age counts from the fetch (`createdAt`), never `effectiveDate`: a backfilled article was re-added forever (#384).
 */
final class EntryPruner
{
    private const int RETENTION_DAYS = 90;
    private const int DELETE_CHUNK_SIZE = 500;
    private const int DEFAULT_MAX_ENTRIES_PER_FEED = 2000;

    /** A floor, not a skip: both passes keep a feed's newest 20, however old. */
    private const int MIN_ENTRIES_PER_FEED = 20;

    public function __construct(
        private readonly RetentionRepository $retention,
        private readonly ClockInterface $clock,
        private readonly EntryIndexer $indexer,
        private readonly int $maxEntriesPerFeed = self::DEFAULT_MAX_ENTRIES_PER_FEED,
    ) {
    }

    /**
     * Counts deleted entries only: the empty-run pass deletes runs, not entries.
     *
     * @throws \DateMalformedStringException
     */
    public function prune(): int
    {
        $deletedEntries = $this->pruneByAge() + $this->pruneByFeedCap();
        $this->retention->deleteEmptyCompletedRuns();

        return $deletedEntries;
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function pruneByAge(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS));

        $deleted = 0;
        foreach ($this->retention->feedIdsFetchedBefore($cutoff) as $feedId) {
            $deleted += $this->deleteByIds(
                $this->retention->staleIdsPastBoundary((int) $feedId, self::MIN_ENTRIES_PER_FEED, $cutoff),
            );
        }

        return $deleted;
    }

    private function pruneByFeedCap(): int
    {
        $cap = $this->clampedMaxEntriesPerFeed();

        $deleted = 0;
        foreach ($this->retention->feedIdsOverCap($cap) as $feedId) {
            $deleted += $this->deleteByIds($this->retention->idsPastBoundary((int) $feedId, $cap));
        }

        return $deleted;
    }

    /** An override below the floor would defeat it, and zero would make the boundary's offset negative. */
    private function clampedMaxEntriesPerFeed(): int
    {
        return max(self::MIN_ENTRIES_PER_FEED, $this->maxEntriesPerFeed);
    }

    /**
     * @param list<int> $ids
     */
    private function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        // A bulk DELETE fires no lifecycle event, so the index is told here, one chunk at a time.
        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $this->retention->deleteEntries($chunk);
            $this->indexer->forget($chunk);
        }

        return \count($ids);
    }
}
```

Keep `(int) $feedId` as develop has it. It casts a DQL scalar, not `getId()`, so `EntityIdCoercionRule` does not flag it.

- [ ] **Step 6: Update the test constructor calls.** Every `new EntryPruner($this->em, …)` becomes `new EntryPruner(new RetentionRepository($this->em), …)`, or `$this->retention()` in `EntryPrunerTest`.

`tests/Service/Retention/EntryPrunerTest.php`:
- Add `use App\Repository\RetentionRepository;` directly after `use App\Entity\User;`.
- Add this helper directly after the `indexer()` helper:
```php
    private function retention(): RetentionRepository
    {
        return new RetentionRepository($this->em);
    }
```
- Replace all ten constructor calls:
  Run: `grep -c 'new EntryPruner(\$this->em, ' tests/Service/Retention/EntryPrunerTest.php`. Expected: `10`.
  Run: `sed -i '' 's/new EntryPruner(\$this->em, /new EntryPruner($this->retention(), /' tests/Service/Retention/EntryPrunerTest.php`
  Run the first grep again. Expected: `0`.
  The ten lines, before → after (`origin/develop` @ `fcc1e6b8` line numbers):

| Line | Before | After |
|---|---|---|
| 32 | `$this->pruner = new EntryPruner($this->em, $this->clock, $this->indexer());` | `$this->pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer());` |
| 188 | `$pruner = new EntryPruner($this->em, $this->clock, new EntryIndexer($failingWriter, new NullLogger()));` | `$pruner = new EntryPruner($this->retention(), $this->clock, new EntryIndexer($failingWriter, new NullLogger()));` |
| 251, 269, 396, 429, 460 | `$pruner = new EntryPruner($this->em, $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);` | `$pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);` |
| 488, 587 | `$pruner = new EntryPruner($this->em, $this->clock, $this->indexer(), maxEntriesPerFeed: 3);` | `$pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: 3);` |
| 577 | `$pruner = new EntryPruner($this->em, $this->clock, $this->indexer(), maxEntriesPerFeed: 0);` | `$pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: 0);` |

`tests/Service/Maintenance/MaintenanceTickTest.php`: add `use App\Repository\RetentionRepository;` directly after `use App\Repository\PreferencesRepository;`, then:

Before (line 182):
```php
            new EntryPruner($this->em, $clock, $indexer),
```
After:
```php
            new EntryPruner(new RetentionRepository($this->em), $clock, $indexer),
```

`tests/Service/Refresh/RefreshRunnerTest.php` (line 124), `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php` (line 154) and `tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php` (line 113) each get two changes. First, add `use App\Repository\RetentionRepository;` directly after `use App\Repository\FeedRepository;`. Then:

Before:
```php
            new EntryPruner($this->em, $this->clock, $this->indexer()),
```
After:
```php
            new EntryPruner(new RetentionRepository($this->em), $this->clock, $this->indexer()),
```

- [ ] **Step 7: Run both legs.**
  Run: `bin/console cache:warmup && composer stan && php bin/phpunit tests/Service/Retention tests/Service/Maintenance tests/Service/Refresh`
  Run: `docker compose exec php composer test -- --filter='EntryPrunerTest|MaintenanceTickTest|RefreshRunner'`
  Expected: stan clean (no `EntryPruner` error left), all PASS on both.

- [ ] **Step 8: Break it.** In `RetentionRepository::deletablePastBoundary()`, delete the line `->andWhere($this->notProtectedDql())`. Run `php bin/phpunit tests/Service/Retention/EntryPrunerTest.php` and watch `testPrunesOldEntriesButKeepsProtectedAndRecent` and `testProtectionAppliesAcrossUsers` fail. Restore the line by hand and re-run (PASS).

- [ ] **Step 9: PHPMD.**
  Run: `composer md`
  Expected: clean.

- [ ] **Step 10: Commit.**
```bash
git add src/Repository/RetentionRepository.php src/Repository/EntryRankBoundary.php src/Service/Retention/EntryPruner.php tests/PhpStan/QueriesLiveInRepositoriesRule.php tests/Service/Retention/EntryPrunerTest.php tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Refresh/RefreshRunnerTest.php tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php
git commit -m "refactor(#1170): retention queries live in RetentionRepository; EntryPruner orchestrates"
```

---

### Task 3: Orphan reclaim and account wipe move their bulk DQL out

**Files:**
- Create: `src/Repository/OrphanedFeedRepository.php`
- Create: `src/Repository/AccountWipeRepository.php`
- Modify: `src/Service/OrphanedFeedReclaimer.php` (whole file)
- Modify: `src/Service/Account/AccountReset.php` (whole file)
- Modify: `tests/PhpStan/QueriesLiveInRepositoriesRule.php` (allow-list)
- Test, constructor call sites only: `tests/Service/OrphanedFeedReclaimerTest.php`, `tests/Service/Maintenance/MaintenanceTickTest.php`, `tests/Service/Refresh/RefreshRunnerTest.php`, `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php`, `tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php`, `tests/Service/Subscription/SubscriptionServiceTest.php`. `AccountResetTest` and `UnsubscribeAllTest` fetch from the container and need no change.

**Interfaces:**
- Produces: `App\Repository\OrphanedFeedRepository`:
  - `__construct(EntityManagerInterface $entityManager)`
  - `orphanIds(): list<int>`
  - `deleteOrphansAmong(list<int> $feedIds): int`
- Produces: `App\Repository\AccountWipeRepository`:
  - `__construct(EntityManagerInterface $em)`
  - `deleteRecommendationData(User $user): void`
  - `deleteOwnedRows(User $user): void`
- Produces: `OrphanedFeedReclaimer::__construct(OrphanedFeedRepository $orphans)` and `AccountReset::__construct(AccountWipeRepository $wipe, EntityManagerInterface $em)`.

- [ ] **Step 1: Red.** Delete `'App\\Service\\Account\\AccountReset',` and `'App\\Service\\OrphanedFeedReclaimer',` from `ALLOW_LIST`.
  Run: `composer stan`
  Expected: FAIL with errors naming both classes.

- [ ] **Step 2: Characterize.**
  Run: `php bin/phpunit tests/Service/OrphanedFeedReclaimerTest.php tests/Service/Account/AccountResetTest.php tests/Service/Subscription`
  Expected: PASS.

- [ ] **Step 3: Create `src/Repository/OrphanedFeedRepository.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/** Feeds nobody subscribes to; entries and read state follow through the FK cascade. */
final readonly class OrphanedFeedRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<int> */
    public function orphanIds(): array
    {
        /** @var list<int> $feedIds */
        $feedIds = $this->entityManager->createQuery(sprintf(
            'SELECT f.id FROM %s f WHERE %s',
            Feed::class,
            $this->hasNoSubscriberDql(),
        ))->getSingleColumnResult();

        return $feedIds;
    }

    /**
     * Re-checks "no subscriber" inside the DELETE: a subscription racing in would otherwise die by the cascade.
     *
     * @param list<int> $feedIds
     */
    public function deleteOrphansAmong(array $feedIds): int
    {
        $affected = $this->entityManager->createQuery(sprintf(
            'DELETE FROM %s f WHERE f.id IN (:feedIds) AND %s',
            Feed::class,
            $this->hasNoSubscriberDql(),
        ))
            ->setParameter('feedIds', $feedIds)
            ->execute();

        return \is_int($affected) ? $affected : 0;
    }

    private function hasNoSubscriberDql(): string
    {
        return sprintf(
            'NOT EXISTS (SELECT s.id FROM %s s WHERE s.feed = f)',
            Subscription::class,
        );
    }
}
```

- [ ] **Step 4: Rewrite `src/Service/OrphanedFeedReclaimer.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrphanedFeedRepository;

/**
 * The only place an unsubscribed feed is deleted, so the immediate path and the sweep cannot drift apart.
 * Bulk DQL bypasses the unit of work: a Feed the caller still holds is stale afterwards, so pass an id.
 */
final readonly class OrphanedFeedReclaimer
{
    /** Same chunking as EntryPruner: keeps the IN() list off the parameter limit. */
    private const int DELETE_CHUNK_SIZE = 500;

    public function __construct(private OrphanedFeedRepository $orphans)
    {
    }

    /** True when the feed had no subscriber left and was deleted. */
    public function reclaim(int $feedId): bool
    {
        return $this->deleteOrphans([$feedId]) > 0;
    }

    /** The safety net: every orphan currently in the database. */
    public function reclaimAll(): int
    {
        return $this->deleteOrphans($this->orphans->orphanIds());
    }

    /**
     * @param list<int> $feedIds
     */
    private function deleteOrphans(array $feedIds): int
    {
        if ([] === $feedIds) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($feedIds, self::DELETE_CHUNK_SIZE) as $chunk) {
            $deleted += $this->orphans->deleteOrphansAmong($chunk);
        }

        return $deleted;
    }
}
```

- [ ] **Step 5: Create `src/Repository/AccountWipeRepository.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Bulk DELETEs of everything a user owns; they bypass the identity map, so the caller must clear() it. */
final readonly class AccountWipeRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function deleteRecommendationData(User $user): void
    {
        // Redundant with the run FK's ON DELETE CASCADE on purpose: the wipe's scope stays readable in one place.
        foreach ([RecommendationItem::class, RecommendationRunLog::class] as $childClass) {
            $this->em->createQuery(sprintf(
                'DELETE FROM %s c WHERE IDENTITY(c.run) IN (SELECT r.id FROM %s r WHERE r.user = :user)',
                $childClass,
                RecommendationRun::class,
            ))->setParameter('user', $user)->execute();
        }
        $this->deleteByUser(RecommendationRun::class, $user);
        $this->deleteByUser(RecommendationSettings::class, $user);
    }

    public function deleteOwnedRows(User $user): void
    {
        $this->deleteByUser(EntryState::class, $user);
        // subscription_tag rows die with their subscription and tag through both join columns' ON DELETE CASCADE.
        $this->deleteByUser(Subscription::class, $user);
        $this->deleteByUser(Tag::class, $user);
        $this->deleteByUser(SavedSearch::class, $user);
    }

    /** @param class-string $entityClass */
    private function deleteByUser(string $entityClass, User $user): void
    {
        $this->em->createQuery(sprintf('DELETE FROM %s x WHERE x.user = :user', $entityClass))
            ->setParameter('user', $user)
            ->execute();
    }
}
```

- [ ] **Step 6: Rewrite `src/Service/Account/AccountReset.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Repository\AccountWipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties an account but keeps what identifies or entitles it: the restore's wipe half. Not transactional on purpose:
 * re-running the restore repairs a partial wipe (spec §8). No orphan reclaim: the restore re-subscribes the feeds.
 */
final readonly class AccountReset
{
    public function __construct(
        private AccountWipeRepository $wipe,
        private EntityManagerInterface $em,
    ) {
    }

    public function reset(User $user): void
    {
        $this->wipe->deleteRecommendationData($user);
        $this->wipe->deleteOwnedRows($user);
        $user->getPreferences()->setScrapeFallbackEnabled(false);
        $this->em->flush();
        $this->em->clear();
    }
}
```

- [ ] **Step 7: Update the test constructor calls.** Six files contain `new OrphanedFeedReclaimer($this->em)`. In each one, add `use App\Repository\OrphanedFeedRepository;`:
  - `OrphanedFeedReclaimerTest`: directly after `use App\Entity\User;`.
  - `MaintenanceTickTest`, `RefreshRunnerTest`, `RefreshRunnerConcurrentFetchTest`, `RefreshRunnerOrphanSweepTest`: directly after `use App\Repository\FeedRepository;`.
  - `SubscriptionServiceTest`: directly after `use App\Enum\SourceFormat;`.

  Then replace the call:
  Run: `grep -rc 'new OrphanedFeedReclaimer(\$this->em)' tests | grep -v ':0'`
  Expected: six files, one hit each.
  Run: `grep -rl 'new OrphanedFeedReclaimer(\$this->em)' tests | xargs sed -i '' 's/new OrphanedFeedReclaimer(\$this->em)/new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em))/'`
  Run the first grep again. Expected: no output.

| File:line (`origin/develop` @ `fcc1e6b8`) | Before | After |
|---|---|---|
| `tests/Service/OrphanedFeedReclaimerTest.php:24` | `$this->reclaimer = new OrphanedFeedReclaimer($this->em);` | `$this->reclaimer = new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em));` |
| `tests/Service/Maintenance/MaintenanceTickTest.php:183` | `new OrphanedFeedReclaimer($this->em),` | `new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),` |
| `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php:155` | `new OrphanedFeedReclaimer($this->em),` | `new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),` |
| `tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php:114` | `new OrphanedFeedReclaimer($this->em),` | `new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),` |
| `tests/Service/Refresh/RefreshRunnerTest.php:125` | `new OrphanedFeedReclaimer($this->em),` | `new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),` |
| `tests/Service/Subscription/SubscriptionServiceTest.php:126` | `new OrphanedFeedReclaimer($this->em),` | `new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),` |

- [ ] **Step 8: Run both legs.**
  Run: `bin/console cache:warmup && composer stan && php bin/phpunit tests/Service/OrphanedFeedReclaimerTest.php tests/Service/Account tests/Service/Backup tests/Service/Subscription tests/Service/Maintenance tests/Service/Refresh tests/Controller/Api/AccountBackupControllerTest.php`
  Run: `docker compose exec php composer test -- --filter='OrphanedFeedReclaimerTest|AccountResetTest|AccountRestorerTest|GoldenBackupRestoreTest|SubscriptionServiceTest|UnsubscribeAllTest|MaintenanceTickTest|RefreshRunner'`
  Expected: stan clean, all PASS.

- [ ] **Step 9: Break it.** In `OrphanedFeedRepository::deleteOrphansAmong()`, change `'DELETE FROM %s f WHERE f.id IN (:feedIds) AND %s'` to `'DELETE FROM %s f WHERE f.id IN (:feedIds) OR %s'`. Run `php bin/phpunit tests/Service/OrphanedFeedReclaimerTest.php` and watch `testReclaimKeepsAFeedThatStillHasASubscriber` fail. Restore by hand and re-run (PASS).

- [ ] **Step 10: PHPMD.** Run: `composer md`. Expected: clean.

- [ ] **Step 11: Commit.**
```bash
git add src/Repository/OrphanedFeedRepository.php src/Repository/AccountWipeRepository.php src/Service/OrphanedFeedReclaimer.php src/Service/Account/AccountReset.php tests/PhpStan/QueriesLiveInRepositoriesRule.php tests/Service/OrphanedFeedReclaimerTest.php tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Refresh/RefreshRunnerTest.php tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php tests/Service/Refresh/RefreshRunnerOrphanSweepTest.php tests/Service/Subscription/SubscriptionServiceTest.php
git commit -m "refactor(#1170): orphan reclaim and account wipe run their bulk DQL through repositories"
```

---

### Task 4: The two read-flip UPDATEs move to `EntryReadMarkRepository`

**Files:**
- Create: `src/Repository/ReadMarking.php`
- Create: `src/Repository/EntryReadMarkRepository.php`
- Modify: `src/Service/Reader/MarkReadService.php` (imports, constructor, the transaction block)
- Modify: `src/Service/Reader/BulkEntryReadMarker.php` (whole file)
- Modify: `tests/PhpStan/QueriesLiveInRepositoriesRule.php` (allow-list)
- Test: `tests/Service/Reader/SearchMarkReadServiceTest.php` (one new pin test, Step 8). `MarkReadServiceTest` and `EntryControllerTest` are built through the container and stay unchanged.

**Interfaces:**
- Produces: `App\Repository\ReadMarking`, a `final readonly` value with `public int $userId` and `public \DateTimeImmutable $at`.
- Produces: `App\Repository\EntryReadMarkRepository`:
  - `__construct(EntityManagerInterface $em)`
  - `hideUnreadInFeedsUntil(ReadMarking $marking, list<int> $feedIds, \DateTimeImmutable $until): void`
  - `hideUnreadAmong(ReadMarking $marking, list<int> $entryIds): void`
- #1163 later converges the five mark-read services. It should build on this repository, not reintroduce DQL.

`EntryStateRepository` is not the home: it already has 9 public methods, and two more would break PHPMD's `TooManyPublicMethods` (10). `ReadMarking` exists so neither method takes four parameters.

- [ ] **Step 1: Red.** Delete `'App\\Service\\Reader\\BulkEntryReadMarker',` and `'App\\Service\\Reader\\MarkReadService',` from `ALLOW_LIST`.
  Run: `composer stan`
  Expected: FAIL naming both classes.

- [ ] **Step 2: Characterize.**
  Run: `php bin/phpunit tests/Service/Reader tests/Service/Recommendation tests/Controller/Api/EntryControllerTest.php`
  Expected: PASS.

- [ ] **Step 3: Create `src/Repository/ReadMarking.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/** Who marks entries read, and the instant stamped as their hiddenAt. */
final readonly class ReadMarking
{
    public function __construct(
        public int $userId,
        public \DateTimeImmutable $at,
    ) {
    }
}
```

- [ ] **Step 4: Create `src/Repository/EntryReadMarkRepository.php`.** Both DQL literals are byte-identical to develop. The first one's continuation lines keep the 17- and 21-space indent they had inside `MarkReadService`'s closure.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/** Bulk read-flips of existing entry-state rows; creating a missing row is the caller's job. */
final readonly class EntryReadMarkRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @param list<int> $feedIds */
    public function hideUnreadInFeedsUntil(ReadMarking $marking, array $feedIds, \DateTimeImmutable $until): void
    {
        $this->em->createQuery(sprintf(
            'UPDATE %s es SET es.isHidden = :true, es.hiddenAt = :now
                 WHERE es.user = :user AND es.isHidden = :false
                 AND es.entry IN (
                     SELECT e.id FROM %s e
                     WHERE e.feed IN (:feeds) AND e.effectiveDate <= :until
                 )',
            EntryState::class,
            Entry::class,
        ))
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $marking->at, Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $marking->userId)
            ->setParameter('feeds', $feedIds)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->execute();
    }

    /** @param list<int> $entryIds */
    public function hideUnreadAmong(ReadMarking $marking, array $entryIds): void
    {
        $this->em->createQuery(
            'UPDATE ' . EntryState::class . ' es
             SET es.isHidden = :true, es.hiddenAt = :now
             WHERE es.user = :user AND es.isHidden = :false AND IDENTITY(es.entry) IN (:ids)',
        )
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN)
            ->setParameter('now', $marking->at, Types::DATETIME_IMMUTABLE)
            ->setParameter('user', $marking->userId)
            ->setParameter('ids', $entryIds)
            ->execute();
    }
}
```

Byte check: the five continuation lines of the first literal must match develop's, leading spaces included.
  Run: `diff <(git show origin/develop:backend/src/Service/Reader/MarkReadService.php | grep -A5 "'UPDATE %s es SET" | tail -n 5) <(grep -A5 "'UPDATE %s es SET" src/Repository/EntryReadMarkRepository.php | tail -n 5)`
  Expected: no output. Repeat with `"'UPDATE ' . EntryState::class"` and `-A2 … | tail -n 2` against develop's `BulkEntryReadMarker.php`. Expected: no output.

- [ ] **Step 5: Edit `src/Service/Reader/MarkReadService.php`.**

Imports, before:
```php
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
```
After:
```php
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\ValidationException;
use App\Repository\EntryReadMarkRepository;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\ReadMarking;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
```

Constructor, before:
```php
    public function __construct(
        private EntityManagerInterface $em,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }
```
After:
```php
    public function __construct(
        private EntityManagerInterface $em,
        private EntryReadMarkRepository $readMarks,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }
```

The transaction block, before (lines 52–74):
```php
        // Atomic: the bulk read-flip and the watermark advance commit together,
        // so a crash between them can't leave entries half-marked. Inside the
        // transaction the DQL UPDATE participates rather than auto-committing, and
        // wrapInTransaction() flushes the managed watermark changes before commit.
        $this->em->wrapInTransaction(function () use ($user, $feedIds, $until): void {
            $this->em->createQuery(sprintf(
                'UPDATE %s es SET es.isHidden = :true, es.hiddenAt = :now
                 WHERE es.user = :user AND es.isHidden = :false
                 AND es.entry IN (
                     SELECT e.id FROM %s e
                     WHERE e.feed IN (:feeds) AND e.effectiveDate <= :until
                 )',
                EntryState::class,
                Entry::class,
            ))
                ->setParameter('true', true, Types::BOOLEAN)
                ->setParameter('false', false, Types::BOOLEAN)
                ->setParameter('now', $this->clock->now(), Types::DATETIME_IMMUTABLE)
                ->setParameter('user', $user->getId())
                ->setParameter('feeds', $feedIds)
                ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
                ->execute();
        });
```
After:
```php
        // Atomic: the read-flip joins the transaction, which flushes the watermark changes before it commits.
        $this->em->wrapInTransaction(function () use ($user, $feedIds, $until): void {
            $this->readMarks->hideUnreadInFeedsUntil(
                new ReadMarking($user->requireId(), $this->clock->now()),
                $feedIds,
                $until,
            );
        });
```

`$user->requireId()` comes from the `PersistedId` trait (#1165). It binds the same int that `$user->getId()` did, and it is how `mark()` already reads the feed ids at line 45.

- [ ] **Step 6: Rewrite `src/Service/Reader/BulkEntryReadMarker.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\User;
use App\Repository\EntryReadMarkRepository;
use App\Repository\EntryStateRepository;
use App\Repository\ReadMarking;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Marks entries read by entry state alone, for lists no subscription watermark can scope (search, For You): flips
 * an explicit unread, creates a missing row. Batched so a broad search cannot pull every id into memory at once.
 */
final readonly class BulkEntryReadMarker
{
    private const int BATCH = 500;

    public function __construct(
        private EntryStateRepository $states,
        private EntryReadMarkRepository $readMarks,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<int> $entryIds Distinct ids of existing entries: a missing state row
     *  is persisted by reference, so a pruned or repeated id fails the insert. */
    public function markRead(int $userId, array $entryIds): void
    {
        if ($entryIds === []) {
            return;
        }

        $marking = new ReadMarking($userId, $this->clock->now());
        foreach (array_chunk($entryIds, self::BATCH) as $chunk) {
            $this->readMarks->hideUnreadAmong($marking, $chunk);
            $this->createMissing($marking, $chunk);
            $this->em->flush();
            $this->em->clear();
        }
    }

    /** @param list<int> $entryIds */
    private function createMissing(ReadMarking $marking, array $entryIds): void
    {
        $withState = $this->states->entryIdsWithStateForUser($marking->userId, $entryIds);
        $missing = array_values(array_diff($entryIds, $withState));
        if ($missing === []) {
            return;
        }
        $userRef = $this->em->getReference(User::class, $marking->userId)
            ?? throw new \LogicException('The current user has no reference.');
        foreach ($missing as $entryId) {
            $entryRef = $this->em->getReference(Entry::class, $entryId)
                ?? throw new \LogicException('An entry just selected for marking has no reference.');
            $state = new EntryState($userRef, $entryRef);
            $state->hide($marking->at);
            $this->em->persist($state);
        }
    }
}
```

The two `?? throw new \LogicException` lines are develop's, unchanged.

- [ ] **Step 7: Run both legs.**
  Run: `bin/console cache:warmup && composer stan && php bin/phpunit tests/Service/Reader tests/Service/Recommendation tests/Service/Search tests/Controller/Api/EntryControllerTest.php`
  Run: `docker compose exec php composer test -- --filter='MarkRead|EntryController|ForYou'`
  Expected: stan clean, all PASS.

- [ ] **Step 8: Pin the bulk flip's hiddenAt.** No test asserts the instant `hideUnreadAmong()` stamps, so a wrong `:now` would pass unnoticed. Add this test to `tests/Service/Reader/SearchMarkReadServiceTest.php`, directly after `testFlipsAnExplicitlyUnreadMatch()`:

```php
    public function testAFlippedMatchIsStampedWithTheMarkingInstant(): void
    {
        $entry = $this->entry('stamp', 'Klima stamp');
        $this->stateFor($entry, false);
        $aDayAgo = new \DateTimeImmutable('-1 day');

        $this->service()->mark($this->user, 'klima', new \DateTimeImmutable('2100-01-01'));

        $hiddenAt = $this->stateOf($entry)?->getHiddenAt();
        self::assertNotNull($hiddenAt);
        self::assertGreaterThan($aDayAgo, $hiddenAt);
    }
```

  Run: `php bin/phpunit tests/Service/Reader/SearchMarkReadServiceTest.php`
  Expected: PASS.
  Break it: in `EntryReadMarkRepository::hideUnreadAmong()`, change `->setParameter('now', $marking->at, Types::DATETIME_IMMUTABLE)` to `->setParameter('now', new \DateTimeImmutable('2000-01-01'), Types::DATETIME_IMMUTABLE)`. Run the same command and watch `testAFlippedMatchIsStampedWithTheMarkingInstant` fail. Restore the line by hand and re-run (PASS).

- [ ] **Step 9: PHPMD.** Run: `composer md`. Expected: clean.

- [ ] **Step 10: Commit.**
```bash
git add src/Repository/ReadMarking.php src/Repository/EntryReadMarkRepository.php src/Service/Reader/MarkReadService.php src/Service/Reader/BulkEntryReadMarker.php tests/PhpStan/QueriesLiveInRepositoriesRule.php tests/Service/Reader/SearchMarkReadServiceTest.php
git commit -m "refactor(#1170): both read-flip UPDATEs live in EntryReadMarkRepository"
```

---
### Task 5: The recommendation loaders' query pipelines become repositories

**Files:**
- Create: `src/Repository/TitledEntry.php`
- Create: `src/Repository/RecommendationCandidateRepository.php`
- Create: `src/Repository/ReadingHistoryRepository.php`
- Modify: `src/Service/Recommendation/PromptLine.php` (whole file; develop's `entryId` is already a non-null `int`)
- Modify: `src/Service/Recommendation/RecommendationCandidateLoader.php` (whole file)
- Modify: `src/Service/Recommendation/RecommendationHistoryLoader.php` (whole file)
- Modify: `tests/PhpStan/QueriesLiveInRepositoriesRule.php` (allow-list)
- Test: `tests/Service/Recommendation/RecommendationCandidateLoaderTest.php`, `tests/Service/Recommendation/RecommendationHistoryLoaderTest.php`, `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`. All three are container-built and stay unchanged.

**Interfaces:**
- Consumes: `PromptLine::$entryId` is `int`, and `Entry::requireId(): int` comes from `PersistedId`. Both loaders' `hydrateLine()` already call `$entry->requireId()` on develop (candidate :202, history :129).
- Produces: `App\Repository\TitledEntry`:
  - `public Entry $entry`, `public string $feedName`
  - `static of(Entry $entry, ?string $customTitle): self`
- Produces: `App\Repository\RecommendationCandidateRepository`:
  - `__construct(EntityManagerInterface $entityManager, DuplicateCollapseDql $collapse)`
  - `newestPool(int $userId, \DateTimeImmutable $since, int $poolSize): list<TitledEntry>`
  - `forIds(int $userId, non-empty-list<int> $entryIds): list<TitledEntry>`
  - `span(int $userId, non-empty-list<int> $entryIds): array{total: int, oldest: ?string, newest: ?string}`
- Produces: `App\Repository\ReadingHistoryRepository`:
  - `__construct(EntityManagerInterface $entityManager)`
  - `favorites(int $userId, int $cap): list<TitledEntry>`
  - `kept(int $userId, int $cap): list<TitledEntry>`
  - `viewed(int $userId, int $cap): list<TitledEntry>`
- Produces: `PromptLine::of(TitledEntry $titled): self`.

**Why repositories, not query classes:** each pipeline is one concern (the candidate pool, the reading history) with a handful of finders over one shared builder. That is a concern repository by §7. The domain work stays in the loaders: the seeded shuffle, the empty-list guards and the `PromptLine`/`CandidatePoolSummary` mapping.

- [ ] **Step 1: Red.** Delete `'App\\Service\\Recommendation\\RecommendationCandidateLoader',` and `'App\\Service\\Recommendation\\RecommendationHistoryLoader',` from `ALLOW_LIST`.
  Run: `composer stan`
  Expected: FAIL naming both classes.

- [ ] **Step 2: Characterize.**
  Run: `php bin/phpunit tests/Service/Recommendation tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`
  Expected: PASS.

- [ ] **Step 3: Create `src/Repository/TitledEntry.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;

/** An entry with the feed name its reader sees: their customTitle, else the feed's title, else its URL. */
final readonly class TitledEntry
{
    public function __construct(
        public Entry $entry,
        public string $feedName,
    ) {
    }

    public static function of(Entry $entry, ?string $customTitle): self
    {
        $feed = $entry->getFeed();

        return new self($entry, SubscriptionDisplayTitle::from($customTitle, $feed->getTitle(), $feed->getUrl()));
    }
}
```

- [ ] **Step 4: Create `src/Repository/RecommendationCandidateRepository.php`.** The builder chains are copied verbatim from `RecommendationCandidateLoader`.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** The recommendation candidate pool, gated by the reader's For You subscriptions and named by their customTitle. */
final readonly class RecommendationCandidateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DuplicateCollapseDql $collapse,
    ) {
    }

    /**
     * The newest entries since $since the reader has not favorited, kept or viewed, one copy per duplicate group.
     * Read entries stay eligible (#386).
     *
     * @return list<TitledEntry>
     */
    public function newestPool(int $userId, \DateTimeImmutable $since, int $poolSize): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('since', $since)
            ->setParameter('notInteracted', false, Types::BOOLEAN);

        $this->poolScope($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $this->poolScope(...), $userId);
        $qb->setMaxResults($poolSize);

        return $this->titledEntries($qb);
    }

    /**
     * No unread filter, so a resumed run retries its exact snapshot; a pruned or unsubscribed entry drops out.
     *
     * @param non-empty-list<int> $entryIds
     *
     * @return list<TitledEntry>
     */
    public function forIds(int $userId, array $entryIds): array
    {
        $qb = $this->candidateQueryBuilder($userId)
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds);

        return $this->titledEntries($qb);
    }

    /**
     * @param non-empty-list<int> $entryIds
     *
     * @return array{total: int, oldest: ?string, newest: ?string}
     */
    public function span(int $userId, array $entryIds): array
    {
        /** @var array{total: int, oldest: ?string, newest: ?string} $row */
        $row = $this->candidateQueryBuilder($userId)
            ->select('COUNT(e.id) AS total', 'MIN(e.effectiveDate) AS oldest', 'MAX(e.effectiveDate) AS newest')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getSingleResult();

        return $row;
    }

    /** Shared by the outer query and the collapse semi-join, so the two cannot drift and reopen a hole (#496). */
    private function poolScope(QueryBuilder $inner, EntryAliases $aliases): void
    {
        $inner->andWhere(\sprintf('%s.includeInForYou = true', $aliases->subscription))
            ->andWhere(\sprintf(
                '(%1$s.isFavorite = :notInteracted OR %1$s.isFavorite IS NULL)'
                . ' AND (%1$s.isKept = :notInteracted OR %1$s.isKept IS NULL)'
                . ' AND (%1$s.isViewed = :notInteracted OR %1$s.isViewed IS NULL)',
                $aliases->state,
            ))
            ->andWhere(\sprintf('%s.effectiveDate >= :since', $aliases->entry));
    }

    private function candidateQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e', 'f', 's.customTitle AS customTitle')
            ->from(Entry::class, 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('s.includeInForYou = true')
            ->setParameter('user', $userId);
    }

    /** @return list<TitledEntry> */
    private function titledEntries(QueryBuilder $qb): array
    {
        /** @var list<array{0: Entry, customTitle: ?string}> $rows the joined feed folds into the Entry graph */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (array $row): TitledEntry => TitledEntry::of($row[0], $row['customTitle']), $rows);
    }
}
```

- [ ] **Step 5: Create `src/Repository/ReadingHistoryRepository.php`.** The builder chains are copied verbatim from `RecommendationHistoryLoader`.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The reader's history in still-subscribed feeds, each entry only in its highest section (favorite, kept, viewed).
 * Favorites and kept order by effectiveDate, since EntryState has no favorited-at; viewed orders by viewedAt.
 */
final readonly class ReadingHistoryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<TitledEntry> */
    public function favorites(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isFavorite = :true')
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN);

        return $this->titledEntries($qb);
    }

    /** @return list<TitledEntry> */
    public function kept(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isKept = :true AND es.isFavorite = :false')
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);

        return $this->titledEntries($qb);
    }

    /** @return list<TitledEntry> */
    public function viewed(int $userId, int $cap): array
    {
        $qb = $this->historyQueryBuilder($userId)
            ->andWhere('es.isViewed = :true AND es.isFavorite = :false AND es.isKept = :false')
            ->orderBy('es.viewedAt', 'DESC')
            ->setMaxResults($cap)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setParameter('false', false, Types::BOOLEAN);

        return $this->titledEntries($qb);
    }

    private function historyQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('es', 'e', 'f')
            ->addSelect('s.customTitle AS customTitle')
            ->from(EntryState::class, 'es')
            ->join('es.entry', 'e')
            ->join('e.feed', 'f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->setParameter('user', $userId);
    }

    /** @return list<TitledEntry> */
    private function titledEntries(QueryBuilder $qb): array
    {
        /** @var list<array{0: EntryState, customTitle: ?string}> $rows entry and feed fold into the EntryState graph */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $row): TitledEntry => TitledEntry::of($row[0]->getEntry(), $row['customTitle']),
            $rows,
        );
    }
}
```

- [ ] **Step 6: Rewrite `src/Service/Recommendation/PromptLine.php`.** This is develop's file plus `of()`. The body of `of()` is the `new PromptLine` construction that both loaders' `hydrateLine()` methods repeat today.

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\TitledEntry;
use App\Service\Text\PlainText;

/** One entry in a prompt; only candidate lines print their id, so a history line gives the model nothing to pick. */
final readonly class PromptLine
{
    public function __construct(
        public int $entryId,
        public string $title,
        public string $feedName,
        public string $date,
        public ?string $description,
    ) {
    }

    public static function of(TitledEntry $titled): self
    {
        $entry = $titled->entry;

        return new self(
            entryId: $entry->requireId(),
            title: $entry->getTitle(),
            feedName: $titled->feedName,
            date: $entry->getEffectiveDate()->format('Y-m-d'),
            description: PlainText::from($entry->getSummary() ?? $entry->getContentHtml()),
        );
    }
}
```

- [ ] **Step 7: Rewrite `src/Service/Recommendation/RecommendationCandidateLoader.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\RecommendationCandidateRepository;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Loads the candidate pool the recommendation prompt picks from, and re-resolves a checkpointed batch of ids. */
final readonly class RecommendationCandidateLoader
{
    public function __construct(private RecommendationCandidateRepository $candidates)
    {
    }

    /**
     * The newest $request->poolSize candidates in an order seeded by $request->orderSeed, so batches sample the pool
     * rather than cluster by recency (#344); the same seed always gives the same order.
     *
     * @return list<PromptLine>
     */
    public function load(int $userId, CandidatePoolRequest $request): array
    {
        $lines = array_map(
            PromptLine::of(...),
            $this->candidates->newestPool($userId, $request->since, $request->poolSize),
        );

        /** @var list<PromptLine> $shuffled shuffleArray() has no generic stub, so it widens to mixed */
        $shuffled = (new Randomizer(new Mt19937($request->orderSeed)))->shuffleArray($lines);

        return $shuffled;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, PromptLine>
     */
    public function linesForIds(int $userId, array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $linesById = [];
        foreach ($this->candidates->forIds($userId, $entryIds) as $titled) {
            $line = PromptLine::of($titled);
            $linesById[$line->entryId] = $line;
        }

        return $linesById;
    }

    /**
     * Null when the ids resolve to nothing: pruned or unsubscribed ids drop out of the total and the range alike.
     *
     * @param list<int> $entryIds
     */
    public function summarize(int $userId, array $entryIds): ?CandidatePoolSummary
    {
        if ($entryIds === []) {
            return null;
        }

        return $this->hydrateSummary($this->candidates->span($userId, $entryIds));
    }

    /**
     * @param array{total: int, oldest: ?string, newest: ?string} $row an aggregate row over the scoped id set
     */
    private function hydrateSummary(array $row): ?CandidatePoolSummary
    {
        $oldest = $row['oldest'];
        $newest = $row['newest'];
        if (!\is_string($oldest) || !\is_string($newest)) {
            return null;
        }

        return new CandidatePoolSummary(
            total: (int) $row['total'],
            oldest: (new \DateTimeImmutable($oldest))->format('Y-m-d'),
            newest: (new \DateTimeImmutable($newest))->format('Y-m-d'),
        );
    }
}
```

- [ ] **Step 8: Rewrite `src/Service/Recommendation/RecommendationHistoryLoader.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\ReadingHistoryRepository;

/** The reader's weighted history for the recommendation prompt: three capped, newest-first sections. */
final readonly class RecommendationHistoryLoader
{
    public function __construct(private ReadingHistoryRepository $history)
    {
    }

    public function load(int $userId, EffectiveRecommendationSettings $settings): RecommendationHistory
    {
        return new RecommendationHistory(
            favorites: array_map(PromptLine::of(...), $this->history->favorites($userId, $settings->favoritesCap)),
            kept: array_map(PromptLine::of(...), $this->history->kept($userId, $settings->keptCap)),
            viewed: array_map(PromptLine::of(...), $this->history->viewed($userId, $settings->viewedCap)),
        );
    }
}
```

- [ ] **Step 9: Run both legs.**
  Run: `bin/console cache:warmup && composer stan && php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Repository/RecommendationFeedTest.php`
  Run: `docker compose exec php composer test -- --filter='Recommendation|AdvanceRecommendationRunsHandlerTest'`
  Expected: stan clean, all PASS. The prompt-builder tests prove that the rendered prompts are byte-identical.

- [ ] **Step 10: Break it.** In `RecommendationCandidateRepository::poolScope()`, delete `->andWhere(\sprintf('%s.effectiveDate >= :since', $aliases->entry))` (and move the `;` up). Run `php bin/phpunit tests/Service/Recommendation/RecommendationCandidateLoaderTest.php` and watch `testAnEntryOlderThanTheWindowIsExcluded` fail. Restore by hand and re-run (PASS).

- [ ] **Step 11: PHPMD.** Run: `composer md`. Expected: clean.

- [ ] **Step 12: Commit.**
```bash
git add src/Repository/TitledEntry.php src/Repository/RecommendationCandidateRepository.php src/Repository/ReadingHistoryRepository.php src/Service/Recommendation/PromptLine.php src/Service/Recommendation/RecommendationCandidateLoader.php src/Service/Recommendation/RecommendationHistoryLoader.php tests/PhpStan/QueriesLiveInRepositoriesRule.php
git commit -m "refactor(#1170): recommendation candidates and reading history are queried through repositories"
```

---

### Task 6: A recorded call's DBAL writes move to `RecommendationCallRepository`

**Files:**
- Create: `src/Repository/CallSettlement.php`
- Create: `src/Repository/RecommendationCallRepository.php`
- Modify: `src/Service/Recommendation/RecordedCall.php` (whole file)
- Modify: `src/Service/Recommendation/RecommendationCallRecorder.php` (imports, constructor, `begin()`)
- Modify: `tests/PhpStan/QueriesLiveInRepositoriesRule.php` (allow-list)
- Test, constructor call sites only: `tests/Service/Recommendation/RecordedCallTest.php:297, 311`, `tests/Service/Recommendation/RecommendationCallRecorderTest.php:48`

**Interfaces:**
- Produces: `App\Repository\CallSettlement(int $logId, string $verdict, int $wireBytes, \DateTimeImmutable $finishedAt, ?string $finishReason)`.
- Produces: `App\Repository\RecommendationCallRepository`:
  - `__construct(Connection $connection)`
  - `recordStreamedChars(int $runId, int $streamedChars): void`
  - `recordTranscript(int $logId, string $answerSoFar, int $wireBytes): void`
  - `settleAnswered(CallSettlement $settlement, string $content): void`
  - `settleTransportFailure(CallSettlement $settlement, ?string $errorDetail): void`
  - `addUsage(int $runId, CompletionUsage $usage): void`
- Produces: `RecordedCall::__construct(RecommendationCallRepository $calls, ClockInterface $clock, int $runId, ?int $logId)`.
- The writes stay on DBAL, which the repository now owns. They must commit at once for the status poll, and must never flush what the advancer holds dirty mid-tick. `connection->update()` builds its SQL from the array keys in order, so the column order below is load-bearing.

- [ ] **Step 1: Red.** Delete `'App\\Service\\Recommendation\\RecommendationCallRecorder',` and `'App\\Service\\Recommendation\\RecordedCall',` from `ALLOW_LIST`.
  Run: `composer stan`
  Expected: FAIL naming both classes.

- [ ] **Step 2: Characterize.**
  Run: `php bin/phpunit tests/Service/Recommendation/RecordedCallTest.php tests/Service/Recommendation/RecommendationCallRecorderTest.php`
  Expected: PASS.

- [ ] **Step 3: Create `src/Repository/CallSettlement.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

/** What a settled provider call writes onto its run-log row, whatever the verdict. */
final readonly class CallSettlement
{
    public function __construct(
        public int $logId,
        public string $verdict,
        public int $wireBytes,
        public \DateTimeImmutable $finishedAt,
        public ?string $finishReason,
    ) {
    }
}
```

- [ ] **Step 4: Create `src/Repository/RecommendationCallRepository.php`.** The column arrays and the two statements are copied verbatim from `RecordedCall`.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Recommendation\CompletionUsage;
use Doctrine\DBAL\Connection;

/**
 * A recorded provider call's writes, on DBAL on purpose: they commit at once for the status poll, and never flush
 * what the advancer's EntityManager holds dirty mid-tick.
 */
final readonly class RecommendationCallRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function recordStreamedChars(int $runId, int $streamedChars): void
    {
        $this->connection->update(
            'recommendation_run',
            ['streamed_chars' => $streamedChars],
            ['id' => $runId],
        );
    }

    public function recordTranscript(int $logId, string $answerSoFar, int $wireBytes): void
    {
        $this->connection->update(
            'recommendation_run_log',
            ['response_text' => $answerSoFar, 'wire_bytes' => $wireBytes],
            ['id' => $logId],
        );
    }

    public function settleAnswered(CallSettlement $settlement, string $content): void
    {
        $this->connection->update('recommendation_run_log', [
            'response_text' => $content,
            'verdict' => $settlement->verdict,
            'wire_bytes' => $settlement->wireBytes,
            'finished_at' => $settlement->finishedAt->format('Y-m-d H:i:s'),
            'finish_reason' => $settlement->finishReason,
        ], ['id' => $settlement->logId]);
    }

    public function settleTransportFailure(CallSettlement $settlement, ?string $errorDetail): void
    {
        $this->connection->update('recommendation_run_log', [
            'verdict' => $settlement->verdict,
            'wire_bytes' => $settlement->wireBytes,
            'finished_at' => $settlement->finishedAt->format('Y-m-d H:i:s'),
            'error_detail' => $errorDetail,
            'finish_reason' => $settlement->finishReason,
        ], ['id' => $settlement->logId]);
    }

    /** SQL arithmetic, not read-modify-write: a #344 wave settles several calls against one run. */
    public function addUsage(int $runId, CompletionUsage $usage): void
    {
        $this->connection->executeStatement(
            'UPDATE recommendation_run SET'
            . ' prompt_tokens = prompt_tokens + :promptTokens,'
            . ' completion_tokens = completion_tokens + :completionTokens,'
            . ' reasoning_tokens = reasoning_tokens + :reasoningTokens,'
            . ' cached_tokens = cached_tokens + :cachedTokens'
            . ' WHERE id = :runId',
            [
                'promptTokens' => $usage->promptTokens,
                'completionTokens' => $usage->completionTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedTokens' => $usage->cachedTokens,
                'runId' => $runId,
            ],
        );

        $this->addCost($runId, $usage->costNanoCredits);
    }

    /** An unpriced call leaves the column NULL: null means no price was reported, 0 would claim the run was free. */
    private function addCost(int $runId, ?int $costNanoCredits): void
    {
        if (null === $costNanoCredits) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE recommendation_run'
            . ' SET cost_nano_credits = COALESCE(cost_nano_credits, 0) + :costNanoCredits'
            . ' WHERE id = :runId',
            ['costNanoCredits' => $costNanoCredits, 'runId' => $runId],
        );
    }
}
```

`recordStreamedChars($runId, 0)` generates the same `UPDATE recommendation_run SET streamed_chars = ? WHERE id = ?` that `resetLiveness()` issued, so both uses share the one method.

- [ ] **Step 5: Rewrite `src/Service/Recommendation/RecordedCall.php`** (whole file). The docblocks on `$wireBytes`, `$finishReason`, `$usage`, `$usageBanked`, the constructor comment and `settle()` are unchanged from develop.

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRunLog;
use App\Repository\CallSettlement;
use App\Repository\RecommendationCallRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * The stream observer for one recorded provider call (#309). Not readonly: its one piece of state is when it last
 * checkpointed. `$logId` null means debug is off — liveness is still kept, the transcript is not.
 */
final class RecordedCall implements CompletionStreamObserver
{
    /** The issue's ~2 s pseudo-streaming cadence. */
    private const int CHECKPOINT_SECONDS = 2;

    private \DateTimeImmutable $lastCheckpointAt;

    /**
     * Tracked on every report, not only on the ones that checkpoint, so the
     * final row records what the provider really sent rather than whatever
     * the last throttled write happened to catch.
     */
    private int $wireBytes = 0;

    /**
     * Held like $wireBytes, not written until the call settles: the provider
     * stamps it near the end of the stream, and the settled row is where it
     * explains the outcome — a `length` beside an empty answer is a truncation,
     * not silence (#327).
     */
    private ?string $finishReason = null;

    /**
     * The provider's own accounting for this call, held like $finishReason
     * and banked when the call settles (#409). Sticky: it arrives in one late
     * message, so a later report without it must not erase it.
     */
    private ?CompletionUsage $usage = null;

    /**
     * One provider call is billed once. Every settle path -- a verdict, a transport
     * abort, a wave that aborts a call the round already settled -- runs through
     * bankUsage(), and without this flag a call reachable by two of them would double
     * its own spend. Per-instance on purpose: a retry and the discarded sibling of an
     * aborted wave are separate RecordedCalls, each billed by the provider (#344). Only
     * set once bankUsage() actually writes, so a settle that finds no usage yet (a
     * transport failure before the provider's usage message arrived) leaves this false
     * for a later settle path to still bank it.
     */
    private bool $usageBanked = false;

    public function __construct(
        private readonly RecommendationCallRepository $calls,
        private readonly ClockInterface $clock,
        private readonly int $runId,
        private readonly ?int $logId,
    ) {
        // The interval is armed at begin() time: begin() already persisted
        // everything worth persisting at time zero, so the first checkpoint
        // is due CHECKPOINT_SECONDS after the call went out.
        $this->lastCheckpointAt = $clock->now();
    }

    public function streamProgressed(CompletionStreamProgress $progress): void
    {
        $this->wireBytes = $progress->wireBytes;
        $this->finishReason = $progress->finishReason ?? $this->finishReason;
        $this->usage = $progress->usage ?? $this->usage;

        $now = $this->clock->now();
        if ($now->getTimestamp() - $this->lastCheckpointAt->getTimestamp() < self::CHECKPOINT_SECONDS) {
            return;
        }
        $this->lastCheckpointAt = $now;

        $this->calls->recordStreamedChars($this->runId, $progress->wireBytes);

        if (null === $this->logId) {
            return;
        }

        $this->calls->recordTranscript($this->logId, $progress->answerSoFar, $progress->wireBytes);
    }

    public function finishUsable(string $content): void
    {
        $this->finish($content, RecommendationRunLog::VERDICT_USABLE);
    }

    public function finishUnusable(string $content): void
    {
        $this->finish($content, RecommendationRunLog::VERDICT_UNUSABLE);
    }

    /**
     * Settles this call with the parser's verdict on $content: usable banks
     * it as the answer, unusable records it as the invalid reply the next
     * retry corrects against.
     */
    public function settle(string $content, bool $usable): void
    {
        if ($usable) {
            $this->finishUsable($content);

            return;
        }

        $this->finishUnusable($content);
    }

    /** The stream died mid-answer: the salvaged checkpoints stay, stamped with the byte count and the error (#320). */
    public function abortAfterTransportFailure(?string $errorDetail): void
    {
        $this->resetLiveness();
        $this->bankUsage();

        if (null === $this->logId) {
            return;
        }

        $this->calls->settleTransportFailure(
            $this->settlement($this->logId, RecommendationRunLog::VERDICT_TRANSPORT_FAILED),
            $errorDetail,
        );
    }

    private function finish(string $content, string $verdict): void
    {
        $this->resetLiveness();
        $this->bankUsage();

        if (null === $this->logId) {
            return;
        }

        $this->calls->settleAnswered($this->settlement($this->logId, $verdict), $content);
    }

    private function settlement(int $logId, string $verdict): CallSettlement
    {
        return new CallSettlement($logId, $verdict, $this->wireBytes, $this->clock->now(), $this->finishReason);
    }

    private function resetLiveness(): void
    {
        $this->calls->recordStreamedChars($this->runId, 0);
    }

    /** Runs before the debug guard in both callers: a spend record must not depend on the debug switch (#409). */
    private function bankUsage(): void
    {
        $usage = $this->usage;

        if (null === $usage || $this->usageBanked) {
            return;
        }
        $this->usageBanked = true;

        $this->calls->addUsage($this->runId, $usage);
    }
}
```

`settlement()` reads the clock after `resetLiveness()` and `bankUsage()`, as the old inline `$this->clock->now()` did, so `finished_at` is stamped at the same moment.

- [ ] **Step 6: Edit `src/Service/Recommendation/RecommendationCallRecorder.php`.**

Imports, before:
```php
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationRunLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
```
After:
```php
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationCallRepository;
use App\Repository\RecommendationRunLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
```

Constructor, before:
```php
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunLogRepository $logs,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }
```
After:
```php
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunLogRepository $logs,
        private RecommendationCallRepository $calls,
        private ClockInterface $clock,
    ) {
    }
```

`begin()`'s return. Before (lines 41–46):
```php
        return new RecordedCall(
            $this->connection,
            $this->clock,
            $run->requireId(),
            $log->getId(),
        );
```
After:
```php
        return new RecordedCall(
            $this->calls,
            $this->clock,
            $run->requireId(),
            $log->getId(),
        );
```

`$log->getId()` stays: `RecordedCall` takes a nullable `$logId`, and `RecommendationRunLog` has no `PersistedId`.

- [ ] **Step 7: Update the test constructor calls.**

`tests/Service/Recommendation/RecordedCallTest.php`: add `use App\Repository\RecommendationCallRepository;` directly after `use App\Entity\User;`. Then, at lines 297 and 311:

Before:
```php
        return new RecordedCall($this->em->getConnection(), $this->clock, $runId, $logId);
```
After:
```php
        $calls = new RecommendationCallRepository($this->em->getConnection());

        return new RecordedCall($calls, $this->clock, $runId, $logId);
```

`tests/Service/Recommendation/RecommendationCallRecorderTest.php`: add `use App\Repository\RecommendationCallRepository;` directly before `use App\Repository\RecommendationRunLogRepository;`. Then, at line 48:

Before:
```php
            $this->em->getConnection(),
```
After:
```php
            new RecommendationCallRepository($this->em->getConnection()),
```

- [ ] **Step 8: Run both legs.**
  Run: `bin/console cache:warmup && composer stan && php bin/phpunit tests/Service/Recommendation tests/Service/Worker tests/Controller/Api/RecommendationRunControllerTest.php`
  Run: `docker compose exec php composer test -- --filter='RecordedCall|RecommendationCallRecorder|Recommendation'`
  Expected: stan clean, all PASS.

- [ ] **Step 9: Break it.** In `RecommendationCallRepository::settleAnswered()`, change `'finished_at' => $settlement->finishedAt->format('Y-m-d H:i:s'),` to `'finished_at' => null,`. Run `php bin/phpunit tests/Service/Recommendation/RecordedCallTest.php` and watch `testFinishUsableWritesTheFinishedAtTimestamp` fail. Restore by hand. Then delete the line `$this->addCost($runId, $usage->costNanoCredits);` in `addUsage()`, watch `testBanksTheUsageWithTheDebugSwitchOff` and `testBanksOneCallOnceHoweverManySettlePathsReachIt` fail, and restore by hand.

- [ ] **Step 10: PHPMD.** Run: `composer md`. Expected: clean.

- [ ] **Step 11: Commit.**
```bash
git add src/Repository/CallSettlement.php src/Repository/RecommendationCallRepository.php src/Service/Recommendation/RecordedCall.php src/Service/Recommendation/RecommendationCallRecorder.php tests/PhpStan/QueriesLiveInRepositoriesRule.php tests/Service/Recommendation/RecordedCallTest.php tests/Service/Recommendation/RecommendationCallRecorderTest.php
git commit -m "refactor(#1170): a recorded call writes through RecommendationCallRepository"
```

---

### Task 7: The two all-query services move into `src/Repository` whole

**Files:**
- Move: `src/Service/Search/Membership/DatabaseSavedSearchMatcher.php` → `src/Repository/DatabaseSavedSearchMatcher.php`
- Move: `src/Service/Backup/EntryBatchInserter.php` → `src/Repository/EntryBatchInserter.php`
- Move: `tests/Service/Search/Membership/DatabaseSavedSearchMatcherTest.php` → `tests/Repository/DatabaseSavedSearchMatcherTest.php`
- Move: `tests/Service/Backup/EntryBatchInserterTest.php` → `tests/Repository/EntryBatchInserterTest.php`
- Modify (imports only): `src/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcher.php`, `src/Service/Backup/RestoreEntryLoader.php`, `src/Service/Backup/RestoreEntryLoaderFactory.php`, `tests/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcherTest.php`, `tests/Service/Backup/EntryMediaBackupRoundTripTest.php`, `tests/Service/Backup/EntryPartRestorerTest.php`, `tests/Service/Backup/RestoreEntryLoaderTest.php`
- Modify: `config/services_test.yaml:559`
- Modify: `tests/PhpStan/QueriesLiveInRepositoriesRule.php` (allow-list)

**Interfaces:**
- Produces: `App\Repository\DatabaseSavedSearchMatcher implements App\Service\Search\Membership\SavedSearchMatcher`, with the constructor unchanged: `(EntityManagerInterface $em, SearchTermsPredicateBuilder $predicates)`.
- Produces: `App\Repository\EntryBatchInserter`, with the constructor unchanged: `(Connection $connection, UrlNormalizer $urlNormalizer)`, and `insert(int $feedId, list<EntryLine> $lines): void`.
- `SavedSearchMatcher` stays the service-side port. The repository implements it, as `SavedSearchEntryMembershipRepository` already implements `SavedSearchMembershipWriter`.

- [ ] **Step 1: Red.** Delete `'App\\Service\\Backup\\EntryBatchInserter',` and `'App\\Service\\Search\\Membership\\DatabaseSavedSearchMatcher',` from `ALLOW_LIST`.
  Run: `composer stan`
  Expected: FAIL naming both classes.

- [ ] **Step 2: Characterize.**
  Run: `php bin/phpunit tests/Service/Search tests/Service/Backup`
  Expected: PASS.

- [ ] **Step 3: Move the matcher.** Run: `git mv src/Service/Search/Membership/DatabaseSavedSearchMatcher.php src/Repository/DatabaseSavedSearchMatcher.php`. Then change its header.

Before:
```php
namespace App\Service\Search\Membership;

use App\Entity\Entry;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\SavedSearchTerm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
```
After:
```php
namespace App\Repository;

use App\Entity\Entry;
use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
```

Nothing below the imports changes.

In `src/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcher.php`, before:
```php
namespace App\Service\Search\Membership;

use App\Service\Search\SearchEngineCapability;
```
After:
```php
namespace App\Service\Search\Membership;

use App\Repository\DatabaseSavedSearchMatcher;
use App\Service\Search\SearchEngineCapability;
```

- [ ] **Step 4: Move the matcher's test.** Run: `git mv tests/Service/Search/Membership/DatabaseSavedSearchMatcherTest.php tests/Repository/DatabaseSavedSearchMatcherTest.php`.

Before:
```php
namespace App\Tests\Service\Search\Membership;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Membership\DatabaseSavedSearchMatcher;
```
After:
```php
namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\DatabaseSavedSearchMatcher;
use App\Repository\SearchTermsPredicateBuilder;
```

In `tests/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcherTest.php`, before:
```php
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\DatabaseSavedSearchMatcher;
use App\Service\Search\Membership\EngineOrDatabaseSavedSearchMatcher;
```
After:
```php
use App\Repository\DatabaseSavedSearchMatcher;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\EngineOrDatabaseSavedSearchMatcher;
```

- [ ] **Step 5: Move the inserter.** Run: `git mv src/Service/Backup/EntryBatchInserter.php src/Repository/EntryBatchInserter.php`. Then replace everything from `namespace` down to the class declaration.

Before:
```php
namespace App\Service\Backup;

use App\Enum\CommentsLoad;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Url\UrlNormalizer;
use Doctrine\DBAL\Connection;

/**
 * Multi-row INSERTs into `entry`, 500 rows per statement — the measured
 * 0.085 ms/row path (row-by-row through the ORM is 14× slower at restore
 * scale, see the spec appendix). Raw SQL by necessity.
 *
 * guid_hash travels IN the file because entryState lines address their entry
 * by (feedUrl, guidHash) and no Entry constructor runs here to recompute it.
 * url_hash goes the other way: it is a pure function of a field the file
 * already carries and is referenced by nothing, so it is recomputed here
 * rather than stored — carrying it would be derived data a format can never
 * drop (#556).
 *
 * The column list is spelled out once; values bind positionally per row.
 * Dates are formatted as the naive-UTC wall-clock strings Doctrine's
 * datetime_immutable type stores — every EntryLine date is already UTC
 * (LineField normalises on parse).
 */
final readonly class EntryBatchInserter
```
After:
```php
namespace App\Repository;

use App\Enum\CommentsLoad;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Url\UrlNormalizer;
use Doctrine\DBAL\Connection;

/**
 * Multi-row INSERTs into `entry`, 500 rows a statement: 14× faster than the ORM at restore scale (spec appendix).
 * url_hash is recomputed, never read from the file (#556); dates bind as the naive-UTC strings Doctrine stores.
 */
final readonly class EntryBatchInserter
```

Nothing below the class declaration changes.

In both `src/Service/Backup/RestoreEntryLoader.php` and `src/Service/Backup/RestoreEntryLoaderFactory.php`, the inserter was a same-namespace class. Add `use App\Repository\EntryBatchInserter;` directly before `use App\Repository\EntryRepository;`.

- [ ] **Step 6: Move the inserter's test and fix the importers.** Run: `git mv tests/Service/Backup/EntryBatchInserterTest.php tests/Repository/EntryBatchInserterTest.php`.

Before:
```php
namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\EntryBatchInserter;
use App\Tests\DbTestCase;
```
After:
```php
namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryBatchInserter;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Backup\Dto\EntryLine;
use App\Tests\DbTestCase;
```

The next three files each import the old class once. Delete their `use App\Service\Backup\EntryBatchInserter;` line and add `use App\Repository\EntryBatchInserter;` as shown:
- `tests/Service/Backup/EntryMediaBackupRoundTripTest.php` (line 15): add it directly after `use App\Entity\User;`.
- `tests/Service/Backup/EntryPartRestorerTest.php` (line 17): add it directly before `use App\Repository\EntryRepository;`.
- `tests/Service/Backup/RestoreEntryLoaderTest.php` (line 12): add it directly before `use App\Repository\EntryRepository;`.

`config/services_test.yaml` line 559. Before:
```yaml
    App\Service\Backup\EntryBatchInserter:
```
After:
```yaml
    App\Repository\EntryBatchInserter:
```

- [ ] **Step 7: Check that nothing still names the old classes.**
  Run: `git grep -nE 'Service\\\\(Backup|Search\\\\Membership)\\\\(EntryBatchInserter|DatabaseSavedSearchMatcher)' -- src tests config`
  Expected: no output. `BackupSchemaCoverageTest.php:268` mentions `EntryBatchInserter` in a message string by bare name, which stays valid.

- [ ] **Step 8: Run both legs.**
  Run: `bin/console cache:warmup && composer stan && php bin/phpunit tests/Repository tests/Service/Search tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php`
  Run: `docker compose exec php composer test -- --filter='SavedSearch|EntryBatchInserter|Backup|Restore'`
  Expected: stan clean, all PASS.

- [ ] **Step 9: Break it.** In `src/Repository/DatabaseSavedSearchMatcher.php`, change `'CASE WHEN %s THEN 1 ELSE 0 END AS match%d'` to `'CASE WHEN %s THEN 0 ELSE 1 END AS match%d'`. Run `php bin/phpunit tests/Repository/DatabaseSavedSearchMatcherTest.php` and watch it fail. Restore by hand and re-run (PASS).

- [ ] **Step 10: PHPMD.** Run: `composer md`. Expected: clean.

- [ ] **Step 11: Commit.**
```bash
git add -A src/Repository/DatabaseSavedSearchMatcher.php src/Repository/EntryBatchInserter.php src/Service/Search/Membership src/Service/Backup tests/Repository/DatabaseSavedSearchMatcherTest.php tests/Repository/EntryBatchInserterTest.php tests/Service/Search/Membership tests/Service/Backup config/services_test.yaml tests/PhpStan/QueriesLiveInRepositoriesRule.php
git commit -m "refactor(#1170): the LIKE matcher and the batch inserter are repositories"
```

---

### Task 8: The last DBAL users: reader audit, failed-message purge, health probe

**Files:**
- Create: `src/Repository/ReaderAuditRepository.php`
- Create: `src/Repository/FailedMessageRepository.php`
- Create: `src/Repository/DatabaseHealthRepository.php`
- Modify: `src/Service/ReaderAudit/AuditSampler.php` (whole file)
- Modify: `src/Service/ReaderAudit/AuditUserResolver.php` (whole file)
- Modify: `src/Service/Worker/Handler/PurgeFailedMessagesHandler.php` (whole file)
- Modify: `src/Controller/Api/HealthController.php` (whole file)
- Modify: `tests/PhpStan/QueriesLiveInRepositoriesRule.php` (the allow-list becomes empty)
- Create: `tests/Service/ReaderAudit/AuditUserResolverTest.php`
- Create: `tests/Repository/DatabaseHealthRepositoryTest.php`
- Modify: `tests/Service/ReaderAudit/AuditSamplerTest.php:218-224`
- Unchanged: `PurgeFailedMessagesHandlerTest` and `HealthControllerTest`, which are container- and HTTP-built.

**Interfaces:**
- Produces: `App\Repository\ReaderAuditRepository`:
  - `__construct(Connection $connection)`
  - `userIdNamed(string $idOrEmail): ?int`
  - `widestSubscriberId(): ?int`
  - `candidateRows(int $userId, \DateTimeImmutable $before): list<array<string, mixed>>`
  - `detailRows(list<int> $entryIds, int $userId): list<array<string, mixed>>`
  Null means that no account matches. It is a lookup miss, and `AuditUserResolver` turns it into `NoAuditUserException`.
- Produces: `App\Repository\FailedMessageRepository::deleteFailedBefore(\DateTimeImmutable $cutoff): void`.
- Produces: `App\Repository\DatabaseHealthRepository::ping(): void`. It throws whatever DBAL throws when the database is unreachable.
- `DatabaseValue` stays in `Service/ReaderAudit`. The repository borrows `DatabaseValue::int()` so an id is checked exactly as before.

- [ ] **Step 1: Write the failing resolver test** (`tests/Service/ReaderAudit/AuditUserResolverTest.php`). The resolver has had no test. Its queries are about to move, and Infection would otherwise see every mutant escape.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\ReaderAuditRepository;
use App\Service\ReaderAudit\AuditUserResolver;
use App\Service\ReaderAudit\Exception\NoAuditUserException;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Connection;

final class AuditUserResolverTest extends DbTestCase
{
    private const string MOMENT = '2026-07-01T00:00:00Z';

    public function testADigitOnlyNameIsTheUsersId(): void
    {
        $user = $this->userWithSubscriptions('by-id@example.com', 0);

        self::assertSame($user->requireId(), $this->resolver()->resolve((string) $user->requireId()));
    }

    public function testAnyOtherNameIsTheUsersEmail(): void
    {
        $user = $this->userWithSubscriptions('by-email@example.com', 0);

        self::assertSame($user->requireId(), $this->resolver()->resolve('by-email@example.com'));
    }

    public function testANameNobodyHasIsRefused(): void
    {
        $this->expectException(NoAuditUserException::class);

        $this->resolver()->resolve('nobody@example.com');
    }

    public function testNoNameResolvesToTheAccountWithTheMostSubscriptions(): void
    {
        $this->userWithSubscriptions('narrow@example.com', 1);
        $widest = $this->userWithSubscriptions('widest@example.com', 2);

        self::assertSame($widest->requireId(), $this->resolver()->resolve(null));
    }

    public function testNoNameWithoutAnySubscriptionIsRefused(): void
    {
        $this->userWithSubscriptions('unsubscribed@example.com', 0);

        $this->expectException(NoAuditUserException::class);

        $this->resolver()->resolve(null);
    }

    private function userWithSubscriptions(string $email, int $subscriptions): User
    {
        $moment = new \DateTimeImmutable(self::MOMENT);
        $user = new User($email, $moment);
        $this->em->persist($user);
        for ($position = 0; $position < $subscriptions; ++$position) {
            $feed = new Feed(sprintf('https://feeds.example.com/%s/%d.xml', rawurlencode($email), $position));
            $this->em->persist($feed);
            $this->em->persist(new Subscription($user, $feed, $moment));
        }
        $this->em->flush();

        return $user;
    }

    private function resolver(): AuditUserResolver
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return new AuditUserResolver(new ReaderAuditRepository($connection));
    }
}
```

- [ ] **Step 2: Write the failing health-probe test** (`tests/Repository/DatabaseHealthRepositoryTest.php`):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\DatabaseHealthRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DatabaseHealthRepositoryTest extends TestCase
{
    public function testPingLetsAnUnreachableDatabaseThrow(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('connection refused'));

        $this->expectException(\RuntimeException::class);

        (new DatabaseHealthRepository($connection))->ping();
    }
}
```

`HealthControllerTest` already covers the reachable path end to end.

- [ ] **Step 3: Run both and watch them fail.**
  Run: `php bin/phpunit tests/Service/ReaderAudit/AuditUserResolverTest.php tests/Repository/DatabaseHealthRepositoryTest.php`
  Expected: FAIL. `App\Repository\ReaderAuditRepository` and `App\Repository\DatabaseHealthRepository` are not found.

- [ ] **Step 4: Empty the allow-list.** In `tests/PhpStan/QueriesLiveInRepositoriesRule.php`, only four entries remain: `HealthController`, `AuditSampler`, `AuditUserResolver` and `PurgeFailedMessagesHandler`. Replace the whole constant and its docblock:

Before:
```php
    /** Seeded with every offender the day the rule landed; each #1170 task deletes its own, so it only shrinks. */
    private const array ALLOW_LIST = [
        'App\\Controller\\Api\\HealthController',
        'App\\Service\\ReaderAudit\\AuditSampler',
        'App\\Service\\ReaderAudit\\AuditUserResolver',
        'App\\Service\\Worker\\Handler\\PurgeFailedMessagesHandler',
    ];
```
After:
```php
    /** Empty since #1170 moved the last query; an entry here must say why its class cannot use a repository. */
    private const array ALLOW_LIST = [];
```
  Run: `composer stan`
  Expected: FAIL. The rule names exactly those four classes, and the two new tests from Steps 1–2 also report their not-yet-created classes.

- [ ] **Step 5: Create `src/Repository/ReaderAuditRepository.php`.** The four SQL strings are copied verbatim from `AuditUserResolver` and `AuditSampler`. The multi-line ones keep their 12- and 15-space layout because they sit at the same nesting.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\ReaderAudit\DatabaseValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** The reader audit's raw SQL: whose subscriptions it reads, and the entries it draws from them. */
final readonly class ReaderAuditRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function userIdNamed(string $idOrEmail): ?int
    {
        $column = ctype_digit($idOrEmail) ? 'id' : 'email';

        return self::idOrNull(
            $this->connection->fetchOne("SELECT id FROM app_user WHERE {$column} = :value", ['value' => $idOrEmail]),
        );
    }

    public function widestSubscriberId(): ?int
    {
        return self::idOrNull($this->connection->fetchOne(
            'SELECT user_id FROM subscription GROUP BY user_id ORDER BY COUNT(*) DESC, user_id ASC',
        ));
    }

    /** @return list<array<string, mixed>> feed_id and entry_id, ordered by feed, then entry */
    public function candidateRows(int $userId, \DateTimeImmutable $before): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT s.feed_id AS feed_id, e.id AS entry_id
               FROM subscription s
               JOIN entry e ON e.feed_id = s.feed_id
              WHERE s.user_id = :user AND e.url IS NOT NULL AND e.url <> \'\'
                AND e.created_at < :before
              ORDER BY s.feed_id, e.id',
            ['user' => $userId, 'before' => $before->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<array<string, mixed>>
     */
    public function detailRows(array $entryIds, int $userId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT e.id, e.title, e.url, e.author, e.image_url, f.id AS feed_id,
                    CASE WHEN e.body_is_opening_post THEN NULL ELSE e.content_html END AS article_content_html,
                    f.title AS feed_title, f.url AS feed_url, s.id AS subscription_id
               FROM entry e
               JOIN feed f ON f.id = e.feed_id
               JOIN subscription s ON s.feed_id = f.id AND s.user_id = :user
              WHERE e.id IN (:ids)',
            ['user' => $userId, 'ids' => $entryIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    private static function idOrNull(mixed $id): ?int
    {
        return $id === false ? null : DatabaseValue::int($id);
    }
}
```

- [ ] **Step 6: Rewrite `src/Service/ReaderAudit/AuditUserResolver.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Repository\ReaderAuditRepository;
use App\Service\ReaderAudit\Exception\NoAuditUserException;

/** No account given resolves to the one with the most subscriptions: installations keep test accounts beside it. */
final readonly class AuditUserResolver
{
    public function __construct(private ReaderAuditRepository $audit)
    {
    }

    public function resolve(?string $idOrEmail): int
    {
        return $idOrEmail === null ? $this->widestSubscriber() : $this->named($idOrEmail);
    }

    private function named(string $idOrEmail): int
    {
        return $this->audit->userIdNamed($idOrEmail)
            ?? throw new NoAuditUserException(\sprintf('No user matches "%s".', $idOrEmail));
    }

    private function widestSubscriber(): int
    {
        return $this->audit->widestSubscriberId()
            ?? throw new NoAuditUserException('No account holds a subscription to audit.');
    }
}
```

- [ ] **Step 7: Rewrite `src/Service/ReaderAudit/AuditSampler.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Repository\ReaderAuditRepository;

/**
 * Draws the audit sample stratified by feed: every feed gives one article before any gives a second. The shuffle is
 * seeded in PHP, so every shard draws the same sample on MySQL and SQLite; the caller's cutoff fixes the entry set.
 */
final readonly class AuditSampler
{
    public function __construct(private ReaderAuditRepository $audit)
    {
    }

    /** @return list<SampledEntry> */
    public function sample(AuditSample $request): array
    {
        $byFeed = $this->candidatesByFeed($request->userId, $request->seed, $request->before);
        $chosenIds = $this->roundRobin($byFeed, $request->limit, $request->perFeed);

        return $chosenIds === [] ? [] : $this->detailsOf($chosenIds, $request->userId);
    }

    /**
     * The named articles instead of a draw — how a cleaner change is re-checked
     * against the pages that motivated it.
     *
     * @param list<int> $entryIds
     *
     * @return list<SampledEntry>
     */
    public function pick(array $entryIds, int $userId): array
    {
        return $entryIds === [] ? [] : $this->detailsOf($entryIds, $userId);
    }

    /**
     * Candidate entry ids per feed, each feed's list already shuffled.
     *
     * @return array<int, list<int>>
     */
    private function candidatesByFeed(int $userId, int $seed, \DateTimeImmutable $before): array
    {
        $byFeed = [];
        foreach ($this->audit->candidateRows($userId, $before) as $row) {
            $byFeed[DatabaseValue::int($row['feed_id'])][] = DatabaseValue::int($row['entry_id']);
        }

        mt_srand($seed);
        foreach ($byFeed as $feedId => $entryIds) {
            shuffle($entryIds);
            $byFeed[$feedId] = $entryIds;
        }

        return $byFeed;
    }

    /**
     * @param array<int, list<int>> $byFeed
     *
     * @return list<int>
     */
    private function roundRobin(array $byFeed, int $limit, int $perFeed): array
    {
        $chosen = [];
        for ($round = 0; $round < $perFeed; $round++) {
            foreach ($byFeed as $entryIds) {
                if (!isset($entryIds[$round])) {
                    continue;
                }
                $chosen[] = $entryIds[$round];
                if (\count($chosen) >= $limit) {
                    return $chosen;
                }
            }
        }

        return $chosen;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<SampledEntry>
     */
    private function detailsOf(array $entryIds, int $userId): array
    {
        $byId = [];
        foreach ($this->audit->detailRows($entryIds, $userId) as $row) {
            $entryId = DatabaseValue::int($row['id']);
            $byId[$entryId] = new SampledEntry(
                entryId: $entryId,
                subscriptionId: DatabaseValue::int($row['subscription_id']),
                feedId: DatabaseValue::int($row['feed_id']),
                // A feed the publisher never titled is still a feed to audit; the
                // report names it by its URL rather than dropping it.
                feedTitle: DatabaseValue::nullableString($row['feed_title'])
                    ?? DatabaseValue::string($row['feed_url']),
                title: DatabaseValue::string($row['title']),
                url: DatabaseValue::string($row['url']),
                feedContentHtml: DatabaseValue::nullableString($row['article_content_html']),
                hasFeedImage: DatabaseValue::isPresent($row['image_url']),
                author: DatabaseValue::nullableString($row['author']),
            );
        }

        // Re-ordered to the round-robin order the caller drew, which is what the
        // shard split slices on.
        $ordered = [];
        foreach ($entryIds as $entryId) {
            if (isset($byId[$entryId])) {
                $ordered[] = $byId[$entryId];
            }
        }

        return $ordered;
    }
}
```

`AuditSamplerTest`'s `sampler()` helper (lines 218–224), before:
```php
    private function sampler(): AuditSampler
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return new AuditSampler($connection);
    }
```
After:
```php
    private function sampler(): AuditSampler
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return new AuditSampler(new ReaderAuditRepository($connection));
    }
```
Also add `use App\Repository\ReaderAuditRepository;` directly after `use App\Enum\CommentsLoad;`.

- [ ] **Step 8: Create `src/Repository/FailedMessageRepository.php`.** The statement is verbatim and at the same nesting.

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/** The failure transport's table, which `auto_setup` creates only on the first failed delivery. */
final readonly class FailedMessageRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function deleteFailedBefore(\DateTimeImmutable $cutoff): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = :queue AND created_at < :cutoff',
                ['queue' => 'failed', 'cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (TableNotFoundException) {
            // No table yet: this stack has never failed a message, so there is nothing to purge.
        }
    }
}
```

- [ ] **Step 9: Rewrite `src/Service/Worker/Handler/PurgeFailedMessagesHandler.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Repository\FailedMessageRepository;
use App\Service\Worker\Message\PurgeFailedMessages;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Daily housekeeping (#311): a stuck worker must not grow the failure transport without bound. */
#[AsMessageHandler]
final readonly class PurgeFailedMessagesHandler
{
    private const int RETENTION_DAYS = 30;

    public function __construct(
        private FailedMessageRepository $failedMessages,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeFailedMessages $message): void
    {
        $this->failedMessages->deleteFailedBefore(
            $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS)),
        );
    }
}
```

- [ ] **Step 10: Create `src/Repository/DatabaseHealthRepository.php`:**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final readonly class DatabaseHealthRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function ping(): void
    {
        $this->connection->executeQuery('SELECT 1');
    }
}
```

- [ ] **Step 11: Rewrite `src/Controller/Api/HealthController.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\DatabaseHealthRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(DatabaseHealthRepository $database): JsonResponse
    {
        try {
            $database->ping();
        } catch (\Throwable) {
            return new JsonResponse(
                ['status' => 'error', 'database' => 'unreachable'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
```

- [ ] **Step 12: Run everything this task touches, on both legs.**
  Run: `bin/console cache:warmup && composer stan && php bin/phpunit tests/Service/ReaderAudit tests/Repository/DatabaseHealthRepositoryTest.php tests/Service/Worker/PurgeFailedMessagesHandlerTest.php tests/Controller/HealthControllerTest.php tests/PhpStan`
  Run: `docker compose exec php composer test -- --filter='Audit|PurgeFailedMessages|Health'`
  Expected: stan clean with an empty allow-list, all PASS.

- [ ] **Step 13: Confirm that no site is left.**
  Run: `git grep -nE 'createQueryBuilder|createQuery\(|createNativeQuery|getConnection\(|Doctrine\\DBAL\\Connection|Doctrine\\ORM\\QueryBuilder' -- src | grep -vE '^src/(Repository|Doctrine)/'`
  Expected: no output.

- [ ] **Step 14: Break it.** In `ReaderAuditRepository::widestSubscriberId()`, change `ORDER BY COUNT(*) DESC` to `ORDER BY COUNT(*) ASC`. Run `php bin/phpunit tests/Service/ReaderAudit/AuditUserResolverTest.php` and watch `testNoNameResolvesToTheAccountWithTheMostSubscriptions` fail. Restore by hand and re-run (PASS).

- [ ] **Step 15: PHPMD.** Run: `composer md`. Expected: clean.

- [ ] **Step 16: Commit.**
```bash
git add src/Repository/ReaderAuditRepository.php src/Repository/FailedMessageRepository.php src/Repository/DatabaseHealthRepository.php src/Service/ReaderAudit/AuditSampler.php src/Service/ReaderAudit/AuditUserResolver.php src/Service/Worker/Handler/PurgeFailedMessagesHandler.php src/Controller/Api/HealthController.php tests/PhpStan/QueriesLiveInRepositoriesRule.php tests/Service/ReaderAudit/AuditUserResolverTest.php tests/Service/ReaderAudit/AuditSamplerTest.php tests/Repository/DatabaseHealthRepositoryTest.php
git commit -m "refactor(#1170): the last DBAL statements move into repositories; the allow-list is empty"
```

---
### Task 9: Repositories without hidden side effects

**Files:**
- Modify: `src/Repository/MailSendFailureRepository.php` (whole file)
- Modify: `src/Service/Mail/MailDeliveryHealth.php` (`recordFailure()`)
- Modify: `src/Repository/WorkerHeartbeatRepository.php` (whole file)
- Modify: `tests/Repository/MailSendFailureRepositoryTest.php`
- Modify: `tests/Service/Mail/MailDeliveryHealthTest.php`
- Create: `tests/Repository/WorkerHeartbeatRepositoryTest.php`, the heartbeat tests Ruling 2 requires on both database legs
- Regression, unchanged: `tests/Service/Worker/WorkerPresenceTest.php`, `SweepStreamHeartbeatTest`, `ForYouSweepTest`, `RecommendationRunControllerTest`, `RecommendationSettingsControllerTest`, `DeferredMailFlushHealthTest`, `SendDueDigestsHealthTest`

**Interfaces:**
- `MailSendFailureRepository::add(MailSendFailure $failure): void` persists and flushes, nothing else.
- `MailSendFailureRepository::pruneToRetention(): void` is now public. `MailDeliveryHealth::recordFailure()` calls it right after `add()`.
- `WorkerHeartbeatRepository::touch(string $name, \DateTimeImmutable $when): void` is one upsert statement.
- `WorkerHeartbeatRepository::forget(string $name): void` is one DQL `DELETE`.
- `WorkerHeartbeatRepository::findTouchedAt(string $name): ?\DateTimeImmutable` and `::findTouchedAtByNames(list<string> $names): array<string, \DateTimeImmutable>` keep their signatures. Both read array-hydrated rows, so no managed `WorkerHeartbeat` can go stale behind the upsert.
- These are the plan's only intended SQL changes. The row state each method leaves behind is identical. What changes is that `touch()` and `forget()` no longer flush unrelated pending changes, and `add()` no longer deletes.

- [ ] **Step 1: Write the failing mail tests.** In `tests/Repository/MailSendFailureRepositoryTest.php`, replace `testAddPrunesToRetentionNewestFirst()` (lines 47–64) with these two tests:

```php
    public function testAddKeepsEveryRowItWrites(): void
    {
        for ($minute = 0; $minute <= MailSendFailureRepository::RETENTION; ++$minute) {
            $stamp = sprintf('2026-09-06T10:%02d:00Z', $minute);
            $this->failures->add($this->failure("user{$minute}@example.test", $stamp));
        }

        self::assertSame(MailSendFailureRepository::RETENTION + 1, $this->failures->countAll());
    }

    public function testPruneToRetentionKeepsTheNewest(): void
    {
        for ($minute = 0; $minute < MailSendFailureRepository::RETENTION + 5; ++$minute) {
            $stamp = sprintf('2026-09-06T10:%02d:00Z', $minute);
            $this->failures->add($this->failure("user{$minute}@example.test", $stamp));
        }

        $this->failures->pruneToRetention();

        self::assertSame(MailSendFailureRepository::RETENTION, $this->failures->countAll());
        self::assertSame(
            'user' . (MailSendFailureRepository::RETENTION + 4) . '@example.test',
            $this->failures->recent(1)[0]->getRecipient(),
        );
        $retained = $this->failures->recent(MailSendFailureRepository::RETENTION);
        self::assertSame('user5@example.test', $retained[MailSendFailureRepository::RETENTION - 1]->getRecipient());
    }
```

In `tests/Service/Mail/MailDeliveryHealthTest.php`, add after `testRecordSuccessClearsEveryStoredFailure()`:

```php
    public function testRecordFailureKeepsTheLogWithinRetention(): void
    {
        for ($failure = 0; $failure <= MailSendFailureRepository::RETENTION; ++$failure) {
            $this->health->recordFailure(MailKind::Digest, "reader{$failure}@example.test", 'SMTP is down');
        }

        self::assertSame(MailSendFailureRepository::RETENTION, $this->failures->countAll());
    }
```

- [ ] **Step 2: Write the heartbeat tests** (`tests/Repository/WorkerHeartbeatRepositoryTest.php`). These are Ruling 2's named tests. The first two, together with `testTouchingTwiceWithTheSameInstantKeepsOneRow`, cover the upsert's insert arm, its update arm, and the same-value update that MySQL counts as zero rows. `storedNames()` and `storedTouchedAt()` read through the DBAL connection, so no managed entity can answer in the database's place.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\WorkerHeartbeat;
use App\Repository\WorkerHeartbeatRepository;
use App\Tests\DbTestCase;

final class WorkerHeartbeatRepositoryTest extends DbTestCase
{
    private const string AT = '2026-09-25 10:00:00';
    private const string LATER = '2026-09-25 10:00:30';

    public function testTouchInsertsARowForANewName(): void
    {
        $this->heartbeats()->touch('new', new \DateTimeImmutable(self::AT));

        self::assertSame(['new'], $this->storedNames());
        self::assertSame(self::AT, $this->storedTouchedAt('new'));
    }

    public function testTouchMovesAnExistingRowToTheNewInstant(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));

        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::LATER));

        self::assertSame(['x'], $this->storedNames());
        self::assertSame(self::LATER, $this->storedTouchedAt('x'));
    }

    public function testForgettingANameThatWasNeverTouchedChangesNothing(): void
    {
        $this->heartbeats()->touch('kept', new \DateTimeImmutable(self::AT));

        $this->heartbeats()->forget('never-touched');

        self::assertSame(['kept'], $this->storedNames());
    }

    public function testTouchLeavesSomeoneElsesPendingChangesUnflushed(): void
    {
        $this->em->persist(new WorkerHeartbeat('pending', new \DateTimeImmutable(self::AT)));

        $this->heartbeats()->touch('touched', new \DateTimeImmutable(self::AT));

        self::assertSame(['touched'], $this->storedNames());
    }

    public function testForgetLeavesSomeoneElsesPendingChangesUnflushed(): void
    {
        $this->heartbeats()->touch('forgotten', new \DateTimeImmutable(self::AT));
        $this->em->persist(new WorkerHeartbeat('pending', new \DateTimeImmutable(self::AT)));

        $this->heartbeats()->forget('forgotten');

        self::assertSame([], $this->storedNames());
    }

    public function testTouchingTwiceWithTheSameInstantKeepsOneRow(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));

        self::assertSame(['x'], $this->storedNames());
        self::assertEquals(new \DateTimeImmutable(self::AT), $this->heartbeats()->findTouchedAt('x'));
    }

    public function testAReadAfterATouchSeesTheNewInstantEvenAfterAnEarlierRead(): void
    {
        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::AT));
        $this->heartbeats()->findTouchedAtByNames(['x']);

        $this->heartbeats()->touch('x', new \DateTimeImmutable(self::LATER));

        self::assertEquals(new \DateTimeImmutable(self::LATER), $this->heartbeats()->findTouchedAt('x'));
    }

    /** @return list<string> */
    private function storedNames(): array
    {
        /** @var list<string> $names */
        $names = $this->em->getConnection()->fetchFirstColumn('SELECT name FROM worker_heartbeat ORDER BY name');

        return $names;
    }

    private function storedTouchedAt(string $name): string
    {
        $touchedAt = $this->em->getConnection()->fetchOne(
            'SELECT touched_at FROM worker_heartbeat WHERE name = :name',
            ['name' => $name],
        );
        self::assertIsString($touchedAt);

        return $touchedAt;
    }

    private function heartbeats(): WorkerHeartbeatRepository
    {
        /** @var WorkerHeartbeatRepository $heartbeats */
        $heartbeats = self::getContainer()->get(WorkerHeartbeatRepository::class);

        return $heartbeats;
    }
}
```

- [ ] **Step 3: Run them and watch the right ones fail.**
  Run: `php bin/phpunit tests/Repository/MailSendFailureRepositoryTest.php tests/Service/Mail/MailDeliveryHealthTest.php tests/Repository/WorkerHeartbeatRepositoryTest.php`
  Expected:
  - FAIL: `testAddKeepsEveryRowItWrites` (50 instead of 51), `testPruneToRetentionKeepsTheNewest` (private method), `testTouchLeavesSomeoneElsesPendingChangesUnflushed` (`['pending', 'touched']`), `testForgetLeavesSomeoneElsesPendingChangesUnflushed` (`['pending']`).
  - PASS already: `testRecordFailureKeepsTheLogWithinRetention`, `testTouchInsertsARowForANewName`, `testTouchMovesAnExistingRowToTheNewInstant`, `testTouchingTwiceWithTheSameInstantKeepsOneRow`, `testAReadAfterATouchSeesTheNewInstantEvenAfterAnEarlierRead`, `testForgettingANameThatWasNeverTouchedChangesNothing`. These pin the behaviour the rewrite must keep.
  Run the heartbeat file on the MySQL leg too: `docker compose exec php composer test -- --filter=WorkerHeartbeatRepositoryTest`. Expect the same two heartbeat FAILs and the same PASSes. This is the baseline Step 7 compares against.

- [ ] **Step 4: Rewrite `src/Repository/MailSendFailureRepository.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailSendFailure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailSendFailure>
 */
final class MailSendFailureRepository extends ServiceEntityRepository
{
    public const int RETENTION = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailSendFailure::class);
    }

    public function add(MailSendFailure $failure): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($failure);
        $manager->flush();
    }

    public function deleteAll(): void
    {
        $this->createQueryBuilder('f')->delete()->getQuery()->execute();
    }

    /** @return list<MailSendFailure> newest first */
    public function recent(int $limit): array
    {
        /** @var list<MailSendFailure> $rows */
        $rows = $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setMaxResults(min($limit, self::RETENTION))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    /** Keeps the newest RETENTION rows: an outage retrying every five minutes must not grow the log (#882). */
    public function pruneToRetention(): void
    {
        /** @var list<int> $ids */
        $ids = array_column(
            $this->createQueryBuilder('f')
                ->select('f.id AS id')
                ->orderBy('f.createdAt', 'DESC')
                ->addOrderBy('f.id', 'DESC')
                ->getQuery()
                ->getArrayResult(),
            'id',
        );

        $overflow = array_slice($ids, self::RETENTION);
        if ([] === $overflow) {
            return;
        }

        $this->createQueryBuilder('f')
            ->delete()
            ->where('f.id IN (:ids)')
            ->setParameter('ids', $overflow)
            ->getQuery()
            ->execute();
    }
}
```

- [ ] **Step 5: Edit `MailDeliveryHealth::recordFailure()`** in `src/Service/Mail/MailDeliveryHealth.php`.

Before:
```php
    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        $occurredAt = $this->clock->now();

        $this->failures->add(new MailSendFailure($kind, $recipient, $error, $occurredAt));
    }
```
After:
```php
    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        $occurredAt = $this->clock->now();

        $this->failures->add(new MailSendFailure($kind, $recipient, $error, $occurredAt));
        $this->failures->pruneToRetention();
    }
```

- [ ] **Step 6: Rewrite `src/Repository/WorkerHeartbeatRepository.php`** (whole file):

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkerHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Writes are single statements, never a flush: a worker touches its heartbeat mid-tick, and a flush would commit
 * whatever else the tick holds dirty. Reads are arrays, so no managed copy goes stale behind a write.
 *
 * @extends ServiceEntityRepository<WorkerHeartbeat>
 */
final class WorkerHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerHeartbeat::class);
    }

    /** An upsert, not UPDATE-then-INSERT: MySQL counts an UPDATE to the same value as zero rows. */
    public function touch(string $name, \DateTimeImmutable $when): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $onConflict = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'ON DUPLICATE KEY UPDATE'
            : 'ON CONFLICT (name) DO UPDATE SET';

        $connection->executeStatement(
            sprintf('INSERT INTO worker_heartbeat (name, touched_at) VALUES (?, ?) %s touched_at = ?', $onConflict),
            [$name, $when, $when],
            [Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE],
        );
    }

    public function findTouchedAt(string $name): ?\DateTimeImmutable
    {
        return $this->findTouchedAtByNames([$name])[$name] ?? null;
    }

    /**
     * One query for every name, because the poll path asks about every driver kind on every request.
     *
     * @param list<string> $names
     *
     * @return array<string, \DateTimeImmutable> names without a row are absent
     */
    public function findTouchedAtByNames(array $names): array
    {
        /** @var list<array{name: string, touchedAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('heartbeat')
            ->select('heartbeat.name AS name', 'heartbeat.touchedAt AS touchedAt')
            ->andWhere('heartbeat.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'touchedAt', 'name');
    }

    /** Idempotent by design: the drain command forgets from both its `finally` and its shutdown hook (#371). */
    public function forget(string $name): void
    {
        $this->createQueryBuilder('heartbeat')
            ->delete()
            ->where('heartbeat.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->execute();
    }
}
```

- [ ] **Step 7: Run the new tests and every heartbeat and mail-health suite, on both legs.** Each leg runs a different arm of the upsert's platform switch (Ruling 2), so the MySQL leg is not optional here.
  Run (SQLite arm): `bin/console cache:warmup && composer stan && php bin/phpunit tests/Repository/MailSendFailureRepositoryTest.php tests/Repository/WorkerHeartbeatRepositoryTest.php tests/Service/Mail tests/Service/Worker tests/Service/Recommendation/ForYouSweepTest.php tests/EventListener/DeferredMailFlushHealthTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/Api/RecommendationSettingsControllerTest.php`
  Run (MySQL arm): `docker compose exec php composer test -- --filter='MailSendFailure|MailDeliveryHealth|DeferredMailFlushHealth|SendDueDigestsHealth|WorkerHeartbeat|WorkerPresence|SweepStreamHeartbeat|ForYouSweep|RecommendationRunController|RecommendationSettingsController'`
  Expected: stan clean. All PASS on both legs, including all seven `WorkerHeartbeatRepositoryTest` tests named in Ruling 2.

- [ ] **Step 8: Break it.**
  - Remove `$this->failures->pruneToRetention();` from `MailDeliveryHealth::recordFailure()`. Run `php bin/phpunit tests/Service/Mail/MailDeliveryHealthTest.php` and watch `testRecordFailureKeepsTheLogWithinRetention` fail. Restore by hand.
  - Temporarily replace the body of `WorkerHeartbeatRepository::findTouchedAtByNames()` with develop's entity-hydrating read:
```php
        $touchedAt = [];
        /** @var list<WorkerHeartbeat> $heartbeats */
        $heartbeats = $this->createQueryBuilder('heartbeat')
            ->andWhere('heartbeat.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getResult();
        foreach ($heartbeats as $heartbeat) {
            $touchedAt[$heartbeat->getName()] = $heartbeat->getTouchedAt();
        }

        return $touchedAt;
```
    Run `php bin/phpunit tests/Repository/WorkerHeartbeatRepositoryTest.php` and watch `testAReadAfterATouchSeesTheNewInstantEvenAfterAnEarlierRead` fail: the first read left a managed copy that the upsert cannot update. Restore the array read by hand and re-run (PASS).

- [ ] **Step 9: Prove each leg runs its own arm of the upsert.** Break one arm at a time, in `WorkerHeartbeatRepository::touch()`:
  - **SQLite arm.** Change `: 'ON CONFLICT (name) DO UPDATE SET';` to `: 'ON DUPLICATE KEY UPDATE';`.
    - Run `php bin/phpunit tests/Repository/WorkerHeartbeatRepositoryTest.php` and watch all seven fail with a SQLite syntax error, since each one calls `touch()`.
    - Run `docker compose exec php composer test -- --filter=WorkerHeartbeatRepositoryTest` and watch all seven pass.
    - Restore the line by hand.
  - **MySQL arm.** Change `? 'ON DUPLICATE KEY UPDATE'` to `? 'ON CONFLICT (name) DO UPDATE SET'`.
    - Run `docker compose exec php composer test -- --filter=WorkerHeartbeatRepositoryTest` and watch all seven fail with a MySQL syntax error.
    - Run `php bin/phpunit tests/Repository/WorkerHeartbeatRepositoryTest.php` and watch all seven pass.
    - Restore the line by hand.
  - Re-run both commands. Expected: all seven PASS on both legs.

- [ ] **Step 10: PHPMD.** Run: `composer md`. Expected: clean.

- [ ] **Step 11: Commit.**
```bash
git add src/Repository/MailSendFailureRepository.php src/Service/Mail/MailDeliveryHealth.php src/Repository/WorkerHeartbeatRepository.php tests/Repository/MailSendFailureRepositoryTest.php tests/Service/Mail/MailDeliveryHealthTest.php tests/Repository/WorkerHeartbeatRepositoryTest.php
git commit -m "refactor(#1170): add() only adds, and heartbeat writes are single statements instead of a flush"
```

---

## Finishing

- [ ] **Step 1: Every gate, on the finished branch.** Run all of these from `backend/`:
  - `bin/console cache:warmup && composer check && composer md`
  - `php bin/phpunit`
  - `docker compose exec php composer test` (the dev containers bind-mount this checkout; run it only if the stack was brought up from this checkout)
  - `composer infection:diff`. Expected: MSI at or above `minMsi` (80). An escaped mutant arrives as a line in `var/infection.log`. Kill it with a test, or name it in the PR as equivalent. Never lower `minMsi`.
  - `mcp__phpstorm__lint_files` on every changed PHP file. Block on ERROR and WARNING.
  - The dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name=="ERROR" or .level_name=="CRITICAL")'`. Expected: nothing new.
  - `composer show larspohlmann/phptramp`. CI runs phptramp's `develop` tip, so a tramp finding may come from the tool, not from this branch.
- [ ] **Step 2: The guard holds.**
  - `grep -n 'private const array ALLOW_LIST = \[\];' tests/PhpStan/QueriesLiveInRepositoriesRule.php` prints one line.
  - Task 8 Step 13's inventory grep prints nothing.
  - Every class that `docs/architecture.md` §7 names exists: `for c in FeedRepository EntryStateRepository RetentionRepository ReadingHistoryRepository RecommendationCallRepository TitledEntry EntryScopePredicates DuplicateCollapseDql AbstractEntryProjectionRepository EntryQuery ForYouFeedQuery SavedSearchListQuery; do test -f src/Repository/$c.php || echo "missing $c"; done` prints nothing.
- [ ] **Step 3: The queries really are byte-identical.** For each moved literal, `git grep -nF` its first line on `origin/develop` and on the branch. Both must find it, the develop hit in the old file and the branch hit in the new one. Do this for the `MarkReadService` UPDATE, the `AuditSampler` SELECTs, the `RecordedCall` token UPDATE and every `EntryPruner` literal. Any literal that differs is a bug in the move.
- [ ] **Step 4: SDD final review** (superpowers:requesting-code-review, as in superpowers:subagent-driven-development). Give the reviewer this plan and the diff `origin/develop...HEAD`, and ask them to hunt specifically for:
  - A moved query whose literal, parameter name, parameter type or builder call order changed.
  - An identity-map hazard: a managed entity read before and after a bulk DQL/DBAL write in one request. Check the heartbeat, `EntryReadMarkRepository` and `AccountWipeRepository` paths.
  - A caller that relied on `WorkerHeartbeatRepository::touch()` or `forget()` flushing its own pending changes. Grep every caller of `WorkerPresence::mark()` and `::forget()`, and check whether that code path flushes on its own afterwards.
  - The transaction in `MarkReadService`: the UPDATE still runs inside `wrapInTransaction`.
  - The rule's blind spots: trait code, static calls, and a query reached through `$repository->createQueryBuilder()` from a service. Report whether any exists in `src`.
  Fix what it finds with its own commit and re-run Step 1.
- [ ] **Step 5: `/simplify`** on the branch diff. Apply its quality fixes, then re-run Step 1.
- [ ] **Step 6: Push and open the PR.**
```bash
git push -u origin refactor/1170-queries-in-repositories
gh pr create --base develop --title "refactor(#1170): queries live in src/Repository" --body "$(cat <<'EOF'
Closes #1170

Queries live in `src/Repository/`. DQL, QueryBuilder, native SQL and DBAL are covered alike. Services orchestrate and own the unit of work. The rule is written in docs/architecture.md §7 and CLAUDE.md, and enforced by `QueriesLiveInRepositoriesRule`, whose allow-list started with the 15 offenders and ends empty.

- New concern repositories:
  - `RetentionRepository`, `OrphanedFeedRepository` and `AccountWipeRepository`
  - `EntryReadMarkRepository` and `ReadMarking`
  - `RecommendationCandidateRepository`, `ReadingHistoryRepository` and `TitledEntry`
  - `RecommendationCallRepository` and `CallSettlement`
  - `ReaderAuditRepository`, `FailedMessageRepository` and `DatabaseHealthRepository`
- `DatabaseSavedSearchMatcher` and `EntryBatchInserter` move into `src/Repository` whole.
- Every moved query literal is byte-identical.
- `MailSendFailureRepository::add()` no longer prunes; `MailDeliveryHealth` calls `pruneToRetention()` explicitly.
- `WorkerHeartbeatRepository` writes with an upsert and a DQL DELETE instead of a whole-EntityManager flush, and reads arrays. These are the only intended SQL changes. `WorkerHeartbeatRepositoryTest` covers both arms of the platform-switched upsert, one on each CI database leg.

Settled at plan review: concern repositories are flat `*Repository` classes, not `Repository/Query/*Query` (the `*Query` suffix already names request value objects). The heartbeat upsert stays, with tests on both legs.

Boundaries: `AbstractEntryProjectionRepository` stays for #1169. Controllers' persist/flush stay for #1157. Mark-read convergence stays for #1163. `Service/Recommendation` restructuring stays for #1162.
EOF
)"
```
- [ ] **Step 7: Merge when CI is green, and only then.** Never pass `--auto`: on this repository it merges immediately. Start a Monitor on `gh pr checks <PR> --watch --fail-fast`.
  - When it exits 0, run `gh pr merge <PR> --merge --delete-branch`.
  - If a check fails, read its log (`gh run view --log-failed`), fix on the branch, push, and restart the Monitor.
- [ ] **Step 8: Verify the issue closed.** Run: `gh issue view 1170 --json state -q .state`. Expected: `CLOSED` (auto-closed by `Closes #1170`). Do not close it by hand.

---

## Spec coverage (issue #1170 → task)

| Issue item | Where |
|---|---|
| "Decide and document where queries live" | Task 1: `docs/architecture.md` §7 and the CLAUDE.md bullet. The Rulings section above records the choices. |
| 12 services building DQL/QueryBuilder (`EntryPruner`, `AccountReset`, `OrphanedFeedReclaimer`, `RecommendationCandidateLoader`, `RecommendationHistoryLoader`, `MarkReadService`, `BulkEntryReadMarker`, `DatabaseSavedSearchMatcher`, `EntryBatchInserter`) | Tasks 2–7. The issue's count includes the DBAL users, which Tasks 6 and 8 cover. |
| 6 services on a raw DBAL `Connection` (`RecordedCall`, `RecommendationCallRecorder`, `PurgeFailedMessagesHandler`, `EntryBatchInserter`, `AuditSampler`, `AuditUserResolver`) | Tasks 6, 7 and 8. `HealthController`, a seventh DBAL user the issue missed, is also in Task 8. |
| `MailSendFailureRepository::add()` deletes rows | Task 9 |
| `WorkerHeartbeatRepository::touch/forget` flush the whole EntityManager | Task 9, tested on both legs (Ruling 2) |
| `AbstractEntryProjectionRepository` shares code by inheritance | Not in this plan. It moves to #1169 (Ruling 3). |
| A mechanical guard | Task 1, emptied by Task 8 |
