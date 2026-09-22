# Saved-Search Membership Table Implementation Plan (#1116)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist which entries each saved search matches in a `saved_search_entry` table, filled by an incremental budgeted sweep with a per-search high-water mark, and read by the list, the badges, mark-read and the digest through one join — deleting the engine-vs-database `WithFallback` triples for saved searches.

**Architecture:** A derived-cache table (`SavedSearchEntry`, composite key) plus `saved_search.matched_up_to_entry_id`. `SavedSearchMembershipSweep` walks entry ids above each search's mark in chunks of 500, asks a `SavedSearchMatcher` (DB `LIKE` predicate, or Meilisearch with an `id IN` filter, chosen by `SearchEngineCapability`, never falling back) which ids match, inserts rows and advances the mark in one transaction per chunk, bounded by a budget. It runs from `MaintenanceTick`, from a 1-minute worker message, and synchronously for one search on create. Every reader becomes a query on the table with the unread predicate in the same statement.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM 3 / DBAL, Meilisearch v1.13 (optional), PHPUnit 12, SQLite (native tests) / MySQL (Docker).

**Spec:** `docs/superpowers/specs/2026-09-22-1116-saved-search-membership-table-design.md`

**Branch:** `feature/1116-saved-search-membership-table` (already created off `develop`, the spec is its first commit).

## Global Constraints

- `declare(strict_types=1)` in every file; PSR-12; PHPStan level max; PHPMD codesize clean on every touched `src` file; phptramp clean.
- `final readonly class` with constructor promotion for every new service; interfaces injected; no boolean flag parameters; guard clauses; no comment unless the reader would get the code wrong without it.
- Controllers hold no private methods (ThinControllerRule).
- Datetimes are naive UTC: `matched_at` is written from `ClockInterface::now()` (the app's clock is UTC).
- Commit messages: `type(#1116): imperative summary` — e.g. `feat(#1116): …`, `refactor(#1116): …`, `test(#1116): …`. No attribution lines.
- Run from `backend/`: `php bin/phpunit --filter=<Test>` for one test, `composer check` (cs + stan + tramp) and `composer md` before each commit that touches `src`. PHPStan needs a warm cache: `bin/console cache:warmup` once.
- Tests are production code: same naming and standards. ASCII search terms only in DB tests (SQLite `LIKE` folds ASCII case alone).
- Entry fixtures in sweep and controller tests must carry a `createdAt` at least 60 s before the clock the sweep reads, or the settle delay hides them (see Task 6).
- Deviation from spec §10, recorded here: **no black-box e2e test.** `tests/E2e` cannot own an entry without a live feed to ingest, so the "badge count equals unread list length" regression is a kernel test in Task 11 instead. Raise it in the PR if you disagree.

---

## File structure

**Create**
- `backend/src/Entity/SavedSearchEntry.php` — the membership row.
- `backend/migrations/Version20260922160000.php` — table + mark column, MySQL and SQLite.
- `backend/src/Repository/SavedSearchEntryMembershipRepository.php` — `insertMissing()` (the only writer of the table).
- `backend/src/Repository/SavedSearchListQuery.php` — the list read's parameter object (ids, not terms).
- `backend/src/Service/Search/Membership/SavedSearchMatcher.php` — interface.
- `backend/src/Service/Search/Membership/DatabaseSavedSearchMatcher.php`
- `backend/src/Service/Search/Membership/IndexedSavedSearchMatcher.php`
- `backend/src/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcher.php` — capability-keyed choice, no fallback.
- `backend/src/Service/Search/Membership/SweepBudget.php`
- `backend/src/Service/Search/Membership/SweepTally.php` — the run's counters (mutable, private to the sweep).
- `backend/src/Service/Search/Membership/SavedSearchMembershipSweepReport.php`
- `backend/src/Service/Search/Membership/SavedSearchMembershipSweep.php`
- `backend/src/Service/Search/SavedSearchEntries.php` — the combined list (rows + per-card badge map), replaces the list triple.
- `backend/src/Service/Worker/Message/SweepSavedSearchMemberships.php`, `…/Handler/SweepSavedSearchMembershipsHandler.php`

**Modify**
- `backend/src/Entity/SavedSearch.php` — mark column + `matchedUpToEntryId()` / `advanceMatchedUpTo()`.
- `backend/src/Repository/SavedSearchRepository.php` — `findBelowMark()`.
- `backend/src/Repository/EntryRepository.php` — `settledCeilingId()`, `idsBetween()`.
- `backend/src/Repository/SavedSearchEntryRepository.php` — table-backed reads; `LIKE` reads removed at the end.
- `backend/src/Service/Search/Index/IndexSearch.php`, `…/MeilisearchIndex.php` — optional `entryIds` filter.
- `backend/src/Service/Search/SavedSearchMatchIds.php`, `backend/src/Service/Reader/SavedSearchMarkReadService.php`, `backend/src/Service/Mail/Digest/DigestEntryFinder.php` — read the table.
- `backend/src/Controller/Api/SavedSearchEntriesController.php`, `backend/src/Controller/Api/SavedSearchController.php`.
- `backend/src/Service/Maintenance/MaintenanceTick.php`, `MaintenanceTickReport.php`, `backend/src/Service/Worker/WorkerSchedule.php`.
- `backend/config/services.yaml` — matcher alias in, three saved-search aliases out.
- `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`, `backend/tests/Service/Maintenance/MaintenanceTickTest.php`, `backend/tests/Service/Mail/Digest/DigestEntryFinderTest.php`, `backend/tests/Controller/Api/SavedSearchControllerTest.php`, `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`.

**Delete (Task 12)**
- `src/Service/Search/{IndexedSavedSearchEntries,IndexedSavedSearchBadges,IndexedSavedSearchUnreadMatches,SavedSearchEntriesWithFallback,SavedSearchBadgesWithFallback,SavedSearchUnreadMatchesWithFallback,DatabaseSavedSearchEntries,DatabaseSavedSearchBadges,DatabaseSavedSearchUnreadMatches,SavedSearchEntriesInterface,SavedSearchBadgeSource,SavedSearchUnreadMatchSource}.php`
- `src/Repository/{SavedSearchBadgeCandidateRepository,SavedSearchEntryQuery}.php`
- `EntryListRepository::unreadMatchIdsSince()`; the `LIKE` methods on `SavedSearchEntryRepository`.
- Their tests: `tests/Service/Search/{IndexedSavedSearchEntries,IndexedSavedSearchBadges,IndexedSavedSearchUnreadMatches,SavedSearchEntriesWithFallback,SavedSearchBadgesWithFallback,SavedSearchUnreadMatchesWithFallback,DatabaseSavedSearchEntries,DatabaseSavedSearchBadges,DatabaseSavedSearchUnreadMatches}Test.php`, `tests/Repository/{SavedSearchBadgeCandidateRepository,SavedSearchEntryList,SavedSearchUnreadMatchIds}Test.php`.

Sequencing rule: every commit stays green. New reads are added beside the old ones (Task 7), consumers switch one at a time (Tasks 8–11), the old code goes last (Task 12).

---

### Task 1: The `SavedSearchEntry` entity, the mark on `SavedSearch`, and the migration

**Files:**
- Create: `backend/src/Entity/SavedSearchEntry.php`
- Create: `backend/migrations/Version20260922160000.php`
- Create: `backend/src/Repository/SavedSearchEntryMembershipRepository.php` (empty shell for the entity's `repositoryClass`; filled in Task 3)
- Modify: `backend/src/Entity/SavedSearch.php`
- Modify: `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`
- Test: `backend/tests/Entity/SavedSearchTest.php`, `backend/tests/Entity/SavedSearchEntryTest.php`

**Interfaces:**
- Produces: `SavedSearch::matchedUpToEntryId(): int`, `SavedSearch::advanceMatchedUpTo(int $entryId): void` (never moves backwards); `new SavedSearchEntry(SavedSearch $savedSearch, Entry $entry, \DateTimeImmutable $matchedAt)` with `getSavedSearch()`, `getEntry()`, `getMatchedAt()`.

- [ ] **Step 1: Write the failing entity tests**

Append to `backend/tests/Entity/SavedSearchTest.php` (inside the existing class):

```php
    public function testAFreshSearchHasCheckedNoEntryYet(): void
    {
        $search = new SavedSearch($this->user(), 'climate', false);

        self::assertSame(0, $search->matchedUpToEntryId());
    }

    public function testAdvancingTheMarkNeverMovesItBackwards(): void
    {
        $search = new SavedSearch($this->user(), 'climate', false);

        $search->advanceMatchedUpTo(500);
        $search->advanceMatchedUpTo(120);

        self::assertSame(500, $search->matchedUpToEntryId());
    }
```

If the test class has no `user()` helper, add `private function user(): User { return new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z')); }` and the `App\Entity\User` import.

Create `backend/tests/Entity/SavedSearchEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SavedSearchEntryTest extends TestCase
{
    public function testCarriesTheSearchTheEntryAndWhenItMatched(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $search = new SavedSearch($user, 'climate', false);
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid-1',
            'https://example.com/1',
            'Climate report',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $matchedAt = new \DateTimeImmutable('2026-09-22T10:00:00');

        $membership = new SavedSearchEntry($search, $entry, $matchedAt);

        self::assertSame($search, $membership->getSavedSearch());
        self::assertSame($entry, $membership->getEntry());
        self::assertSame($matchedAt, $membership->getMatchedAt());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter='SavedSearchTest|SavedSearchEntryTest'`
Expected: FAIL — `Call to undefined method … matchedUpToEntryId()` and `Class "App\Entity\SavedSearchEntry" not found`.

- [ ] **Step 3: Add the mark to `SavedSearch`**

In `backend/src/Entity/SavedSearch.php`, after the `$includeInDigest` property:

```php
    /**
     * The membership sweep's high-water mark: every entry with an id up to
     * this one has been checked against this search's terms (#1116).
     */
    #[ORM\Column(name: 'matched_up_to_entry_id', options: ['default' => 0])]
    private int $matchedUpToEntryId = 0;
```

After `setIncludeInDigest()`:

```php
    public function matchedUpToEntryId(): int
    {
        return $this->matchedUpToEntryId;
    }

    public function advanceMatchedUpTo(int $entryId): void
    {
        $this->matchedUpToEntryId = max($this->matchedUpToEntryId, $entryId);
    }
```

- [ ] **Step 4: Create the entity and the repository shell**

`backend/src/Entity/SavedSearchEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedSearchEntryMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry a saved search's terms match (#1116). A derived row: the sweep
 * writes it, every reader joins it, and it is safe to rebuild at any time.
 */
#[ORM\Entity(repositoryClass: SavedSearchEntryMembershipRepository::class)]
#[ORM\Table(name: 'saved_search_entry')]
#[ORM\Index(name: 'idx_saved_search_entry_entry', columns: ['entry_id'])]
class SavedSearchEntry
{
    // No `nullable: false` on the identifier join columns — Doctrine forces
    // identifier columns NOT NULL and deprecates stating it (see EntryState).
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: SavedSearch::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private SavedSearch $savedSearch;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(name: 'matched_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $matchedAt;

    public function __construct(SavedSearch $savedSearch, Entry $entry, \DateTimeImmutable $matchedAt)
    {
        $this->savedSearch = $savedSearch;
        $this->entry = $entry;
        $this->matchedAt = $matchedAt;
    }

    public function getSavedSearch(): SavedSearch
    {
        return $this->savedSearch;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getMatchedAt(): \DateTimeImmutable
    {
        return $this->matchedAt;
    }
}
```

`backend/src/Repository/SavedSearchEntryMembershipRepository.php` (shell; Task 3 adds the writer):

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearchEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of saved_search_entry (#1116). Reads live on
 * SavedSearchEntryRepository, which projects entries, not memberships.
 *
 * @extends ServiceEntityRepository<SavedSearchEntry>
 */
final class SavedSearchEntryMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearchEntry::class);
    }
}
```

- [ ] **Step 5: Run the entity tests**

Run: `php bin/phpunit --filter='SavedSearchTest|SavedSearchEntryTest'`
Expected: PASS.

- [ ] **Step 6: Declare the new field and entity to the backup coverage test**

Run: `php bin/phpunit --filter=BackupSchemaCoverageTest`
Expected: FAIL naming `SavedSearch::matchedUpToEntryId` and `App\Entity\SavedSearchEntry` as undeclared.

In `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`:
- In the `SavedSearch::class => [...]` block (around line 223) add:
  `'matchedUpToEntryId' => 'The membership sweep\'s high-water mark (#1116); a restored search starts at 0 and is re-swept.',`
- In `INSTANCE_SCOPED` add:
  `SavedSearchEntry::class => 'A derived saved-search membership row (#1116); rebuilt by the sweep, so a restore carries none.',`
  with `use App\Entity\SavedSearchEntry;`.

If the test names a different constant for "excluded whole entity", use the one it names; the point is that it lists the entity as deliberately absent from backups with a reason.

Run: `php bin/phpunit --filter=BackupSchemaCoverageTest`
Expected: PASS.

- [ ] **Step 7: Generate the migration from the diff, then shape it for both platforms**

Run inside Docker (MySQL is the platform whose FK names you want verbatim):

```bash
docker compose exec php bin/console doctrine:migrations:diff --no-interaction
```

Open the generated file, copy the MySQL statements (the `CREATE TABLE saved_search_entry …`, both `ALTER TABLE … ADD CONSTRAINT FK_… FOREIGN KEY … ON DELETE CASCADE`, and `ALTER TABLE saved_search ADD matched_up_to_entry_id INT DEFAULT 0 NOT NULL`), then **delete the generated file** and create `backend/migrations/Version20260922160000.php` in the two-platform shape the #953 migration (`Version20260913180658.php`) uses:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Persisted saved-search membership (#1116): the saved_search_entry table the
 * sweep fills, and the per-search high-water mark it advances.
 */
final class Version20260922160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add saved_search_entry and saved_search.matched_up_to_entry_id (#1116).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('saved_search_entry'), 'saved_search_entry already exists.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE saved_search_entry (
                    saved_search_id INT NOT NULL,
                    entry_id INT NOT NULL,
                    matched_at DATETIME NOT NULL,
                    INDEX <IDX_NAME_FROM_DIFF_FOR_saved_search_id> (saved_search_id),
                    INDEX idx_saved_search_entry_entry (entry_id),
                    PRIMARY KEY (saved_search_id, entry_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql('ALTER TABLE saved_search_entry ADD CONSTRAINT <FK_NAME_FROM_DIFF> FOREIGN KEY (saved_search_id) REFERENCES saved_search (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE saved_search_entry ADD CONSTRAINT <FK_NAME_FROM_DIFF> FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE saved_search ADD matched_up_to_entry_id INT DEFAULT 0 NOT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE saved_search_entry (
                    matched_at DATETIME NOT NULL,
                    saved_search_id INTEGER NOT NULL,
                    entry_id INTEGER NOT NULL,
                    PRIMARY KEY (saved_search_id, entry_id),
                    CONSTRAINT <FK_NAME_FROM_DIFF> FOREIGN KEY (saved_search_id) REFERENCES saved_search (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                    CONSTRAINT <FK_NAME_FROM_DIFF> FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
                )
                SQL);
            $this->addSql('CREATE INDEX <IDX_NAME_FROM_DIFF_FOR_saved_search_id> ON saved_search_entry (saved_search_id)');
            $this->addSql('CREATE INDEX idx_saved_search_entry_entry ON saved_search_entry (entry_id)');
            $this->addSql('ALTER TABLE saved_search ADD COLUMN matched_up_to_entry_id INTEGER DEFAULT 0 NOT NULL');

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the saved-search membership migration.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE saved_search_entry');
        $this->addSql('ALTER TABLE saved_search DROP COLUMN matched_up_to_entry_id');
    }
}
```

Replace every `<…_FROM_DIFF>` token with the exact identifier the diff printed (Doctrine derives them by hash; hand-written names would fail `doctrine:schema:validate`). There must be no `<` left in the file.

- [ ] **Step 8: Prove the migration on a fresh SQLite and on MySQL**

```bash
rm -f var/migrate-check.sqlite
DATABASE_URL="sqlite:///$PWD/var/migrate-check.sqlite" bin/console doctrine:migrations:migrate --no-interaction --quiet
DATABASE_URL="sqlite:///$PWD/var/migrate-check.sqlite" bin/console doctrine:schema:validate
rm -f var/migrate-check.sqlite
```

Expected: `[OK] The database schema is in sync with the mapping files.`

Then MySQL, against the live dev database (this is the standing "apply new migrations to the live Docker DB" rule):

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: in sync.

- [ ] **Step 9: Gates and commit**

```bash
composer check && composer md && php bin/phpunit
git add src/Entity/SavedSearch.php src/Entity/SavedSearchEntry.php src/Repository/SavedSearchEntryMembershipRepository.php migrations/Version20260922160000.php tests/Entity/SavedSearchTest.php tests/Entity/SavedSearchEntryTest.php tests/Service/Backup/BackupSchemaCoverageTest.php
git commit -m "feat(#1116): add the saved-search membership table and high-water mark"
```

---

### Task 2: `SweepBudget`, `SweepTally`, `SavedSearchMembershipSweepReport`

**Files:**
- Create: `backend/src/Service/Search/Membership/SweepBudget.php`
- Create: `backend/src/Service/Search/Membership/SweepTally.php`
- Create: `backend/src/Service/Search/Membership/SavedSearchMembershipSweepReport.php`
- Test: `backend/tests/Service/Search/Membership/SavedSearchMembershipSweepReportTest.php`, `backend/tests/Service/Search/Membership/SweepBudgetTest.php`

**Interfaces:**
- Produces: `SweepBudget::seconds(int $seconds): self`, `SweepBudget::deadlineFrom(\DateTimeImmutable $start): \DateTimeImmutable`; `SweepTally` with public int `$searchesSwept`, `$entriesScanned`, `$matchesInserted` and `toReport(bool $caughtUp): SavedSearchMembershipSweepReport`; `SavedSearchMembershipSweepReport` with readonly public `int $searchesSwept, int $entriesScanned, int $matchesInserted, bool $caughtUp` and `toArray(): array{searchesSwept:int, entriesScanned:int, matchesInserted:int, caughtUp:bool}`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Service/Search/Membership/SweepBudgetTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Service\Search\Membership\SweepBudget;
use PHPUnit\Framework\TestCase;

final class SweepBudgetTest extends TestCase
{
    public function testTheDeadlineIsTheStartPlusTheBudget(): void
    {
        $start = new \DateTimeImmutable('2026-09-22T10:00:00');

        self::assertEquals(
            new \DateTimeImmutable('2026-09-22T10:00:10'),
            SweepBudget::seconds(10)->deadlineFrom($start),
        );
    }

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SweepBudget::seconds(-1);
    }
}
```

`backend/tests/Service/Search/Membership/SavedSearchMembershipSweepReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Service\Search\Membership\SweepTally;
use PHPUnit\Framework\TestCase;

final class SavedSearchMembershipSweepReportTest extends TestCase
{
    public function testTheTallyBecomesAReportWithStableKeys(): void
    {
        $tally = new SweepTally();
        $tally->searchesSwept = 2;
        $tally->entriesScanned = 1000;
        $tally->matchesInserted = 7;

        self::assertSame(
            ['searchesSwept' => 2, 'entriesScanned' => 1000, 'matchesInserted' => 7, 'caughtUp' => false],
            $tally->toReport(false)->toArray(),
        );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter='SweepBudgetTest|SavedSearchMembershipSweepReportTest'`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the three classes**

`backend/src/Service/Search/Membership/SweepBudget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

/** How long one sweep run may work before it stops and leaves the rest to the next run. */
final readonly class SweepBudget
{
    private function __construct(private int $seconds)
    {
    }

    public static function seconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('A sweep budget cannot be negative.');
        }

        return new self($seconds);
    }

    public function deadlineFrom(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return $start->modify(\sprintf('+%d seconds', $this->seconds));
    }
}
```

`backend/src/Service/Search/Membership/SweepTally.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

/** The counters one sweep run accumulates; the report is their read-only end state. */
final class SweepTally
{
    public int $searchesSwept = 0;
    public int $entriesScanned = 0;
    public int $matchesInserted = 0;

    public function toReport(bool $caughtUp): SavedSearchMembershipSweepReport
    {
        return new SavedSearchMembershipSweepReport(
            $this->searchesSwept,
            $this->entriesScanned,
            $this->matchesInserted,
            $caughtUp,
        );
    }
}
```

`backend/src/Service/Search/Membership/SavedSearchMembershipSweepReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

final readonly class SavedSearchMembershipSweepReport
{
    public function __construct(
        public int $searchesSwept,
        public int $entriesScanned,
        public int $matchesInserted,
        public bool $caughtUp,
    ) {
    }

    /**
     * @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool}
     */
    public function toArray(): array
    {
        return [
            'searchesSwept' => $this->searchesSwept,
            'entriesScanned' => $this->entriesScanned,
            'matchesInserted' => $this->matchesInserted,
            'caughtUp' => $this->caughtUp,
        ];
    }
}
```

- [ ] **Step 4: Run the tests, gates, commit**

Run: `php bin/phpunit --filter='SweepBudgetTest|SavedSearchMembershipSweepReportTest'` — PASS.

```bash
composer check && composer md
git add src/Service/Search/Membership tests/Service/Search/Membership
git commit -m "feat(#1116): add the membership sweep's budget, tally and report"
```

---

### Task 3: Repository support for the sweep

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php`
- Modify: `backend/src/Repository/SavedSearchRepository.php`
- Modify: `backend/src/Repository/SavedSearchEntryMembershipRepository.php`
- Test: `backend/tests/Repository/SavedSearchMembershipSweepRepositoriesTest.php`

**Interfaces:**
- Produces: `EntryRepository::settledCeilingId(\DateTimeImmutable $createdNoLaterThan): int` (0 when no entry qualifies); `EntryRepository::idsBetween(int $afterId, int $upToId, int $limit): list<int>` ascending; `SavedSearchRepository::findBelowMark(int $ceiling): list<SavedSearch>` ordered by mark ASC, id ASC; `SavedSearchEntryMembershipRepository::insertMissing(int $savedSearchId, array $entryIds, \DateTimeImmutable $matchedAt): int` (rows inserted; existing pairs skipped).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Repository/SavedSearchMembershipSweepRepositoriesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Tests\DbTestCase;

final class SavedSearchMembershipSweepRepositoriesTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('sweep@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    public function testTheCeilingIsTheHighestIdCreatedNoLaterThanTheInstant(): void
    {
        $old = $this->entry('a', createdAt: '2026-09-22T09:58:00');
        $this->entry('b', createdAt: '2026-09-22T09:59:30');

        $ceiling = $this->entries()->settledCeilingId(new \DateTimeImmutable('2026-09-22T09:59:00'));

        self::assertSame($old->getId(), $ceiling);
    }

    public function testTheCeilingIsZeroWhenNoEntryIsSettled(): void
    {
        $this->entry('a', createdAt: '2026-09-22T09:59:30');

        self::assertSame(0, $this->entries()->settledCeilingId(new \DateTimeImmutable('2026-09-22T09:59:00')));
    }

    public function testIdsBetweenWalksAscendingAboveTheMarkUpToTheCeilingAndStopsAtTheLimit(): void
    {
        $first = $this->entry('a');
        $second = $this->entry('b');
        $third = $this->entry('c');
        $this->entry('d');

        $ids = $this->entries()->idsBetween((int) $first->getId(), (int) $third->getId(), 1);
        self::assertSame([$second->getId()], $ids);

        $ids = $this->entries()->idsBetween((int) $first->getId(), (int) $third->getId(), 10);
        self::assertSame([$second->getId(), $third->getId()], $ids);
    }

    public function testFindBelowMarkOrdersByMarkThenIdAndSkipsCaughtUpSearches(): void
    {
        $caughtUp = $this->search('caught up');
        $caughtUp->advanceMatchedUpTo(100);
        $behind = $this->search('behind');
        $behind->advanceMatchedUpTo(40);
        $fresh = $this->search('fresh');
        $this->em->flush();

        $due = $this->searches()->findBelowMark(100);

        self::assertSame(
            [$fresh->getId(), $behind->getId()],
            array_map(static fn (SavedSearch $s): ?int => $s->getId(), $due),
        );
    }

    public function testInsertMissingAddsOnlyTheAbsentPairsAndReportsHowMany(): void
    {
        $search = $this->search('climate');
        $one = $this->entry('a');
        $two = $this->entry('b');
        $this->em->flush();
        $matchedAt = new \DateTimeImmutable('2026-09-22T10:00:00');

        $first = $this->memberships()->insertMissing((int) $search->getId(), [(int) $one->getId()], $matchedAt);
        $second = $this->memberships()->insertMissing(
            (int) $search->getId(),
            [(int) $one->getId(), (int) $two->getId()],
            $matchedAt,
        );

        self::assertSame(1, $first);
        self::assertSame(1, $second);
        self::assertSame(2, $this->memberships()->count(['savedSearch' => $search]));
    }

    public function testInsertMissingWithNoIdsInsertsNothing(): void
    {
        $search = $this->search('climate');
        $this->em->flush();

        self::assertSame(0, $this->memberships()->insertMissing((int) $search->getId(), [], new \DateTimeImmutable()));
    }

    private function entry(string $guid, string $createdAt = '2026-07-01T00:00:00Z'): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function search(string $term): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->em->persist($search);

        return $search;
    }

    private function entries(): EntryRepository
    {
        $repository = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $repository);

        return $repository;
    }

    private function searches(): SavedSearchRepository
    {
        $repository = self::getContainer()->get(SavedSearchRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $repository);

        return $repository;
    }

    private function memberships(): SavedSearchEntryMembershipRepository
    {
        $repository = self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchEntryMembershipRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit --filter=SavedSearchMembershipSweepRepositoriesTest`
Expected: FAIL — undefined methods.

- [ ] **Step 3: Implement the four methods**

`EntryRepository` — add after `entriesAfterId()`:

```php
    /**
     * The highest entry id created no later than $createdNoLaterThan — the
     * membership sweep's ceiling, so entries the engine may not have indexed
     * yet wait for the next run (#1116).
     */
    public function settledCeilingId(\DateTimeImmutable $createdNoLaterThan): int
    {
        $ceiling = $this->createQueryBuilder('e')
            ->select('MAX(e.id)')
            ->andWhere('e.createdAt <= :createdNoLaterThan')
            ->setParameter('createdNoLaterThan', $createdNoLaterThan)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $ceiling;
    }

    /**
     * @return list<int> ascending ids in ($afterId, $upToId], at most $limit
     */
    public function idsBetween(int $afterId, int $upToId, int $limit): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.id > :afterId')
            ->andWhere('e.id <= :upToId')
            ->setParameter('afterId', $afterId)
            ->setParameter('upToId', $upToId)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
```

`SavedSearchRepository` — add:

```php
    /**
     * Every search that has not yet checked every entry up to $ceiling, the
     * furthest-behind first so a new search's backfill is served before
     * steady-state work (#1116).
     *
     * @return list<SavedSearch>
     */
    public function findBelowMark(int $ceiling): array
    {
        /** @var list<SavedSearch> $searches */
        $searches = $this->createQueryBuilder('s')
            ->andWhere('s.matchedUpToEntryId < :ceiling')
            ->setParameter('ceiling', $ceiling)
            ->orderBy('s.matchedUpToEntryId', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $searches;
    }
```

`SavedSearchEntryMembershipRepository` — replace the shell body with:

```php
    /** Rows per INSERT: 3 placeholders each, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearchEntry::class);
    }

    /**
     * Inserts the (search, entry) pairs that do not exist yet and answers how
     * many it added. A re-run over the same chunk therefore adds nothing,
     * which is what makes the sweep's per-chunk transaction restartable.
     *
     * @param list<int> $entryIds
     */
    public function insertMissing(int $savedSearchId, array $entryIds, \DateTimeImmutable $matchedAt): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $missing = array_values(array_diff($entryIds, $this->existingEntryIds($savedSearchId, $entryIds)));
        foreach (array_chunk($missing, self::INSERT_ROWS) as $rows) {
            $this->insertRows($savedSearchId, $rows, $matchedAt);
        }

        return \count($missing);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    private function existingEntryIds(int $savedSearchId, array $entryIds): array
    {
        /** @var list<int|string> $existing */
        $existing = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM saved_search_entry WHERE saved_search_id = ? AND entry_id IN (?)',
            [$savedSearchId, $entryIds],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        return array_map(intval(...), $existing);
    }

    /** @param list<int> $entryIds */
    private function insertRows(int $savedSearchId, array $entryIds, \DateTimeImmutable $matchedAt): void
    {
        $parameters = [];
        foreach ($entryIds as $entryId) {
            array_push($parameters, $savedSearchId, $entryId, $matchedAt->format('Y-m-d H:i:s'));
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO saved_search_entry (saved_search_id, entry_id, matched_at) VALUES '
            . implode(', ', array_fill(0, \count($entryIds), '(?, ?, ?)')),
            $parameters,
        );
    }
```

Add `use Doctrine\DBAL\ArrayParameterType;` and `use Doctrine\DBAL\ParameterType;`.

- [ ] **Step 4: Run, gates, commit**

Run: `php bin/phpunit --filter=SavedSearchMembershipSweepRepositoriesTest` — PASS.

```bash
composer check && composer md
git add src/Repository/EntryRepository.php src/Repository/SavedSearchRepository.php src/Repository/SavedSearchEntryMembershipRepository.php tests/Repository/SavedSearchMembershipSweepRepositoriesTest.php
git commit -m "feat(#1116): add the ceiling, id walk, due-search and membership-insert reads the sweep needs"
```

---

### Task 4: The matcher — interface, DB implementation, capability-keyed choice

**Files:**
- Create: `backend/src/Service/Search/Membership/SavedSearchMatcher.php`
- Create: `backend/src/Service/Search/Membership/DatabaseSavedSearchMatcher.php`
- Create: `backend/src/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcher.php`
- Modify: `backend/config/services.yaml`
- Test: `backend/tests/Service/Search/Membership/DatabaseSavedSearchMatcherTest.php`, `backend/tests/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcherTest.php`

**Interfaces:**
- Produces: `interface SavedSearchMatcher { /** @param list<SavedSearchTerm> $searches @param list<int> $candidateEntryIds @return array<int, list<int>> */ public function matchingIds(array $searches, array $candidateEntryIds): array; }` — every requested search id is a key, `[]` when nothing matched; `@throws SearchEngineUnavailableException` (engine implementation only).
- Consumes: `SavedSearchTerm` (`$id`, `$terms`), `SearchTermsPredicateBuilder::build(QueryBuilder, SearchTerms, string $prefix): string`, `SearchEngineCapability::isConfigured()`.
- Task 5 produces `IndexedSavedSearchMatcher`; this task's `EngineOrDatabaseSavedSearchMatcher` depends on it, so **create Task 5's class first if you execute out of order** — in order, do Task 5 before wiring the alias (Step 6 here says when).

- [ ] **Step 1: Write the failing DB matcher test**

`backend/tests/Service/Search/Membership/DatabaseSavedSearchMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Membership\DatabaseSavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchMode;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/** ASCII terms only: SQLite's LIKE folds ASCII case alone. */
final class DatabaseSavedSearchMatcherTest extends DbTestCase
{
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    public function testAnswersEveryRequestedSearchWithItsMatchesAmongTheCandidates(): void
    {
        $climate = $this->entry('a', 'Climate report');
        $rocket = $this->entry('b', 'Rocket launch', summary: 'Climate of Mars');
        $this->entry('c', 'Nothing here');

        $matches = $this->matcher()->matchingIds(
            [$this->search(1, 'climate'), $this->search(2, 'rocket'), $this->search(3, 'zebra')],
            [(int) $climate->getId(), (int) $rocket->getId()],
        );

        self::assertSame([
            1 => [$climate->getId(), $rocket->getId()],
            2 => [$rocket->getId()],
            3 => [],
        ], $matches);
    }

    public function testAMatchOutsideTheCandidatesIsNotReturned(): void
    {
        $inside = $this->entry('a', 'Climate report');
        $this->entry('b', 'Climate too');

        $matches = $this->matcher()->matchingIds([$this->search(1, 'climate')], [(int) $inside->getId()]);

        self::assertSame([1 => [$inside->getId()]], $matches);
    }

    public function testWholeWordModeRejectsASubstringHit(): void
    {
        $word = $this->entry('a', 'A punk show');
        $this->entry('b', 'Punktlich arrival');

        $matches = $this->matcher()->matchingIds(
            [$this->search(1, 'punk', SearchMode::WholeWord)],
            [(int) $word->getId(), (int) $this->entry('c', 'Spunky')->getId()],
        );

        self::assertSame([1 => [$word->getId()]], $matches);
    }

    public function testNoCandidatesAnswersEverySearchWithNothing(): void
    {
        self::assertSame([1 => [], 2 => []], $this->matcher()->matchingIds(
            [$this->search(1, 'climate'), $this->search(2, 'rocket')],
            [],
        ));
    }

    public function testMoreSearchesThanOneStatementHoldsAreStillAllAnswered(): void
    {
        $entry = $this->entry('a', 'term07 and term30');
        $searches = [];
        for ($i = 1; $i <= 30; $i++) {
            $searches[] = $this->search($i, \sprintf('term%02d', $i));
        }

        $matches = $this->matcher()->matchingIds($searches, [(int) $entry->getId()]);

        self::assertCount(30, $matches);
        self::assertSame([$entry->getId()], $matches[7]);
        self::assertSame([$entry->getId()], $matches[30]);
        self::assertSame([], $matches[8]);
    }

    private function matcher(): DatabaseSavedSearchMatcher
    {
        return new DatabaseSavedSearchMatcher($this->em, new SearchTermsPredicateBuilder());
    }

    private function search(int $id, string $term, SearchMode $mode = SearchMode::Substring): SavedSearchTerm
    {
        return new SavedSearchTerm($id, SearchTerms::fromTermAndMode($term, $mode));
    }

    private function entry(string $guid, string $title, ?string $summary = null): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $entry->setSummary($summary);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit --filter=DatabaseSavedSearchMatcherTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the interface and the DB matcher**

`backend/src/Service/Search/Membership/SavedSearchMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\SavedSearchTerm;

/**
 * Which of a handful of candidate entries each saved search matches — the one
 * question the membership sweep asks, on whichever engine the host has (#1116).
 */
interface SavedSearchMatcher
{
    /**
     * @param list<SavedSearchTerm> $searches
     * @param list<int>             $candidateEntryIds
     *
     * @return array<int, list<int>> every requested saved-search id => the candidate ids it matches
     *
     * @throws SearchEngineUnavailableException
     */
    public function matchingIds(array $searches, array $candidateEntryIds): array;
}
```

`backend/src/Service/Search/Membership/DatabaseSavedSearchMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Entity\Entry;
use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\SavedSearchTerm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The LIKE matcher: one statement per chunk answers every search at once —
 * the WHERE keeps the candidates any search matches, a CASE per search flags
 * which. Title and summary only, exact terms; the recall the database host
 * has always had.
 */
final readonly class DatabaseSavedSearchMatcher implements SavedSearchMatcher
{
    /**
     * Each search binds up to four parameters per term twice (the WHERE and
     * its CASE), so this keeps one statement under SQLite's historical 999.
     */
    private const int SEARCHES_PER_STATEMENT = 25;

    public function __construct(
        private EntityManagerInterface $em,
        private SearchTermsPredicateBuilder $predicates,
    ) {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        $matches = self::nothingFor($searches);
        if ($candidateEntryIds === []) {
            return $matches;
        }

        foreach (array_chunk($searches, self::SEARCHES_PER_STATEMENT) as $chunk) {
            $matches = $this->matchesInOneStatement($chunk, $candidateEntryIds) + $matches;
        }

        return $matches;
    }

    /**
     * @param list<SavedSearchTerm> $searches
     * @param list<int>             $candidateEntryIds
     *
     * @return array<int, list<int>>
     */
    private function matchesInOneStatement(array $searches, array $candidateEntryIds): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e.id')
            ->from(Entry::class, 'e')
            ->andWhere('e.id IN (:candidates)')
            ->setParameter('candidates', $candidateEntryIds)
            ->orderBy('e.id', 'ASC');

        $anyMatches = [];
        foreach ($searches as $position => $search) {
            $qb->addSelect(\sprintf(
                'CASE WHEN %s THEN 1 ELSE 0 END AS match%d',
                $this->predicates->build($qb, $search->terms, 'flag' . $position . 'term'),
                $position,
            ));
            $anyMatches[] = $this->predicates->build($qb, $search->terms, 'any' . $position . 'term');
        }
        $qb->andWhere('(' . implode(' OR ', $anyMatches) . ')');

        return $this->collect($searches, $qb);
    }

    /**
     * @param list<SavedSearchTerm> $searches
     *
     * @return array<int, list<int>>
     */
    private function collect(array $searches, QueryBuilder $qb): array
    {
        $matches = self::nothingFor($searches);
        // Doctrine types the mapped id; a CASE is raw, and MySQL hands it back as a string.
        /** @var list<array{id: int, ...<string, int|string>}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            foreach ($searches as $position => $search) {
                if ((int) $row['match' . $position] === 1) {
                    $matches[$search->id][] = (int) $row['id'];
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<SavedSearchTerm> $searches
     *
     * @return array<int, list<int>>
     */
    private static function nothingFor(array $searches): array
    {
        return array_fill_keys(array_map(static fn (SavedSearchTerm $s): int => $s->id, $searches), []);
    }
}
```

- [ ] **Step 4: Run the DB matcher test**

Run: `php bin/phpunit --filter=DatabaseSavedSearchMatcherTest` — PASS.

If the whole-word test fails on `Punktlich`: the predicate builder's whole-word mode is what the badge scan already uses; check the term is `'punk'` with `SearchMode::WholeWord`, not a trailing-space string.

- [ ] **Step 5: Write the failing chooser test**

`backend/tests/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Repository\SearchTermsPredicateBuilder;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\DatabaseSavedSearchMatcher;
use App\Service\Search\Membership\EngineOrDatabaseSavedSearchMatcher;
use App\Service\Search\Membership\IndexedSavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchEngineCapability;
use App\Service\Search\SearchTerms;
use App\Tests\Service\Search\FakeMultiSearchReader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class EngineOrDatabaseSavedSearchMatcherTest extends TestCase
{
    public function testAnUnconfiguredEngineMeansTheDatabaseMatcherAnswers(): void
    {
        $engine = new FakeMultiSearchReader([], new SearchEngineUnavailableException('never asked'));
        $em = $this->createStub(EntityManagerInterface::class);

        $matcher = new EngineOrDatabaseSavedSearchMatcher(
            new IndexedSavedSearchMatcher($engine),
            new DatabaseSavedSearchMatcher($em, new SearchTermsPredicateBuilder()),
            new SearchEngineCapability('', ''),
        );

        self::assertSame([1 => []], $matcher->matchingIds([$this->search(1)], []));
        self::assertSame([], $engine->receivedRounds);
    }

    public function testAConfiguredEngineAnswersAndItsFailurePropagatesWithoutFallback(): void
    {
        $engine = new FakeMultiSearchReader([], new SearchEngineUnavailableException('down'));
        $em = $this->createStub(EntityManagerInterface::class);

        $matcher = new EngineOrDatabaseSavedSearchMatcher(
            new IndexedSavedSearchMatcher($engine),
            new DatabaseSavedSearchMatcher($em, new SearchTermsPredicateBuilder()),
            new SearchEngineCapability('http://meilisearch:7700', 'key'),
        );

        $this->expectException(SearchEngineUnavailableException::class);

        $matcher->matchingIds([$this->search(1)], [10]);
    }

    private function search(int $id): SavedSearchTerm
    {
        return new SavedSearchTerm($id, SearchTerms::fromInput('climate'));
    }
}
```

- [ ] **Step 6: Implement the chooser (after Task 5's `IndexedSavedSearchMatcher` exists) and wire it**

`backend/src/Service/Search/Membership/EngineOrDatabaseSavedSearchMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Service\Search\SearchEngineCapability;

/**
 * The engine when one is configured, the database otherwise — and never the
 * database as a fallback when the engine fails: a table filled by two
 * matchers with different recall would be sticky and wrong, so an engine
 * failure stops the sweep for this run instead (see SavedSearchMembershipSweep).
 */
final readonly class EngineOrDatabaseSavedSearchMatcher implements SavedSearchMatcher
{
    public function __construct(
        private IndexedSavedSearchMatcher $engine,
        private DatabaseSavedSearchMatcher $database,
        private SearchEngineCapability $capability,
    ) {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        $matcher = $this->capability->isConfigured() ? $this->engine : $this->database;

        return $matcher->matchingIds($searches, $candidateEntryIds);
    }
}
```

In `backend/config/services.yaml`, next to the `App\Service\Search\Index\SearchIndexReader:` alias, add:

```yaml
    App\Service\Search\Membership\SavedSearchMatcher: '@App\Service\Search\Membership\EngineOrDatabaseSavedSearchMatcher'
```

- [ ] **Step 7: Run both tests, gates, commit**

Run: `php bin/phpunit --filter='DatabaseSavedSearchMatcherTest|EngineOrDatabaseSavedSearchMatcherTest'` — PASS (Task 5 must be done for the second one to compile; if executing strictly in order, commit Step 1–4 now and finish Steps 5–7 right after Task 5).

```bash
composer check && composer md
git add src/Service/Search/Membership config/services.yaml tests/Service/Search/Membership
git commit -m "feat(#1116): add the saved-search matcher with its database implementation"
```

---

### Task 5: The Meilisearch matcher and the `id IN` filter

**Files:**
- Modify: `backend/src/Service/Search/Index/IndexSearch.php`
- Modify: `backend/src/Service/Search/Index/MeilisearchIndex.php` (`filterFor`)
- Create: `backend/src/Service/Search/Membership/IndexedSavedSearchMatcher.php`
- Test: `backend/tests/Service/Search/Index/MeilisearchIndexTest.php`, `backend/tests/Service/Search/Membership/IndexedSavedSearchMatcherTest.php`

**Interfaces:**
- Produces: `IndexSearch::amongEntries(SearchTerms $terms, array $entryIds, int $limit): self` (feedIds `[]`, cursor `null`, `$entryIds` set); `IndexSearch` gains `public ?array $entryIds = null` as a fifth constructor parameter (list<int>|null); `MeilisearchIndex::filterFor` emits `id IN [..]` for it.
- Produces: `IndexedSavedSearchMatcher implements SavedSearchMatcher`, one `findMany()` per call, one query per search, `limit = count($candidateEntryIds)`.

- [ ] **Step 1: Write the failing filter test**

Add to `backend/tests/Service/Search/Index/MeilisearchIndexTest.php` after `testNoCursorAddsNoCursorPredicate`:

```php
    public function testASearchAmongEntriesFiltersByIdAloneAndSendsTheirCountAsTheLimit(): void
    {
        $client = $this->clientCapturing(new MockResponse('{"hits":[]}'));
        $this->index($client)->find(IndexSearch::amongEntries(SearchTerms::fromInput('widgets'), [5, 9, 12], 3));

        $query = $this->capturedJsonObject();
        self::assertSame('id IN [5,9,12]', $query['filter']);
        self::assertSame(3, $query['limit']);
    }

    public function testAnEntryIdFilterJoinsTheFeedFilterWithAnd(): void
    {
        $client = $this->clientCapturing(new MockResponse('{"hits":[]}'));
        $this->index($client)->find(new IndexSearch(SearchTerms::fromInput('widgets'), [3], null, 20, [5, 9]));

        self::assertSame('feedId IN [3] AND id IN [5,9]', $this->capturedJsonObject()['filter']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit --filter=MeilisearchIndexTest`
Expected: FAIL — `amongEntries` undefined / too many arguments.

- [ ] **Step 3: Extend `IndexSearch` and `filterFor`**

`IndexSearch` constructor becomes:

```php
    /**
     * @param list<int>      $feedIds  the feeds the caller may see; never asked
     *                                 of the engine when empty
     * @param list<int>|null $entryIds when set, only these entries are candidates
     */
    public function __construct(
        public SearchTerms $terms,
        public array $feedIds,
        public ?EntryCursor $cursor,
        public int $limit,
        public ?array $entryIds = null,
    ) {
    }

    /**
     * A membership probe (#1116): which of exactly these entries match, on
     * every feed — the sweep matches globally and gates by subscription at
     * read time.
     *
     * @param list<int> $entryIds
     */
    public static function amongEntries(SearchTerms $terms, array $entryIds, int $limit): self
    {
        return new self($terms, [], null, $limit, $entryIds);
    }
```

`MeilisearchIndex::filterFor` becomes:

```php
    private function filterFor(IndexSearch $search): string
    {
        $clauses = [];
        if ($search->feedIds !== []) {
            $clauses[] = sprintf('feedId IN [%s]', implode(',', $search->feedIds));
        }
        if ($search->entryIds !== null) {
            $clauses[] = sprintf('id IN [%s]', implode(',', $search->entryIds));
        }
        if ($search->cursor !== null) {
            // Keyset pagination on (effectiveDate, id) descending: everything
            // strictly before the cursor's date, plus same-date rows with a
            // smaller id — the compound predicate the probe confirmed Meilisearch
            // accepts verbatim as a plain filter string.
            $clauses[] = sprintf(
                '(effectiveDate < %1$d OR (effectiveDate = %1$d AND id < %2$d))',
                $search->cursor->sortInstant->getTimestamp(),
                $search->cursor->id,
            );
        }

        return implode(' AND ', $clauses);
    }
```

- [ ] **Step 4: Run the index tests**

Run: `php bin/phpunit --filter=MeilisearchIndexTest` — PASS, including the pre-existing feed/cursor cases.

- [ ] **Step 5: Write the failing matcher test**

`backend/tests/Service/Search/Membership/IndexedSavedSearchMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Membership\IndexedSavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchTerms;
use App\Tests\Service\Search\FakeMultiSearchReader;
use PHPUnit\Framework\TestCase;

final class IndexedSavedSearchMatcherTest extends TestCase
{
    public function testAsksOneQueryPerSearchAmongExactlyTheCandidatesAndMapsAnswersBySearchId(): void
    {
        $engine = new FakeMultiSearchReader([[
            new IndexMatches([12, 5], []),
            new IndexMatches([], []),
        ]]);

        $matches = (new IndexedSavedSearchMatcher($engine))->matchingIds(
            [$this->search(7, 'climate'), $this->search(9, 'rocket')],
            [5, 9, 12],
        );

        self::assertSame([7 => [12, 5], 9 => []], $matches);
        self::assertCount(1, $engine->receivedRounds);
        $round = $engine->receivedRounds[0];
        self::assertCount(2, $round);
        self::assertInstanceOf(IndexSearch::class, $round[0]);
        self::assertSame([5, 9, 12], $round[0]->entryIds);
        self::assertSame([], $round[0]->feedIds);
        self::assertSame(3, $round[0]->limit);
        self::assertSame('rocket', $round[1]->terms->terms[0]);
    }

    public function testNoCandidatesNeverAsksTheEngine(): void
    {
        $engine = new FakeMultiSearchReader();

        $matches = (new IndexedSavedSearchMatcher($engine))->matchingIds([$this->search(7, 'climate')], []);

        self::assertSame([7 => []], $matches);
        self::assertSame([], $engine->receivedRounds);
    }

    public function testAnUnavailableEnginePropagates(): void
    {
        $engine = new FakeMultiSearchReader([], new SearchEngineUnavailableException('down'));

        $this->expectException(SearchEngineUnavailableException::class);

        (new IndexedSavedSearchMatcher($engine))->matchingIds([$this->search(7, 'climate')], [1]);
    }

    private function search(int $id, string $term): SavedSearchTerm
    {
        return new SavedSearchTerm($id, SearchTerms::fromInput($term));
    }
}
```

- [ ] **Step 6: Implement the engine matcher**

`backend/src/Service/Search/Membership/IndexedSavedSearchMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Service\Search\Index\IndexMatches;
use App\Service\Search\Index\IndexSearch;
use App\Service\Search\Index\SearchIndexReader;
use App\Service\Search\SavedSearchTerm;

/**
 * The engine matcher: one multi-search per chunk, one query per search, each
 * filtered to exactly the candidate ids. Content and typo matches included —
 * the recall the engine host has always had.
 */
final readonly class IndexedSavedSearchMatcher implements SavedSearchMatcher
{
    public function __construct(private SearchIndexReader $index)
    {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        if ($searches === [] || $candidateEntryIds === []) {
            return array_fill_keys(array_map(static fn (SavedSearchTerm $s): int => $s->id, $searches), []);
        }

        $results = $this->index->findMany(array_map(
            static fn (SavedSearchTerm $search): IndexSearch => IndexSearch::amongEntries(
                $search->terms,
                $candidateEntryIds,
                \count($candidateEntryIds),
            ),
            $searches,
        ));

        $matches = [];
        foreach ($searches as $position => $search) {
            $matches[$search->id] = $results[$position]->entryIds;
        }

        return $matches;
    }
}
```

- [ ] **Step 7: Run, then finish Task 4 Steps 5–7 if they were deferred, gates, commit**

Run: `php bin/phpunit --filter='IndexedSavedSearchMatcherTest|MeilisearchIndexTest|EngineOrDatabaseSavedSearchMatcherTest'` — PASS.

```bash
composer check && composer md
git add src/Service/Search/Index/IndexSearch.php src/Service/Search/Index/MeilisearchIndex.php src/Service/Search/Membership tests/Service/Search/Index/MeilisearchIndexTest.php tests/Service/Search/Membership
git commit -m "feat(#1116): add the Meilisearch matcher and the engine's id filter"
```

---

### Task 6: `SavedSearchMembershipSweep`

**Files:**
- Create: `backend/src/Service/Search/Membership/SavedSearchMembershipSweep.php`
- Test: `backend/tests/Service/Search/Membership/SavedSearchMembershipSweepTest.php`, `backend/tests/Support/RecordingSavedSearchMatcher.php`

**Interfaces:**
- Consumes: Tasks 2–4 (`SweepBudget`, `SweepTally`, `EntryRepository::settledCeilingId/idsBetween`, `SavedSearchRepository::findBelowMark`, `SavedSearchEntryMembershipRepository::insertMissing`, `SavedSearchMatcher`), `SavedSearchTerms::termOf(SavedSearch): SavedSearchTerm` (exists), `Psr\Clock\ClockInterface`.
- Produces: `sweep(SweepBudget $budget): SavedSearchMembershipSweepReport` and `sweepOne(SavedSearch $search, SweepBudget $budget): SavedSearchMembershipSweepReport`. Constants `CHUNK = 500`, `SETTLE_SECONDS = 60`.

- [ ] **Step 1: Write the recording matcher test double**

`backend/tests/Support/RecordingSavedSearchMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\SavedSearchTerm;

/**
 * Answers from a fixed map of saved-search id => matching entry ids, keeps
 * every call it received, and can be told to fail — so a sweep test can
 * assert exactly which candidates were asked about and what happened after.
 */
final class RecordingSavedSearchMatcher implements SavedSearchMatcher
{
    /** @var list<array{searchIds: list<int>, candidates: list<int>}> */
    public array $calls = [];

    /**
     * @param array<int, list<int>> $matchesBySearchId
     */
    public function __construct(
        private readonly array $matchesBySearchId = [],
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        $this->calls[] = [
            'searchIds' => array_map(static fn (SavedSearchTerm $s): int => $s->id, $searches),
            'candidates' => $candidateEntryIds,
        ];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $matches = [];
        foreach ($searches as $search) {
            $matches[$search->id] = array_values(array_intersect(
                $this->matchesBySearchId[$search->id] ?? [],
                $candidateEntryIds,
            ));
        }

        return $matches;
    }
}
```

- [ ] **Step 2: Write the failing sweep tests**

`backend/tests/Service/Search/Membership/SavedSearchMembershipSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\TickingClock;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class SavedSearchMembershipSweepTest extends DbTestCase
{
    private const string NOW = '2026-09-22T10:00:00';

    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('sweep@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    public function testMatchesEverySettledEntryInsertsTheRowsAndAdvancesTheMark(): void
    {
        $search = $this->search('climate');
        $hit = $this->entry('a');
        $miss = $this->entry('b');
        $matcher = new RecordingSavedSearchMatcher([(int) $search->getId() => [(int) $hit->getId()]]);

        $report = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame(
            ['searchesSwept' => 1, 'entriesScanned' => 2, 'matchesInserted' => 1, 'caughtUp' => true],
            $report->toArray(),
        );
        self::assertSame([[$hit->getId(), $miss->getId()]], array_column($matcher->calls, 'candidates'));
        self::assertSame($miss->getId(), $this->reload($search)->matchedUpToEntryId());
        self::assertSame([$hit->getId()], $this->memberEntryIds($search));
    }

    public function testAnEntryYoungerThanTheSettleDelayWaitsForTheNextRun(): void
    {
        $search = $this->search('climate');
        $settled = $this->entry('a', createdAt: '2026-09-22T09:58:59');
        $young = $this->entry('b', createdAt: '2026-09-22T09:59:01');
        $matcher = new RecordingSavedSearchMatcher();

        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame([[$settled->getId()]], array_column($matcher->calls, 'candidates'));
        self::assertSame($settled->getId(), $this->reload($search)->matchedUpToEntryId());
        self::assertLessThan($young->getId(), $this->reload($search)->matchedUpToEntryId());
    }

    public function testSearchesAtTheSameMarkAreAskedTogetherInOneCall(): void
    {
        $first = $this->search('climate');
        $second = $this->search('rocket');
        $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher();

        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertCount(1, $matcher->calls);
        self::assertSame([$first->getId(), $second->getId()], $matcher->calls[0]['searchIds']);
    }

    public function testTheFurthestBehindGroupIsServedFirst(): void
    {
        $behind = $this->search('climate');
        $ahead = $this->search('rocket');
        $this->entry('a');
        $last = $this->entry('b');
        $ahead->advanceMatchedUpTo((int) $last->getId() - 1);
        $this->em->flush();
        $matcher = new RecordingSavedSearchMatcher();

        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame([$behind->getId()], $matcher->calls[0]['searchIds']);
        self::assertSame([$ahead->getId()], $matcher->calls[1]['searchIds']);
    }

    public function testASpentBudgetStopsBetweenChunksAndTheNextRunResumesAtTheMark(): void
    {
        $search = $this->search('climate');
        $ids = [];
        for ($i = 0; $i < SavedSearchMembershipSweep::CHUNK + 1; $i++) {
            $ids[] = (int) $this->entry('e' . $i)->getId();
        }
        // Every reading moves the clock 6 s: the deadline is read once, then
        // once per chunk, so a 10 s budget allows exactly one chunk.
        $clock = new TickingClock(new \DateTimeImmutable(self::NOW), 6);
        $matcher = new RecordingSavedSearchMatcher();

        $first = $this->sweep($matcher, $clock)->sweep(SweepBudget::seconds(10));

        self::assertFalse($first->caughtUp);
        self::assertSame(SavedSearchMembershipSweep::CHUNK, $first->entriesScanned);
        self::assertSame($ids[SavedSearchMembershipSweep::CHUNK - 1], $this->reload($search)->matchedUpToEntryId());

        $second = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertTrue($second->caughtUp);
        self::assertSame(1, $second->entriesScanned);
        self::assertSame(end($ids), $this->reload($search)->matchedUpToEntryId());
    }

    public function testAnUnavailableEngineLeavesTheMarkAloneInsertsNothingAndWarnsOnce(): void
    {
        $search = $this->search('climate');
        $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher([], new SearchEngineUnavailableException('down'));
        $logger = new RecordingLogger();

        $report = $this->sweep($matcher, logger: $logger)->sweep(SweepBudget::seconds(10));

        self::assertFalse($report->caughtUp);
        self::assertSame(0, $report->matchesInserted);
        self::assertSame(0, $this->reload($search)->matchedUpToEntryId());
        self::assertSame([], $this->memberEntryIds($search));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testAMatcherFailureAfterTheInsertLeavesNoRowAndNoMovedMark(): void
    {
        $search = $this->search('climate');
        $hit = $this->entry('a');
        $matcher = new class((int) $search->getId(), (int) $hit->getId()) implements SavedSearchMatcher {
            public function __construct(private readonly int $searchId, private readonly int $hitId)
            {
            }

            public function matchingIds(array $searches, array $candidateEntryIds): array
            {
                return [$this->searchId => [$this->hitId]];
            }
        };
        $memberships = new class($this->em) extends SavedSearchEntryMembershipRepository {
            public function insertMissing(int $savedSearchId, array $entryIds, \DateTimeImmutable $matchedAt): int
            {
                parent::insertMissing($savedSearchId, $entryIds, $matchedAt);

                throw new \RuntimeException('simulated failure after the insert');
            }
        };

        try {
            $this->sweep($matcher, memberships: $memberships)->sweep(SweepBudget::seconds(10));
            self::fail('The failure must propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->reload($search)->matchedUpToEntryId());
        self::assertSame([], $this->memberEntryIds($search));
    }

    public function testSweepOneWalksOnlyThatSearch(): void
    {
        $only = $this->search('climate');
        $other = $this->search('rocket');
        $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher();

        $report = $this->sweep($matcher)->sweepOne($only, SweepBudget::seconds(8));

        self::assertSame(1, $report->searchesSwept);
        self::assertSame([[$only->getId()]], array_column($matcher->calls, 'searchIds'));
        self::assertSame(0, $this->reload($other)->matchedUpToEntryId());
    }

    public function testNothingToDoIsOneQueryAndACaughtUpReport(): void
    {
        $matcher = new RecordingSavedSearchMatcher();

        $report = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertTrue($report->caughtUp);
        self::assertSame([], $matcher->calls);
    }

    private function sweep(
        SavedSearchMatcher $matcher,
        ?ClockInterface $clock = null,
        ?SavedSearchEntryMembershipRepository $memberships = null,
        ?RecordingLogger $logger = null,
    ): SavedSearchMembershipSweep {
        $searches = self::getContainer()->get(SavedSearchRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $searches);
        $entries = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entries);
        $membershipRepository = $memberships ?? self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchEntryMembershipRepository::class, $membershipRepository);

        return new SavedSearchMembershipSweep(
            $searches,
            $entries,
            $membershipRepository,
            $matcher,
            $this->em,
            $clock ?? new MockClock(self::NOW),
            $logger ?? new NullLogger(),
        );
    }

    private function search(string $term): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->em->persist($search);
        $this->em->flush();

        return $search;
    }

    private function entry(string $guid, string $createdAt = '2026-09-22T09:00:00'): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function reload(SavedSearch $search): SavedSearch
    {
        $this->em->clear();
        $reloaded = $this->em->find(SavedSearch::class, $search->getId());
        self::assertInstanceOf(SavedSearch::class, $reloaded);

        return $reloaded;
    }

    /** @return list<int> */
    private function memberEntryIds(SavedSearch $search): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM saved_search_entry WHERE saved_search_id = ? ORDER BY entry_id',
            [$search->getId()],
        );

        return array_map(intval(...), $ids);
    }
}
```

Check `tests/Support/RecordingLogger.php` for the property that holds records (`$records` with `level`/`message` keys is the assumption above); adjust the two assertions to its real shape. If the anonymous repository subclass fails because the repository is `final`, drop `final` from `SavedSearchEntryMembershipRepository` (repositories in this tree that are extended by tests do the same) and say so in the commit body.

- [ ] **Step 3: Run to verify failure**

Run: `php bin/phpunit --filter=SavedSearchMembershipSweepTest`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement the sweep**

`backend/src/Service/Search/Membership/SavedSearchMembershipSweep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Entity\SavedSearch;
use App\Repository\EntryRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\SavedSearchTerms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills saved_search_entry incrementally (#1116): every search carries a
 * high-water mark, the run walks entry ids above it up to a settled ceiling
 * in chunks, asks the matcher which ids match, inserts the rows and advances
 * the mark. Searches at the same mark walk together, so once caught up a run
 * is one matcher call per chunk for all of them. Budget-bounded; the mark is
 * where the next run continues.
 */
final readonly class SavedSearchMembershipSweep
{
    public const int CHUNK = 500;

    /** How old an entry must be before it is matched, so an engine's async indexing has landed. */
    public const int SETTLE_SECONDS = 60;

    public function __construct(
        private SavedSearchRepository $searches,
        private EntryRepository $entries,
        private SavedSearchEntryMembershipRepository $memberships,
        private SavedSearchMatcher $matcher,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $ceiling = $this->ceiling();

        return $this->walkGroups($this->searches->findBelowMark($ceiling), $ceiling, $budget);
    }

    public function sweepOne(SavedSearch $search, SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $ceiling = $this->ceiling();
        $due = $search->matchedUpToEntryId() < $ceiling ? [$search] : [];

        return $this->walkGroups($due, $ceiling, $budget);
    }

    private function ceiling(): int
    {
        return $this->entries->settledCeilingId(
            $this->clock->now()->modify(\sprintf('-%d seconds', self::SETTLE_SECONDS)),
        );
    }

    /**
     * @param list<SavedSearch> $due
     */
    private function walkGroups(array $due, int $ceiling, SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $tally = new SweepTally();
        $deadline = $budget->deadlineFrom($this->clock->now());
        foreach (self::groupedByMark($due) as $group) {
            $tally->searchesSwept += \count($group);
            if (!$this->walkGroup($group, $ceiling, $deadline, $tally)) {
                return $tally->toReport(false);
            }
        }

        return $tally->toReport(true);
    }

    /**
     * @param list<SavedSearch> $due already ordered by mark, then id
     *
     * @return list<list<SavedSearch>>
     */
    private static function groupedByMark(array $due): array
    {
        $groups = [];
        foreach ($due as $search) {
            $groups[$search->matchedUpToEntryId()][] = $search;
        }

        return array_values($groups);
    }

    /**
     * Walks one mark group to the ceiling; false when the budget or the
     * engine stopped it short.
     *
     * @param non-empty-list<SavedSearch> $group
     */
    private function walkGroup(array $group, int $ceiling, \DateTimeImmutable $deadline, SweepTally $tally): bool
    {
        $mark = $group[0]->matchedUpToEntryId();
        while ($mark < $ceiling) {
            if ($this->clock->now() >= $deadline) {
                return false;
            }
            $chunk = $this->entries->idsBetween($mark, $ceiling, self::CHUNK);
            if ($chunk === []) {
                $this->advance($group, $ceiling);

                return true;
            }
            if (!$this->applyChunk($group, $chunk, $tally)) {
                return false;
            }
            $mark = $chunk[array_key_last($chunk)];
        }

        return true;
    }

    /**
     * @param non-empty-list<SavedSearch> $group
     * @param non-empty-list<int>         $chunk
     */
    private function applyChunk(array $group, array $chunk, SweepTally $tally): bool
    {
        try {
            $matches = $this->matcher->matchingIds(array_map(SavedSearchTerms::termOf(...), $group), $chunk);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; the membership sweep stops here and retries next run.', [
                'exception' => $e,
            ]);

            return false;
        }

        $now = $this->clock->now();
        $lastId = $chunk[array_key_last($chunk)];
        // Insert and mark advance share one transaction: a run that dies here
        // leaves neither half-inserted rows nor a skipped chunk.
        $this->em->wrapInTransaction(function () use ($group, $matches, $now, $lastId, $tally): void {
            foreach ($group as $search) {
                $searchId = (int) $search->getId();
                $tally->matchesInserted += $this->memberships->insertMissing($searchId, $matches[$searchId] ?? [], $now);
            }
            $this->advance($group, $lastId);
        });
        $tally->entriesScanned += \count($chunk);

        return true;
    }

    /** @param non-empty-list<SavedSearch> $group */
    private function advance(array $group, int $entryId): void
    {
        foreach ($group as $search) {
            $search->advanceMatchedUpTo($entryId);
        }
        $this->em->flush();
    }
}
```

Notes for the implementer:
- `wrapInTransaction` runs `flush()` inside the transaction through `advance()`; if the closure throws, Doctrine rolls back and rethrows — that is the atomicity test.
- The "chunk empty" branch (`idsBetween` returns nothing although `mark < ceiling`): ids above the mark were pruned. Advancing straight to the ceiling is correct: there is nothing to match.
- PHPMD: the class has 7 fields and 8 methods; if `ExcessiveClassComplexity` or `TooManyMethods` fires, move `groupedByMark` and `advance` into a small `MarkGroup` value object (`readonly class MarkGroup { public function __construct(public array $searches) {} public function mark(): int; public function advanceTo(int $id): void; }`) — keep the tests as they are.

- [ ] **Step 5: Run, gates, commit**

Run: `php bin/phpunit --filter=SavedSearchMembershipSweepTest` — PASS.

```bash
composer check && composer md
git add src/Service/Search/Membership/SavedSearchMembershipSweep.php tests/Service/Search/Membership/SavedSearchMembershipSweepTest.php tests/Support/RecordingSavedSearchMatcher.php
git commit -m "feat(#1116): add the saved-search membership sweep"
```

---

### Task 7: Table-backed reads on `SavedSearchEntryRepository` (beside the old ones)

**Files:**
- Create: `backend/src/Repository/SavedSearchListQuery.php`
- Modify: `backend/src/Repository/SavedSearchEntryRepository.php` (add methods; keep the old ones for now)
- Test: `backend/tests/Repository/SavedSearchMembershipReadsTest.php`

**Interfaces:**
- Produces: `SavedSearchListQuery(int $userId, list<int> $savedSearchIds, bool $onlyUnread = false, ?EntryCursor $cursor = null, int $limit = EntryQuery::DEFAULT_LIMIT)` with public readonly `$limit` clamped like `SavedSearchEntryQuery`.
- Produces on `SavedSearchEntryRepository`:
  - `listMembers(SavedSearchListQuery $query): list<EntryListRow>`
  - `unreadMemberIdsBySavedSearch(int $userId, array $savedSearchIds): array<int, list<int>>` — every requested id a key
  - `unreadMemberIdsUpTo(int $userId, array $savedSearchIds, \DateTimeImmutable $until): list<int>`
  - `unreadMemberIdsSince(int $savedSearchId, int $userId, \DateTimeImmutable $since): list<int>` — newest first
  - `firstMatchingSavedSearchIds(array $entryIds, array $savedSearchIdsInSidebarOrder): array<int, int>`
- The repository's constructor gains `DuplicateCollapseDql $collapse` (the `SearchTermsPredicateBuilder` dependency stays until Task 12).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Repository/SavedSearchMembershipReadsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;
use App\Tests\DbTestCase;

/**
 * Every saved-search read over the membership table (#1116): the list, the
 * badge ids, the mark-read set, the digest window and the per-card badge.
 */
final class SavedSearchMembershipReadsTest extends DbTestCase
{
    private User $user;
    private User $stranger;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->em->persist($this->stranger);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();
    }

    public function testTheListShowsMembersNewestFirstAndAnEntryInTwoSearchesOnce(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $both = $this->entry('a', '2026-07-10T00:00:00Z');
        $onlyRocket = $this->entry('b', '2026-07-09T00:00:00Z');
        $this->entry('c', '2026-07-08T00:00:00Z');
        $this->member($climate, $both);
        $this->member($rocket, $both);
        $this->member($rocket, $onlyRocket);

        $rows = $this->repo()->listMembers($this->query([$climate, $rocket]));

        self::assertSame([$both->getId(), $onlyRocket->getId()], $this->ids($rows));
    }

    public function testOnlyUnreadReturnsTheNewestUnreadRowsWhenTheNewestMembersAreRead(): void
    {
        $climate = $this->search('climate');
        $newestRead = $this->entry('a', '2026-07-10T00:00:00Z');
        $secondRead = $this->entry('b', '2026-07-09T00:00:00Z');
        $olderUnread = $this->entry('c', '2026-07-08T00:00:00Z');
        foreach ([$newestRead, $secondRead, $olderUnread] as $entry) {
            $this->member($climate, $entry);
        }
        $this->hide($newestRead);
        $this->hide($secondRead);

        $rows = $this->repo()->listMembers($this->query([$climate], onlyUnread: true, limit: 2));

        self::assertSame([$olderUnread->getId()], $this->ids($rows));
    }

    public function testTheCursorWalksTheStreamAcrossAPageBoundary(): void
    {
        $climate = $this->search('climate');
        $first = $this->entry('a', '2026-07-10T00:00:00Z');
        $second = $this->entry('b', '2026-07-09T00:00:00Z');
        $third = $this->entry('c', '2026-07-08T00:00:00Z');
        foreach ([$first, $second, $third] as $entry) {
            $this->member($climate, $entry);
        }

        $page = $this->repo()->listMembers($this->query([$climate], limit: 2));
        self::assertSame([$first->getId(), $second->getId()], $this->ids($page));

        $next = $this->repo()->listMembers($this->query(
            [$climate],
            limit: 2,
            cursor: new EntryCursor($second->getEffectiveDate(), (int) $second->getId()),
        ));
        self::assertSame([$third->getId()], $this->ids($next));
    }

    public function testAMemberOfAnUnsubscribedFeedIsNotListed(): void
    {
        $climate = $this->search('climate');
        $otherFeed = new Feed('https://elsewhere.example.com/feed.xml');
        $this->em->persist($otherFeed);
        $this->em->flush();
        $foreign = $this->entry('a', '2026-07-10T00:00:00Z', $otherFeed);
        $this->member($climate, $foreign);

        self::assertSame([], $this->repo()->listMembers($this->query([$climate])));
    }

    public function testAForeignSearchIdYieldsNothing(): void
    {
        $theirs = new SavedSearch($this->stranger, 'climate', false);
        $this->em->persist($theirs);
        $this->em->flush();
        $entry = $this->entry('a', '2026-07-10T00:00:00Z');
        $this->member($theirs, $entry);

        self::assertSame([], $this->repo()->listMembers($this->query([$theirs])));
        self::assertSame([(int) $theirs->getId() => []], $this->repo()->unreadMemberIdsBySavedSearch(
            (int) $this->user->getId(),
            [(int) $theirs->getId()],
        ));
    }

    public function testNoSearchIdsListsNothing(): void
    {
        self::assertSame([], $this->repo()->listMembers($this->query([])));
    }

    public function testDuplicateCopiesCollapseToTheLowestIdInTheList(): void
    {
        $climate = $this->search('climate');
        $original = $this->entry('a', '2026-07-10T00:00:00Z', urlHash: 'same');
        $copy = $this->entry('b', '2026-07-10T00:00:00Z', urlHash: 'same');
        $this->member($climate, $original);
        $this->member($climate, $copy);

        $rows = $this->repo()->listMembers($this->query([$climate]));

        self::assertSame([$original->getId()], $this->ids($rows));
    }

    public function testBadgeIdsGroupUnreadMembersBySearchWithEveryRequestedKeyPresent(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $empty = $this->search('zebra');
        $unread = $this->entry('a', '2026-07-10T00:00:00Z');
        $read = $this->entry('b', '2026-07-09T00:00:00Z');
        $this->member($climate, $unread);
        $this->member($climate, $read);
        $this->member($rocket, $unread);
        $this->hide($read);

        $ids = $this->repo()->unreadMemberIdsBySavedSearch(
            (int) $this->user->getId(),
            [(int) $climate->getId(), (int) $rocket->getId(), (int) $empty->getId()],
        );

        self::assertSame([
            (int) $climate->getId() => [$unread->getId()],
            (int) $rocket->getId() => [$unread->getId()],
            (int) $empty->getId() => [],
        ], $ids);
    }

    public function testBadgeIdsEqualTheUnreadListForTheSameFixture(): void
    {
        $climate = $this->search('climate');
        $newestRead = $this->entry('a', '2026-07-10T00:00:00Z');
        $older = $this->entry('b', '2026-07-09T00:00:00Z');
        $oldest = $this->entry('c', '2026-07-08T00:00:00Z');
        foreach ([$newestRead, $older, $oldest] as $entry) {
            $this->member($climate, $entry);
        }
        $this->hide($newestRead);

        $badge = $this->repo()->unreadMemberIdsBySavedSearch((int) $this->user->getId(), [(int) $climate->getId()]);
        $list = $this->repo()->listMembers($this->query([$climate], onlyUnread: true));

        self::assertCount(\count($badge[(int) $climate->getId()]), $list);
        self::assertEqualsCanonicalizing($badge[(int) $climate->getId()], $this->ids($list));
    }

    public function testTheMarkReadSetHonoursUntil(): void
    {
        $climate = $this->search('climate');
        $newer = $this->entry('a', '2026-07-10T00:00:00Z');
        $older = $this->entry('b', '2026-07-08T00:00:00Z');
        $this->member($climate, $newer);
        $this->member($climate, $older);

        $ids = $this->repo()->unreadMemberIdsUpTo(
            (int) $this->user->getId(),
            [(int) $climate->getId()],
            new \DateTimeImmutable('2026-07-09T00:00:00Z'),
        );

        self::assertSame([$older->getId()], $ids);
    }

    public function testTheDigestWindowIsUnreadMembersNewerThanSinceNewestFirst(): void
    {
        $climate = $this->search('climate');
        $newest = $this->entry('a', '2026-07-10T00:00:00Z');
        $inWindow = $this->entry('b', '2026-07-09T00:00:00Z');
        $tooOld = $this->entry('c', '2026-07-05T00:00:00Z');
        $readInWindow = $this->entry('d', '2026-07-09T12:00:00Z');
        foreach ([$newest, $inWindow, $tooOld, $readInWindow] as $entry) {
            $this->member($climate, $entry);
        }
        $this->hide($readInWindow);

        $ids = $this->repo()->unreadMemberIdsSince(
            (int) $climate->getId(),
            (int) $this->user->getId(),
            new \DateTimeImmutable('2026-07-08T00:00:00Z'),
        );

        self::assertSame([$newest->getId(), $inWindow->getId()], $ids);
    }

    public function testThePerCardBadgeIsTheFirstMatchingSearchInSidebarOrder(): void
    {
        $climate = $this->search('climate');
        $rocket = $this->search('rocket');
        $both = $this->entry('a', '2026-07-10T00:00:00Z');
        $rocketOnly = $this->entry('b', '2026-07-09T00:00:00Z');
        $none = $this->entry('c', '2026-07-08T00:00:00Z');
        $this->member($climate, $both);
        $this->member($rocket, $both);
        $this->member($rocket, $rocketOnly);

        $badges = $this->repo()->firstMatchingSavedSearchIds(
            [(int) $both->getId(), (int) $rocketOnly->getId(), (int) $none->getId()],
            [(int) $rocket->getId(), (int) $climate->getId()],
        );

        self::assertSame([
            (int) $both->getId() => (int) $rocket->getId(),
            (int) $rocketOnly->getId() => (int) $rocket->getId(),
        ], $badges);
    }

    /** @param list<SavedSearch> $searches */
    private function query(
        array $searches,
        bool $onlyUnread = false,
        int $limit = 50,
        ?EntryCursor $cursor = null,
    ): SavedSearchListQuery {
        return new SavedSearchListQuery(
            userId: (int) $this->user->getId(),
            savedSearchIds: array_map(static fn (SavedSearch $s): int => (int) $s->getId(), $searches),
            onlyUnread: $onlyUnread,
            cursor: $cursor,
            limit: $limit,
        );
    }

    private function search(string $term): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->em->persist($search);
        $this->em->flush();

        return $search;
    }

    private function entry(string $guid, string $effectiveDate, ?Feed $feed = null, ?string $urlHash = null): Entry
    {
        $entry = new Entry(
            $feed ?? $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
            $urlHash,
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function member(SavedSearch $search, Entry $entry): void
    {
        $this->em->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));
        $this->em->flush();
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-11T00:00:00Z'));
        $this->em->persist($state);
        $this->em->flush();
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int|null>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): ?int => $row->entry->getId(), $rows);
    }

    private function repo(): SavedSearchEntryRepository
    {
        $repo = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repo);

        return $repo;
    }
}
```

If `Entry`'s constructor does not accept `$urlHash` as the seventh argument in this checkout, set it through the entity's setter instead and keep the test's intent (two entries share a `urlHash`).

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit --filter=SavedSearchMembershipReadsTest`
Expected: FAIL — `SavedSearchListQuery` not found.

- [ ] **Step 3: Create the query object**

`backend/src/Repository/SavedSearchListQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;

/**
 * Everything one combined saved-search read needs (#1116): the caller, the
 * searches by id — membership is a table now, so the read needs no terms —
 * and the page.
 */
final readonly class SavedSearchListQuery
{
    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    /**
     * @param list<int> $savedSearchIds
     */
    public function __construct(
        public int $userId,
        public array $savedSearchIds,
        public bool $onlyUnread = false,
        public ?EntryCursor $cursor = null,
        int $limit = EntryQuery::DEFAULT_LIMIT,
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }
}
```

- [ ] **Step 4: Add the reads to the repository**

In `SavedSearchEntryRepository`: add `DuplicateCollapseDql $collapse` to the constructor (keep the existing two dependencies), import `App\Entity\SavedSearchEntry`, `App\Entity\Subscription`, `App\Entity\EntryState`, and add these methods (leave every existing method untouched):

```php
    /**
     * The combined list over the membership table: every member of any of the
     * caller's searches, newest first, keyset-paged, the unread test inside
     * the same statement as the LIMIT. EXISTS rather than a join, so an entry
     * in several searches is one row without a DISTINCT.
     *
     * @return list<EntryListRow>
     */
    public function listMembers(SavedSearchListQuery $query): array
    {
        if ($query->savedSearchIds === []) {
            return [];
        }

        $qb = $this->newestFirst($this->rowQueryBuilder($query->userId))
            ->setMaxResults($query->limit)
            ->setParameter('searchIds', $query->savedSearchIds);
        $applyMembership = static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        };
        $applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyMembership, $query->userId);

        if ($query->onlyUnread) {
            $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
        }

        $this->applyCursor($qb, $query->cursor, EntryListSort::PublishedDate);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): EntryListRow => $this->rowHydrator->hydrate($row), $rows);
    }

    /**
     * Saved-search id => the ids of its unread members the caller may see —
     * one query for every badge. Every requested id keeps its key.
     *
     * @param list<int> $savedSearchIds
     *
     * @return array<int, list<int>>
     */
    public function unreadMemberIdsBySavedSearch(int $userId, array $savedSearchIds): array
    {
        $idsBySearch = array_fill_keys($savedSearchIds, []);
        if ($savedSearchIds === []) {
            return $idsBySearch;
        }

        $qb = $this->unreadEntriesQueryBuilder($userId)
            ->select('e.id AS id', 'ss.id AS searchId')
            ->join(SavedSearchEntry::class, 'sse', 'ON', 'sse.entry = e')
            ->join('sse.savedSearch', 'ss')
            ->andWhere('ss.id IN (:searchIds)')
            ->andWhere('ss.user = :user')
            ->setParameter('searchIds', $savedSearchIds)
            ->orderBy('e.id', 'ASC');
        $this->collapse->apply($qb, static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        }, $userId);

        /** @var list<array{id: int, searchId: int}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            $idsBySearch[(int) $row['searchId']][] = (int) $row['id'];
        }

        return $idsBySearch;
    }

    /**
     * The ids the combined mark-read flips: every unread member of any of the
     * given searches no newer than $until, subscription-gated and collapsed.
     *
     * @param list<int> $savedSearchIds
     *
     * @return list<int>
     */
    public function unreadMemberIdsUpTo(int $userId, array $savedSearchIds, \DateTimeImmutable $until): array
    {
        if ($savedSearchIds === []) {
            return [];
        }

        $qb = $this->unreadEntriesQueryBuilder($userId)
            ->select('e.id')
            ->andWhere('e.effectiveDate <= :until')
            ->setParameter('until', $until)
            ->setParameter('searchIds', $savedSearchIds);
        $applyMembership = static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        };
        $applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyMembership, $userId);

        return $this->scalarIds($qb);
    }

    /**
     * One search's unread members newer than $since, newest first — the
     * digest's window (#636).
     *
     * @return list<int>
     */
    public function unreadMemberIdsSince(int $savedSearchId, int $userId, \DateTimeImmutable $since): array
    {
        $qb = $this->unreadEntriesQueryBuilder($userId)
            ->select('e.id')
            ->andWhere('e.effectiveDate > :since')
            ->setParameter('since', $since)
            ->setParameter('searchIds', [$savedSearchId]);
        $applyMembership = static function (QueryBuilder $qb, EntryAliases $aliases): void {
            $qb->andWhere(self::memberOfAnySearch($aliases));
        };
        $applyMembership($qb, EntryAliases::primary());
        $this->collapse->apply($qb, $applyMembership, $userId);

        return $this->scalarIds($this->newestFirst($qb));
    }

    /**
     * Entry id => the first of the given searches (in the order given — the
     * sidebar's) that it is a member of. Entries in none are absent.
     *
     * @param list<int> $entryIds
     * @param list<int> $savedSearchIdsInSidebarOrder
     *
     * @return array<int, int>
     */
    public function firstMatchingSavedSearchIds(array $entryIds, array $savedSearchIdsInSidebarOrder): array
    {
        if ($entryIds === [] || $savedSearchIdsInSidebarOrder === []) {
            return [];
        }

        /** @var list<array{entryId: int, searchId: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(sse.entry) AS entryId', 'IDENTITY(sse.savedSearch) AS searchId')
            ->from(SavedSearchEntry::class, 'sse')
            ->andWhere('sse.entry IN (:entryIds)')
            ->andWhere('sse.savedSearch IN (:searchIds)')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('searchIds', $savedSearchIdsInSidebarOrder)
            ->getQuery()
            ->getScalarResult();

        $rank = array_flip($savedSearchIdsInSidebarOrder);
        $first = [];
        foreach ($rows as $row) {
            $entryId = (int) $row['entryId'];
            $searchId = (int) $row['searchId'];
            if (!isset($first[$entryId]) || $rank[$searchId] < $rank[$first[$entryId]]) {
                $first[$entryId] = $searchId;
            }
        }

        return $first;
    }

    /**
     * "This entry is a member of one of :searchIds, and that search belongs
     * to :user." Alias-parameterised so the collapse subquery can apply the
     * same scope to its own entry alias; both parameters are bound once on
     * the outer builder, which the subquery shares.
     */
    private static function memberOfAnySearch(EntryAliases $aliases): string
    {
        $member = 'member' . ucfirst($aliases->entry);
        $search = 'search' . ucfirst($aliases->entry);

        return \sprintf(
            'EXISTS (SELECT 1 FROM %s %s JOIN %s.savedSearch %s WHERE %s.entry = %s AND %s.id IN (:searchIds) AND %s.user = :user)',
            SavedSearchEntry::class,
            $member,
            $member,
            $search,
            $member,
            $aliases->entry,
            $search,
            $search,
        );
    }
```

If `IDENTITY()` on a composite-keyed association's own column errors in `firstMatchingSavedSearchIds`, join the associations instead: `->join('sse.entry', 'e')->join('sse.savedSearch', 'ss')->select('e.id AS entryId', 'ss.id AS searchId')`.

- [ ] **Step 5: Run, gates, commit**

Run: `php bin/phpunit --filter=SavedSearchMembershipReadsTest` — PASS. Then `php bin/phpunit --filter='SavedSearchEntryListTest|SavedSearchUnreadMatchIdsTest'` — still PASS (old reads untouched).

```bash
composer check && composer md
git add src/Repository/SavedSearchListQuery.php src/Repository/SavedSearchEntryRepository.php tests/Repository/SavedSearchMembershipReadsTest.php
git commit -m "feat(#1116): read saved-search members from the membership table"
```

---

### Task 8: The combined list reads the table

**Files:**
- Create: `backend/src/Service/Search/SavedSearchEntries.php`
- Modify: `backend/src/Controller/Api/SavedSearchEntriesController.php`
- Test: `backend/tests/Service/Search/SavedSearchEntriesTest.php`, `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php`

**Interfaces:**
- Produces: `SavedSearchEntries::list(SavedSearchListQuery $query): SavedSearchEntriesResult` — rows from `listMembers`, `savedSearchIds` from `firstMatchingSavedSearchIds(rowIds, $query->savedSearchIds)`.
- Consumes: `SavedSearchRepository::findForUser(int): list<SavedSearch>` for the ids (sidebar order).

- [ ] **Step 1: Write the failing service test**

`backend/tests/Service/Search/SavedSearchEntriesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SavedSearchListQuery;
use App\Service\Search\SavedSearchEntries;
use App\Tests\DbTestCase;

final class SavedSearchEntriesTest extends DbTestCase
{
    public function testAnswersTheRowsAndTheFirstMatchingSearchPerRow(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($user);
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $climate = new SavedSearch($user, 'climate', false);
        $rocket = new SavedSearch($user, 'rocket', false);
        $this->em->persist($climate);
        $this->em->persist($rocket);
        $entry = new Entry(
            $feed,
            'a',
            'https://example.com/a',
            'Climate rocket',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();
        $matchedAt = new \DateTimeImmutable('2026-09-22T10:00:00');
        $this->em->persist(new SavedSearchEntry($climate, $entry, $matchedAt));
        $this->em->persist(new SavedSearchEntry($rocket, $entry, $matchedAt));
        $this->em->flush();

        $service = self::getContainer()->get(SavedSearchEntries::class);
        self::assertInstanceOf(SavedSearchEntries::class, $service);
        $result = $service->list(new SavedSearchListQuery(
            (int) $user->getId(),
            [(int) $rocket->getId(), (int) $climate->getId()],
        ));

        self::assertCount(1, $result->rows);
        self::assertSame($entry->getId(), $result->rows[0]->entry->getId());
        self::assertSame([(int) $entry->getId() => (int) $rocket->getId()], $result->savedSearchIds);
        self::assertSame(1, $result->matchCount);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit --filter=SavedSearchEntriesTest` — FAIL, class not found.

- [ ] **Step 3: Implement the service and switch the controller**

`backend/src/Service/Search/SavedSearchEntries.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\SavedSearchListQuery;

/**
 * The combined saved-search list (#769) over the membership table (#1116):
 * the page of rows and, per row, the first of the caller's searches (in
 * sidebar order) it belongs to — the badge the card shows.
 */
final readonly class SavedSearchEntries
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    public function list(SavedSearchListQuery $query): SavedSearchEntriesResult
    {
        $rows = $this->entries->listMembers($query);
        $entryIds = array_map(static fn (EntryListRow $row): int => (int) $row->entry->getId(), $rows);

        return new SavedSearchEntriesResult(
            rows: $rows,
            savedSearchIds: $this->entries->firstMatchingSavedSearchIds($entryIds, $query->savedSearchIds),
        );
    }
}
```

`SavedSearchEntriesController`: replace `SavedSearchTerms $terms` and `SavedSearchEntriesInterface $entries` with `SavedSearchRepository $savedSearches` and `SavedSearchEntries $entries`; `list()` becomes:

```php
        $userId = (int) $user->getId();
        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: array_map(
                static fn (SavedSearch $s): int => (int) $s->getId(),
                $this->savedSearches->findForUser($userId),
            ),
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
        );
        $result = $this->entries->list($query);

        return new JsonResponse(SavedSearchPage::of(
            $result->withRows($this->categoryLoader->loadInto($result->rows)),
            $query->limit,
        ));
```

Update the imports (`App\Entity\SavedSearch`, `App\Repository\SavedSearchListQuery`, `App\Repository\SavedSearchRepository`, `App\Service\Search\SavedSearchEntries`; drop `SavedSearchEntryQuery`, `SavedSearchEntriesInterface`, `SavedSearchTerms`).

- [ ] **Step 4: Make the controller test create memberships**

`SavedSearchEntriesControllerTest` today relies on the `LIKE` path finding entries by title. For each test that expects an entry to be listed, persist a `SavedSearchEntry($savedSearch, $entry, $matchedAt)` after creating both (add a `private function member(SavedSearch $search, Entry $entry): void` helper mirroring Task 7's). Keep every assertion; only the fixture gains the rows. Do not stub the sweep here — the membership rows are the fixture.

Run: `php bin/phpunit --filter='SavedSearchEntriesTest|SavedSearchEntriesControllerTest'` — PASS.

- [ ] **Step 5: Gates, commit**

```bash
composer check && composer md
git add src/Service/Search/SavedSearchEntries.php src/Controller/Api/SavedSearchEntriesController.php tests/Service/Search/SavedSearchEntriesTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php
git commit -m "refactor(#1116): serve the combined saved-search list from the membership table"
```

---

### Task 9: Badges read the table; `create()` sweeps the new search

**Files:**
- Modify: `backend/src/Service/Search/SavedSearchMatchIds.php`
- Modify: `backend/src/Controller/Api/SavedSearchController.php`
- Test: `backend/tests/Controller/Api/SavedSearchControllerTest.php`

**Interfaces:**
- `SavedSearchMatchIds::forAll(array $savedSearches, int $userId): array<int, list<int>>` and `forOne()` keep their signatures and the `#[WithSpan]` (TracedServiceMethodsTest pins `forAll`); the dependency becomes `SavedSearchEntryRepository`.
- `SavedSearchController::create()` gains `SavedSearchMembershipSweep $sweep`; after `flush()` it calls `$this->sweep->sweepOne($savedSearch, SweepBudget::seconds(self::CREATE_SWEEP_BUDGET_SECONDS))` with `private const int CREATE_SWEEP_BUDGET_SECONDS = 8;`.

- [ ] **Step 1: Switch `SavedSearchMatchIds`**

```php
final readonly class SavedSearchMatchIds
{
    public function __construct(private SavedSearchEntryRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, list<int>> saved-search id => unread member entry ids
     */
    #[WithSpan]
    public function forAll(array $savedSearches, int $userId): array
    {
        return $this->entries->unreadMemberIdsBySavedSearch(
            $userId,
            array_map(static fn (SavedSearch $s): int => (int) $s->getId(), $savedSearches),
        );
    }

    /**
     * A batch of one answers one list under the search's id; this unwraps it.
     *
     * @return list<int>
     */
    public function forOne(SavedSearch $savedSearch, int $userId): array
    {
        return array_merge(...$this->forAll([$savedSearch], $userId));
    }
}
```

Rewrite the class docblock's second sentence to "Read from the membership table (#1116), so the set is exactly what opening the search lists — every search in one query."

- [ ] **Step 2: Switch the controller**

Add `private SavedSearchMembershipSweep $sweep` to the constructor and the constant; in `create()`, inside the `if ($savedSearch === null)` block after `$this->em->flush();`:

```php
            $this->sweep->sweepOne($savedSearch, SweepBudget::seconds(self::CREATE_SWEEP_BUDGET_SECONDS));
```

Imports: `App\Service\Search\Membership\SavedSearchMembershipSweep`, `App\Service\Search\Membership\SweepBudget`.

- [ ] **Step 3: Run the controller test and fix the fixture's clock**

Run: `php bin/phpunit --filter=SavedSearchControllerTest`

`testCreateListWithUnreadMatchIdsAndDelete` expects `unreadEntryIds` right after create. That now depends on the synchronous `sweepOne` seeing the entry, which needs `createdAt <= now - 60 s` against the container's real clock. Inspect the test's entry fixture: if its `createdAt` is a fixed past date (`2026-07-…`), it already qualifies; if it is `new \DateTimeImmutable()` / "now", change it to `new \DateTimeImmutable('-1 day')`. Same for `testWholeWordAndSubstringMatchIdsAreIndependentAcrossTheList`.

Expected: PASS. If `TracedServiceMethodsTest` fails, the `#[WithSpan]` attribute was dropped — restore it.

- [ ] **Step 4: Gates, commit**

```bash
composer check && composer md
git add src/Service/Search/SavedSearchMatchIds.php src/Controller/Api/SavedSearchController.php tests/Controller/Api/SavedSearchControllerTest.php
git commit -m "refactor(#1116): read badges from the membership table and backfill a new search on create"
```

---

### Task 10: Mark-read and the digest read the table

**Files:**
- Modify: `backend/src/Service/Reader/SavedSearchMarkReadService.php`
- Modify: `backend/src/Service/Mail/Digest/DigestEntryFinder.php`
- Test: `backend/tests/Service/Mail/Digest/DigestEntryFinderTest.php`, `backend/tests/Controller/Api/SavedSearchEntriesControllerTest.php` (mark-read case)

**Interfaces:**
- `SavedSearchMarkReadService(SavedSearchRepository $savedSearches, SavedSearchEntryRepository $entries, BulkEntryReadMarker $readMarker)`; `mark(User $user, \DateTimeImmutable $until): void` unchanged.
- `DigestEntryFinder(SavedSearchEntryRepository $members, EntryListRepository $entries)`; `matchesSince(SavedSearch, int, \DateTimeImmutable): DigestSearchMatches` unchanged.

- [ ] **Step 1: Rewrite `SavedSearchMarkReadService`**

```php
final readonly class SavedSearchMarkReadService
{
    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchEntryRepository $entries,
        private BulkEntryReadMarker $readMarker,
    ) {
    }

    public function mark(User $user, \DateTimeImmutable $until): void
    {
        $userId = (int) $user->getId();
        $searchIds = array_map(
            static fn (SavedSearch $s): int => (int) $s->getId(),
            $this->savedSearches->findForUser($userId),
        );

        $this->readMarker->markRead($userId, $this->entries->unreadMemberIdsUpTo($userId, $searchIds, $until));
    }
}
```

Docblock: "Marks read every unread member of any of the caller's saved searches no newer than $until — the same rows the combined unread list shows (#1116). Per-entry state rows on purpose: a search spans feeds, so a per-search watermark would leave the entry unread in its feed list."

- [ ] **Step 2: Rewrite `DigestEntryFinder`**

```php
final readonly class DigestEntryFinder
{
    /** The most entries one saved search contributes to a single digest. */
    public const int PER_SEARCH = 10;

    public function __construct(
        private SavedSearchEntryRepository $members,
        private EntryListRepository $entries,
    ) {
    }

    public function matchesSince(SavedSearch $search, int $userId, \DateTimeImmutable $since): DigestSearchMatches
    {
        $ids = $this->members->unreadMemberIdsSince((int) $search->getId(), $userId, $since);
        if ($ids === []) {
            return new DigestSearchMatches([], 0);
        }

        // Hydrate only the newest PER_SEARCH rows, not the whole match set: a wide
        // window can match hundreds, and building every heavy list row to show ten
        // would time the request out (#636). The ids arrive newest-first, so the
        // head is the newest; totalCount stays the full pre-cap count for "+N more".
        $newestIds = \array_slice($ids, 0, self::PER_SEARCH);

        return new DigestSearchMatches($this->entries->rowsByIdsForUser($newestIds, $userId), \count($ids));
    }
}
```

Drop the `SearchMode`, `SearchTerms`, `EntrySearchQuery` imports; add `App\Repository\SavedSearchEntryRepository`.

- [ ] **Step 3: Convert `DigestEntryFinderTest` to real rows**

`SavedSearchEntryRepository` is `final`, so the old mock of `unreadMatchIdsSince` cannot be replaced by a mock. Turn the test into a `DbTestCase`: persist a user, a feed, a subscription, a saved search, twelve member entries with `effectiveDate` inside the window (newest first), one member outside it, one read member inside it; then assert `matchesSince()` returns `PER_SEARCH` (10) rows, the newest ten by id, and `totalCount === 12`. Keep the "no matches → empty, totalCount 0" case. Construct the finder with the two repositories from the container.

Run: `php bin/phpunit --filter='DigestEntryFinderTest|SavedSearchEntriesControllerTest|DigestComposerTest|SendDueDigestsTest'` — PASS. If any digest test built the finder with the old single-argument constructor, update the call.

- [ ] **Step 4: Gates, commit**

```bash
composer check && composer md
git add src/Service/Reader/SavedSearchMarkReadService.php src/Service/Mail/Digest/DigestEntryFinder.php tests/Service/Mail/Digest/DigestEntryFinderTest.php
git commit -m "refactor(#1116): mark saved searches read and build the digest from the membership table"
```

---

### Task 11: Schedule the sweep — `MaintenanceTick`, the worker message, the regression test

**Files:**
- Modify: `backend/src/Service/Maintenance/MaintenanceTick.php`, `MaintenanceTickReport.php`
- Create: `backend/src/Service/Worker/Message/SweepSavedSearchMemberships.php`, `backend/src/Service/Worker/Handler/SweepSavedSearchMembershipsHandler.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php`
- Test: `backend/tests/Service/Maintenance/MaintenanceTickTest.php`, `backend/tests/Service/Worker/SweepSavedSearchMembershipsHandlerTest.php`, `backend/tests/Controller/Api/SavedSearchUnreadListMatchesBadgeTest.php`

**Interfaces:**
- `MaintenanceTick` constructor gains `SavedSearchMembershipSweep $membershipSweep` (sixth argument, before `LokiSpoolShipper`); `MaintenanceTickReport` gains `array $savedSearchMemberships` (fifth constructor argument, before `$logShipping`) and the key `savedSearchMemberships` in `toArray()`.
- `MaintenanceTick::TICK_MEMBERSHIP_BUDGET_SECONDS = 10`; handler budget 60.

- [ ] **Step 1: Extend the report and the tick**

`MaintenanceTickReport`: add `public array $savedSearchMemberships` between `$imageVerification` and `$logShipping`, the `@param`, the array-shape line, and `'savedSearchMemberships' => $this->savedSearchMemberships,` in `toArray()`.

`MaintenanceTick`:

```php
    public const int REFRESH_BUDGET_SECONDS = 20;

    /** Inside the 20 s tick, after the refresh: enough for ~50 chunks on Strato. */
    private const int MEMBERSHIP_BUDGET_SECONDS = 10;
    …
    public function __construct(
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
        private SendDueDigests $sendDueDigests,
        private ImageVerificationSweep $imageVerificationSweep,
        private SavedSearchMembershipSweep $membershipSweep,
        private LokiSpoolShipper $logSpoolShipper,
    ) {
    }

    public function run(): MaintenanceTickReport
    {
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        if ($refresh->isAborted()) {
            $recommendations = $this->skippedRecommendations();
            $digests = $this->skippedDigests();
            $imageVerification = $this->skippedImageVerification();
            $memberships = $this->skippedMemberships();
        } else {
            $recommendations = $this->forYouSweep->sweepOnce()->toArray();
            $digests = $this->sendDueDigests->run()->toArray();
            $imageVerification = $this->imageVerificationSweep->verifyDue()->toArray();
            $memberships = $this->membershipSweep->sweep(SweepBudget::seconds(self::MEMBERSHIP_BUDGET_SECONDS))->toArray();
        }
        $logShipping = $this->logSpoolShipper->ship()->toArray();

        return new MaintenanceTickReport(
            $refresh->toArray(),
            $recommendations,
            $digests,
            $imageVerification,
            $memberships,
            $logShipping,
        );
    }

    /**
     * @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool, skipped: string}
     */
    private function skippedMemberships(): array
    {
        return (new SavedSearchMembershipSweepReport(0, 0, 0, false))->toArray() + ['skipped' => self::ABORTED_REASON];
    }
```

Add one sentence to the class docblock: "The membership sweep (#1116) runs under the same guard, after the image sweep."

- [ ] **Step 2: Update `MaintenanceTickTest`**

Where the test constructs `new MaintenanceTick(...)` (around line 223), build a real sweep from the container's repositories with a `RecordingSavedSearchMatcher` (Task 6) and pass it as the fifth argument. In the "normal tick" test add:

```php
        self::assertIsInt($report['savedSearchMemberships']['entriesScanned']);
        self::assertIsBool($report['savedSearchMemberships']['caughtUp']);
        self::assertArrayNotHasKey('skipped', $report['savedSearchMemberships']);
```

In the aborted-refresh test add the expected block:

```php
        self::assertSame(
            [
                'searchesSwept' => 0,
                'entriesScanned' => 0,
                'matchesInserted' => 0,
                'caughtUp' => false,
                'skipped' => 'refresh aborted: the shared EntityManager is unusable this tick',
            ],
            $report['savedSearchMemberships'],
        );
```

Run: `php bin/phpunit --filter=MaintenanceTickTest` — PASS.

- [ ] **Step 3: Worker message, handler, schedule**

`backend/src/Service/Worker/Message/SweepSavedSearchMemberships.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Every minute: advance every saved search's membership mark (#1116). A
 * sweep — it does whatever is outstanding when it runs — so it carries no
 * properties and a missed tick catches up in one.
 */
final readonly class SweepSavedSearchMemberships
{
}
```

`backend/src/Service/Worker/Handler/SweepSavedSearchMembershipsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use App\Service\Worker\Message\SweepSavedSearchMemberships;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepSavedSearchMembershipsHandler
{
    /** The worker has no FastCGI window to fit; one firing may walk a whole backfill. */
    private const int BUDGET_SECONDS = 60;

    public function __construct(
        private SavedSearchMembershipSweep $sweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepSavedSearchMemberships $message): void
    {
        $report = $this->sweep->sweep(SweepBudget::seconds(self::BUDGET_SECONDS));
        $this->logger->info('Worker saved-search membership sweep finished.', ['report' => $report->toArray()]);
    }
}
```

`WorkerSchedule::getSchedule()`: add `->add(RecurringMessage::every('1 minute', new SweepSavedSearchMemberships()))` after the digests entry, with the import; change "Five entries by decision" to "Six entries by decision" and append to that sentence "…, and the one-minute saved-search membership sweep (#1116)". Update "All five messages are SWEEPS" to "All six".

`backend/tests/Service/Worker/SweepSavedSearchMembershipsHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Repository\EntryRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Worker\Handler\SweepSavedSearchMembershipsHandler;
use App\Service\Worker\Message\SweepSavedSearchMemberships;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use Symfony\Component\Clock\MockClock;

final class SweepSavedSearchMembershipsHandlerTest extends DbTestCase
{
    public function testFiringRunsTheSweepAndLogsItsReport(): void
    {
        $searches = self::getContainer()->get(SavedSearchRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $searches);
        $entries = self::getContainer()->get(EntryRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entries);
        $memberships = self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchEntryMembershipRepository::class, $memberships);
        $logger = new RecordingLogger();
        $sweep = new SavedSearchMembershipSweep(
            $searches,
            $entries,
            $memberships,
            new RecordingSavedSearchMatcher(),
            $this->em,
            new MockClock('2026-09-22T10:00:00'),
            $logger,
        );

        (new SweepSavedSearchMembershipsHandler($sweep, $logger))->__invoke(new SweepSavedSearchMemberships());

        self::assertCount(1, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertTrue($logger->records[0]['context']['report']['caughtUp']);
    }
}
```

Adjust the three `$logger->records[…]` reads to `RecordingLogger`'s real shape (as in Task 6).

Run: `php bin/phpunit --filter='SweepSavedSearchMembershipsHandlerTest|WorkerSchedule'` — PASS (if a `WorkerScheduleTest` exists and counts entries, update its count to 6).

- [ ] **Step 4: The regression test for the Docker bug (spec §10, replaces the e2e)**

`backend/tests/Controller/Api/SavedSearchUnreadListMatchesBadgeTest.php` (an `ApiTestCase`; copy the login/header helper usage from `SavedSearchControllerTest::testCreateListWithUnreadMatchIdsAndDelete`):

- Create a user with the factory, a feed, a subscription, and a saved search `climate`.
- Persist `SavedSearchMembershipSweep::CHUNK`-independent fixtures: three member entries with descending `effectiveDate`, all with `createdAt` `-1 day`; hide the newest two with `EntryState::hide()`.
- `GET /api/saved-searches` → `unreadEntryIds` for the search has exactly one id (the oldest).
- `GET /api/entries/saved-searches?unread=1` → `entries` has exactly one row and its `id` equals that id.
- Assert the two agree: `count(unreadEntryIds) === count(entries)`.

This is the exact shape that answered "733 vs empty" on Docker: the newest page read, unread rows older. It is DB-only and engine-independent now, by construction.

Run: `php bin/phpunit --filter=SavedSearchUnreadListMatchesBadgeTest` — PASS.

- [ ] **Step 5: Gates, commit**

```bash
composer check && composer md
git add src/Service/Maintenance src/Service/Worker tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Worker/SweepSavedSearchMembershipsHandlerTest.php tests/Controller/Api/SavedSearchUnreadListMatchesBadgeTest.php
git commit -m "feat(#1116): run the membership sweep from the maintenance tick and the worker"
```

---

### Task 12: Delete the engine-vs-database split for saved searches

**Files:**
- Delete (src): `Service/Search/{IndexedSavedSearchEntries,IndexedSavedSearchBadges,IndexedSavedSearchUnreadMatches,SavedSearchEntriesWithFallback,SavedSearchBadgesWithFallback,SavedSearchUnreadMatchesWithFallback,DatabaseSavedSearchEntries,DatabaseSavedSearchBadges,DatabaseSavedSearchUnreadMatches,SavedSearchEntriesInterface,SavedSearchBadgeSource,SavedSearchUnreadMatchSource}.php`, `Repository/{SavedSearchBadgeCandidateRepository,SavedSearchEntryQuery}.php`
- Delete (tests): the twelve listed under **File structure → Delete**.
- Modify: `backend/src/Repository/SavedSearchEntryRepository.php`, `backend/src/Repository/EntryListRepository.php`, `backend/config/services.yaml`, `backend/src/Service/Search/SavedSearchEntriesResult.php` (docblock only)

- [ ] **Step 1: Delete the files**

```bash
cd backend
git rm src/Service/Search/IndexedSavedSearchEntries.php src/Service/Search/IndexedSavedSearchBadges.php src/Service/Search/IndexedSavedSearchUnreadMatches.php src/Service/Search/SavedSearchEntriesWithFallback.php src/Service/Search/SavedSearchBadgesWithFallback.php src/Service/Search/SavedSearchUnreadMatchesWithFallback.php src/Service/Search/DatabaseSavedSearchEntries.php src/Service/Search/DatabaseSavedSearchBadges.php src/Service/Search/DatabaseSavedSearchUnreadMatches.php src/Service/Search/SavedSearchEntriesInterface.php src/Service/Search/SavedSearchBadgeSource.php src/Service/Search/SavedSearchUnreadMatchSource.php src/Repository/SavedSearchBadgeCandidateRepository.php src/Repository/SavedSearchEntryQuery.php
git rm tests/Service/Search/IndexedSavedSearchEntriesTest.php tests/Service/Search/IndexedSavedSearchBadgesTest.php tests/Service/Search/IndexedSavedSearchUnreadMatchesTest.php tests/Service/Search/SavedSearchEntriesWithFallbackTest.php tests/Service/Search/SavedSearchBadgesWithFallbackTest.php tests/Service/Search/SavedSearchUnreadMatchesWithFallbackTest.php tests/Service/Search/DatabaseSavedSearchEntriesTest.php tests/Service/Search/DatabaseSavedSearchBadgesTest.php tests/Service/Search/DatabaseSavedSearchUnreadMatchesTest.php tests/Repository/SavedSearchBadgeCandidateRepositoryTest.php tests/Repository/SavedSearchEntryListTest.php tests/Repository/SavedSearchUnreadMatchIdsTest.php
```

- [ ] **Step 2: Strip the `LIKE` reads from `SavedSearchEntryRepository`**

Remove `listForSavedSearches`, `unreadMatchIdsForSavedSearches`, `unreadMatchIdsBySavedSearch`, `matchedSavedSearchIds`, `matchIdsInOneScan`, `matchFlagExpression`, `anySearchMatches`, `firstMatchExpression`, the `SEARCHES_PER_SCAN` constant and the `SearchTermsPredicateBuilder` constructor argument and import, the `SavedSearchTerm` import. Rewrite the class docblock: "The combined saved-search list (#769) and every other saved-search read, over the membership table (#1116)."

Remove `EntryListRepository::unreadMatchIdsSince()` (keep `unreadMatchQueryBuilder` — `unreadMatchingEntryIdsForUser` still uses it) and the sentence in its class docblock that mentions sharing term matching with `SavedSearchEntryRepository`; fix the docblock on `rowsByIdsForUser` that names `IndexedSavedSearchEntries` (say "the digest and the recommender").

In `SavedSearchEntriesResult`, shorten the `$matchCount` docblock to: "The read's own frontier before hydration; equals count($rows) now that the list is the match set, kept so EntryPage::withMatchCount has one shape."

- [ ] **Step 3: Unwire**

In `config/services.yaml` delete the three lines:

```yaml
    App\Service\Search\SavedSearchEntriesInterface: '@App\Service\Search\SavedSearchEntriesWithFallback'
    App\Service\Search\SavedSearchUnreadMatchSource: '@App\Service\Search\SavedSearchUnreadMatchesWithFallback'
    App\Service\Search\SavedSearchBadgeSource: '@App\Service\Search\SavedSearchBadgesWithFallback'
```

Grep for stragglers: `grep -rn "SavedSearchEntryQuery\|SavedSearchEntriesInterface\|SavedSearchBadgeSource\|SavedSearchUnreadMatchSource\|SavedSearchBadgeCandidateRepository\|IndexedSavedSearch\|DatabaseSavedSearch\|unreadMatchIdsSince\|listForSavedSearches\|unreadMatchIdsForSavedSearches\|unreadMatchIdsBySavedSearch\|matchedSavedSearchIds" src tests config` — expected: no output except `SavedSearchEntryRepository::SEARCHES_PER_SCAN` if a test referenced it (delete that reference).

- [ ] **Step 4: Full native suite, gates, commit**

```bash
bin/console cache:warmup
composer check && composer md && php bin/phpunit
git add -A src tests config
git commit -m "refactor(#1116): delete the engine-versus-database split for saved searches"
```

---

### Task 13: Full verification, the MySQL leg, mutation gate, PR

**Files:** none new.

- [ ] **Step 1: Native suite and gates once more from a warm cache**

```bash
bin/console cache:warmup && composer check && composer md && php bin/phpunit
```

Expected: all green.

- [ ] **Step 2: Docker containers current, MySQL leg**

Confirm the php container runs this branch's code (rebuild if the compose file's image changed; otherwise the bind mount is enough), then:

```bash
docker compose exec php composer test
```

Expected: green. If a query passes on SQLite but fails on MySQL, the usual suspects are `IDENTITY()` in a subquery `SELECT` and the `LIKE ESCAPE` dialect — both are exercised by `SavedSearchMembershipReadsTest`.

- [ ] **Step 3: Live check on the Docker stack**

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console app:maintenance:tick   # or POST /maintenance/tick — whichever this checkout exposes; see MaintenanceController
```

Repeat the tick until the report's `savedSearchMemberships.caughtUp` is `true`. Then in the running frontend: open a saved search with the unread filter — the list must be non-empty when its badge is non-zero (the original report), and "mark all read" must return promptly. Scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 100 | jq 'select(.level_name != "DEBUG")'` — no warnings from the sweep.

- [ ] **Step 4: Mutation gate over the touched files**

```bash
composer infection:diff
```

Expected: MSI at or above `minMsi` in `infection.json5`. Escaped mutants in the sweep usually point at an unasserted counter (`entriesScanned`, `searchesSwept`) — add the assertion rather than lowering anything.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feature/1116-saved-search-membership-table
```

PR title: `Persist saved-search membership in a table, filled by an incremental sweep`. Body: the two root causes from the spec §1 (two short paragraphs), the decision paragraph (§2), the rollout note — "every existing search starts at mark 0; the first ticks after deploy backfill them all in one walk (~100 chunks per 50 000 entries); until then a search shows a partial list and badge" —, the deviation (no black-box e2e, why, and the kernel test that stands in), and `Closes #1116`. Merge target `develop`. Do not merge; hand the link back.

---

## Self-review against the spec

- §3 data model → Task 1. §3.3 global scope → no feed filter in matchers (Tasks 4, 5), subscription join in every reader (Task 7).
- §4.1 ceiling/settle, groups, chunks, budget, lowest mark first → Task 6 (`ceiling()`, `groupedByMark`, `walkGroup`, deadline; `findBelowMark` ordering in Task 3). §4.2 transaction and idempotent insert → Task 6 `wrapInTransaction` + Task 3 `insertMissing`. §4.3 no fallback → Tasks 4 and 6. §4.4 scheduling → Task 11 and Task 9 (`create()`). §4.5 report → Task 2.
- §5 matcher → Tasks 4, 5. §6 readers 1–4 → Tasks 7–10. §7 deletions → Task 12. §8 API unchanged; `backfillPending` left out per §13 default. §9 migration and rollout → Task 1, Task 13. §10 tests → each task; e2e replaced by Task 11 Step 4 (deviation recorded in Global Constraints). §11 style → enforced by gates per task. §12 follow-ups untouched.
- Type consistency checked: `SavedSearchMatcher::matchingIds(array $searches, array $candidateEntryIds)` everywhere; `SweepBudget::seconds()` / `deadlineFrom()`; `SavedSearchListQuery` fields; repository method names `listMembers`, `unreadMemberIdsBySavedSearch`, `unreadMemberIdsUpTo`, `unreadMemberIdsSince`, `firstMatchingSavedSearchIds`; `MaintenanceTick` argument order (sweep before shipper) matches `MaintenanceTickTest` and `MaintenanceTickReport` argument order (memberships before logShipping).

---

## Recorded at execution: where the code diverged from this plan

Both handled in PR #1117; recorded so the plan the repo keeps matches what was built.

1. **`settledCeilingId()` / `idsBetween()` do not live on `EntryRepository`** (Tasks 3, 6, 11). Adding them tripped PHPMD `TooManyPublicMethods` (`EntryRepository` already carried nine), and the threshold is not to be tuned. They live on a new `EntryMembershipSweepRepository`, the same split `EntryListRepository` is. `SavedSearchMembershipSweep` injects that repository, and the sweep-construction tests fetch it from the container.
2. **The atomicity double in Task 6 needed the real `ManagerRegistry`**, not the `EntityManager`: `ServiceEntityRepository`'s constructor takes a registry. The anonymous subclass is built over the container's registry so `parent::insertMissing()` runs the real `INSERT` — otherwise the rollback test proves nothing. `SavedSearchEntryMembershipRepository` lost `final` for it, as the task's note anticipated.

Smaller, within scope: the `DigestEntryFinder` constructor change (Task 10) reached six digest tests whose collaborators are `final`; they now use real fixtures through a shared `SavedSearchMatchFixture` helper. `docs/backup.md` gained a row for the new entity.
