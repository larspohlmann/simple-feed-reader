# Entry `effective_date` Materialization Implementation Plan (#245)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the un-indexable `COALESCE(published_at, created_at)` sort expression with a materialized, indexed `entry.effective_date` column, and retire the one-time published-date repair path.

**Architecture:** `Entry` owns the invariant `effectiveDate = publishedAt ?? createdAt` — the constructor initialises it and `setPublishedAt()` recomputes it; there is no public setter. Two indexes serve the hot queries: `(effective_date, id)` for the cross-feed newest-first list, `(feed_id, effective_date)` (replacing `idx_entry_feed_published`) for single-feed scans, the pruner, and the mark-read watermark. All five DQL call sites switch from the `COALESCE` expression to the column.

**Tech Stack:** Symfony 7.4, Doctrine ORM/DBAL 4, Doctrine Migrations (platform-aware MySQL + SQLite DDL), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-02-entry-effective-date-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file; PSR-12 (`composer cs`); PHPStan level max (`composer stan`, warm the cache first with `bin/console cache:warmup`).
- Every touched `src` file must be PHPMD-clean before commit (`composer md`) — fix the design, not the threshold.
- Datetimes are naive UTC everywhere; the new column is no exception.
- All backend commands run from `backend/`.
- Commit messages follow the repo convention: `fix(#245): <what>` / `test(#245): <what>` / `chore(#245): <what>`.
- Migrations must be platform-aware (MySQL + SQLite), idempotent against a `doctrine:schema:create`-baselined database, and safe to re-run after a partial failure (`isTransactional(): false`, MySQL DDL autocommits). Model: `backend/migrations/Version20260802120000.php`.
- The branch is `fix/245-entry-list-effective-date`; it already exists and carries the spec.

---

### Task 1: Remove the one-time published-date repair path

The #48/#50 repair has run in production. It is the only thing that rewrites `publishedAt` after insert; removing it first makes the invariant in Task 2 trivially true.

**Files:**
- Modify: `backend/src/Service/EntryIngestor.php` (delete `correctPublishedDates()`, lines 82–115)
- Delete: `backend/src/Command/BackfillPublishedDatesCommand.php`
- Delete: `backend/tests/Service/CorrectPublishedDatesTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `EntryIngestor` without `correctPublishedDates()`. After this task, `Entry::setPublishedAt()` is called only at entry creation time (ingest, e2e seed).

- [ ] **Step 1: Confirm the footprint is exactly three files**

Run: `grep -rln "correctPublishedDates\|BackfillPublishedDates" src tests`
Expected: exactly `src/Service/EntryIngestor.php`, `src/Command/BackfillPublishedDatesCommand.php`, `tests/Service/CorrectPublishedDatesTest.php`. If anything else appears, stop and report.

- [ ] **Step 2: Delete the command and its test**

```bash
git rm backend/src/Command/BackfillPublishedDatesCommand.php backend/tests/Service/CorrectPublishedDatesTest.php
```

- [ ] **Step 3: Delete `correctPublishedDates()` from `EntryIngestor`**

Remove the whole method including its docblock (`/** * Rewrite existing entries' publishedAt …` through the closing brace). Do not touch `ingest()` or `fillMissingImages()`.

- [ ] **Step 4: Run the SQLite suite**

Run: `php bin/phpunit`
Expected: PASS, no references to the removed code.

- [ ] **Step 5: Static checks on the touched file**

Run: `bin/console cache:warmup && composer check && composer md`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add -A backend
git commit -m "chore(#245): remove the one-time published-date repair path"
```

---

### Task 2: `Entry::effectiveDate` — entity invariant and index declarations

**Files:**
- Modify: `backend/src/Entity/Entry.php`
- Create: `backend/tests/Entity/EntryEffectiveDateTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `Entry::getEffectiveDate(): \DateTimeImmutable`; ORM column `entry.effective_date` (non-null, `datetime_immutable`, `options: ['default' => '1970-01-01 00:00:00']`); indexes `idx_entry_effective (effective_date, id)` and `idx_entry_feed_effective (feed_id, effective_date)`; `idx_entry_feed_published` is gone from metadata. Tasks 4–6 rely on the DQL field name `e.effectiveDate`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Entity/EntryEffectiveDateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use PHPUnit\Framework\TestCase;

final class EntryEffectiveDateTest extends TestCase
{
    public function testConstructionFallsBackToCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-01 10:00:00');

        $entry = $this->makeEntry($createdAt);

        self::assertEquals($createdAt, $entry->getEffectiveDate());
    }

    public function testSetPublishedAtMovesTheEffectiveDate(): void
    {
        $publishedAt = new \DateTimeImmutable('2026-07-15 08:30:00');
        $entry = $this->makeEntry(new \DateTimeImmutable('2026-08-01 10:00:00'));

        $entry->setPublishedAt($publishedAt);

        self::assertEquals($publishedAt, $entry->getEffectiveDate());
    }

    public function testClearingPublishedAtFallsBackToCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-01 10:00:00');
        $entry = $this->makeEntry($createdAt);
        $entry->setPublishedAt(new \DateTimeImmutable('2026-07-15 08:30:00'));

        $entry->setPublishedAt(null);

        self::assertEquals($createdAt, $entry->getEffectiveDate());
    }

    private function makeEntry(\DateTimeImmutable $createdAt): Entry
    {
        return new Entry(
            feed: new Feed('https://example.com/feed.xml'),
            guid: 'urn:uuid:effective-date',
            url: 'https://example.com/post/1',
            title: 'A post',
            createdAt: $createdAt,
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Entity/EntryEffectiveDateTest.php`
Expected: FAIL — `Call to undefined method App\Entity\Entry::getEffectiveDate()`.

- [ ] **Step 3: Implement in `Entry.php`**

Replace the class-level index attribute (line 14):

```php
#[ORM\Index(name: 'idx_entry_effective', columns: ['effective_date', 'id'])]
#[ORM\Index(name: 'idx_entry_feed_effective', columns: ['feed_id', 'effective_date'])]
```

(`idx_entry_feed_published` is removed.)

Add the property directly after `$publishedAt`:

```php
    /**
     * The list-sort instant: publishedAt when the feed supplied one, createdAt
     * otherwise. Materialized (rather than COALESCE'd in queries) so an index
     * can serve the reader's newest-first sort. Maintained exclusively by the
     * constructor and setPublishedAt() — no public setter — so it cannot drift
     * from its sources. The column default exists only for the migration on
     * SQLite, which cannot add a NOT NULL column without one; every real row
     * is written by this class or backfilled by the migration.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, options: ['default' => '1970-01-01 00:00:00'])]
    private \DateTimeImmutable $effectiveDate;
```

In the constructor, after `$this->createdAt = $createdAt;`:

```php
        $this->effectiveDate = $createdAt;
```

Replace `setPublishedAt()` and add the getter after `getPublishedAt()`:

```php
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
        $this->effectiveDate = $publishedAt ?? $this->createdAt;
    }

    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->effectiveDate;
    }
```

- [ ] **Step 4: Run the test, then the whole SQLite suite**

Run: `php bin/phpunit tests/Entity/EntryEffectiveDateTest.php` → PASS.
Run: `php bin/phpunit` → PASS (the test bootstrap builds schema from metadata, so the new column and indexes exist everywhere automatically).

- [ ] **Step 5: Static checks**

Run: `composer check && composer md`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Entity/Entry.php backend/tests/Entity/EntryEffectiveDateTest.php
git commit -m "fix(#245): materialize entry.effective_date as an entity-maintained column"
```

---

### Task 3: Migration — add, backfill, reindex

**Files:**
- Create: `backend/migrations/Version20260802130000.php`

**Interfaces:**
- Consumes: the column/index names declared in Task 2 (`effective_date`, `idx_entry_effective`, `idx_entry_feed_effective`).
- Produces: a migrated database whose schema passes `doctrine:schema:validate` on MySQL and SQLite.

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Materializes entry.effective_date = COALESCE(published_at, created_at) so an
 * index can serve the reader's newest-first sort (#245), and swaps the entry
 * indexes: idx_entry_feed_published goes; idx_entry_effective (effective_date,
 * id) serves the cross-feed list walk, idx_entry_feed_effective (feed_id,
 * effective_date) serves single-feed scans, the pruner and the mark-read
 * watermark.
 *
 * PLATFORM-AWARE DDL for the same reason Version20260723200000 is: DDL diffed
 * on one platform does not parse on the other, and the suite cannot catch it
 * because tests build their schema from ORM metadata, not this chain.
 *
 * The epoch DEFAULT exists only because SQLite cannot ADD a NOT NULL column
 * without one (and has no MODIFY COLUMN to tighten afterwards); MySQL takes the
 * identical DDL so both platforms match the ORM metadata, which declares the
 * same default. No row ever keeps it: the backfill below rewrites every
 * pre-existing row, and Entry assigns the real value on construction.
 *
 * The backfill runs OUTSIDE the column guard and is idempotent (rewriting a
 * correct row with the same COALESCE is a no-op): isTransactional() is false
 * and MySQL DDL autocommits, so a process killed between the ALTER and the
 * UPDATE must still backfill when doctrine:migrations:migrate is re-run.
 */
final class Version20260802130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Materialize entry.effective_date and index it for list sorting (#245)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(!$mysql && !$sqlite, \sprintf(
            'No DDL defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));

        $entry = $schema->getTable('entry');

        // Per-column idempotence for a database baselined from
        // doctrine:schema:create, where ORM metadata already produced the column.
        if (!$entry->hasColumn('effective_date')) {
            $verb = $mysql ? 'ADD' : 'ADD COLUMN';
            $this->addSql(\sprintf(
                "ALTER TABLE entry %s effective_date DATETIME DEFAULT '1970-01-01 00:00:00' NOT NULL",
                $verb,
            ));
        }

        // Unconditional and self-healing; see the class docblock.
        $this->addSql('UPDATE entry SET effective_date = COALESCE(published_at, created_at)');

        if ($entry->hasIndex('idx_entry_feed_published')) {
            $this->addSql($mysql
                ? 'DROP INDEX idx_entry_feed_published ON entry'
                : 'DROP INDEX idx_entry_feed_published');
        }
        if (!$entry->hasIndex('idx_entry_effective')) {
            $this->addSql('CREATE INDEX idx_entry_effective ON entry (effective_date, id)');
        }
        if (!$entry->hasIndex('idx_entry_feed_effective')) {
            $this->addSql('CREATE INDEX idx_entry_feed_effective ON entry (feed_id, effective_date)');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;

        $entry = $schema->getTable('entry');

        if ($entry->hasIndex('idx_entry_effective')) {
            $this->addSql($mysql ? 'DROP INDEX idx_entry_effective ON entry' : 'DROP INDEX idx_entry_effective');
        }
        if ($entry->hasIndex('idx_entry_feed_effective')) {
            $this->addSql($mysql
                ? 'DROP INDEX idx_entry_feed_effective ON entry'
                : 'DROP INDEX idx_entry_feed_effective');
        }
        if (!$entry->hasIndex('idx_entry_feed_published')) {
            $this->addSql('CREATE INDEX idx_entry_feed_published ON entry (feed_id, published_at)');
        }
        if ($entry->hasColumn('effective_date')) {
            $this->addSql('ALTER TABLE entry DROP COLUMN effective_date');
        }
    }
}
```

- [ ] **Step 2: Run it against the Docker MySQL and validate the schema**

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: migration applies; `[Mapping] OK`, `[Database] OK`. If `[Database]` reports a diff on `effective_date` or the indexes, fix the DDL — do not fix the metadata.

- [ ] **Step 3: Re-run to prove idempotence**

Run: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`
Expected: "No migrations to execute" (versions table already records it).

- [ ] **Step 4: Confirm the backfill on real rows**

```bash
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) AS wrong FROM entry WHERE effective_date <> COALESCE(published_at, created_at)"
```

Expected: `wrong = 0`.

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/Version20260802130000.php
git commit -m "fix(#245): migration for entry.effective_date with backfill and index swap"
```

Note: the CI migration leg (migrate from empty on SQLite and MySQL, then `doctrine:schema:validate`) is the authoritative check for the SQLite branch — watch it on the PR.

---

### Task 4: Switch the list query and the cursor to the column

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php:90-119` (`listForUser`), `:180-200` (`applyView`), `:202-214` (`applyCursor`), `:245-257` (`rowIsRead`)
- Modify: `backend/src/Controller/Api/EntryController.php:89-99` (nextCursor build)
- Modify: `backend/src/Http/EntryCursor.php:10` (doc comment)
- Modify: `backend/tests/Repository/EntryListTest.php:180`
- Test: `backend/tests/Repository/EntryListTest.php` (existing — pins ordering, cursor, and view behaviour, which must not change)

**Interfaces:**
- Consumes: `Entry::getEffectiveDate(): \DateTimeImmutable` and DQL field `e.effectiveDate` (Task 2).
- Produces: identical observable API behaviour; `EntryCursor` encoding unchanged.

- [ ] **Step 1: Run the existing tests first — they are the safety net**

Run: `php bin/phpunit tests/Repository/EntryListTest.php`
Expected: PASS (baseline).

- [ ] **Step 2: `listForUser` — order by the column, drop the HIDDEN alias**

Replace lines 94–98:

```php
        $qb = $this->rowQueryBuilder($query->userId)
            ->orderBy('e.effectiveDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);
```

- [ ] **Step 3: `applyView` — unread watermark uses the column**

In the `'unread'` case, replace the `andWhere` expression:

```php
                $qb->andWhere(
                    'es.isRead = :readFalse '
                    . 'OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL '
                    . 'OR e.effectiveDate > s.markedReadUntil))',
                )->setParameter('readFalse', false, Types::BOOLEAN);
```

- [ ] **Step 4: `applyCursor` — tuple comparison on the column**

```php
        $qb->andWhere(
            '(e.effectiveDate < :curDate '
            . 'OR (e.effectiveDate = :curDate AND e.id < :curId))',
        )
            ->setParameter('curDate', $cursor->date, Types::DATETIME_IMMUTABLE)
            ->setParameter('curId', $cursor->id);
```

- [ ] **Step 5: `rowIsRead` — use the getter**

Replace lines 252–256:

```php
        $markedReadUntil = $row['markedReadUntil'];

        return $markedReadUntil instanceof \DateTimeInterface
            && $entry->getEffectiveDate() <= $markedReadUntil;
```

- [ ] **Step 6: `EntryController` — nextCursor from the getter**

Replace lines 94–98:

```php
            $entry = $last->entry;
            $nextCursor = EntryCursor::encode($entry->getEffectiveDate(), $entry->getId() ?? 0);
```

- [ ] **Step 7: `EntryCursor` doc comment and the test's cursor build**

In `backend/src/Http/EntryCursor.php`, update the line-10 comment: `date` is the entry's `effectiveDate` (publishedAt when the feed supplied one, createdAt otherwise). In `backend/tests/Repository/EntryListTest.php:180`, replace `$page1[1]->entry->getPublishedAt() ?? $page1[1]->entry->getCreatedAt()` with `$page1[1]->entry->getEffectiveDate()`.

- [ ] **Step 8: Run the suite**

Run: `php bin/phpunit`
Expected: PASS — behaviour is pinned by the existing tests; any failure means the rewrite changed semantics. Investigate, do not adjust the test.

- [ ] **Step 9: Static checks and commit**

Run: `composer check && composer md` → clean.

```bash
git add backend/src/Repository/EntryRepository.php backend/src/Controller/Api/EntryController.php backend/src/Http/EntryCursor.php backend/tests/Repository/EntryListTest.php
git commit -m "fix(#245): sort and paginate the entry list on effective_date"
```

---

### Task 5: Unread counts and mark-all-read use the column

**Files:**
- Modify: `backend/src/Repository/EntryStateRepository.php:86-90`
- Modify: `backend/src/Service/Reader/MarkReadService.php:60-66`
- Test: `backend/tests/Repository/UnreadCountsTest.php` and the existing MarkRead tests under `backend/tests/` (existing — pin the behaviour)

**Interfaces:**
- Consumes: DQL field `e.effectiveDate` (Task 2).
- Produces: identical observable behaviour; the read-watermark comparisons now use the same instant the list sorts by, by construction.

- [ ] **Step 1: Baseline**

Run: `php bin/phpunit tests/Repository/UnreadCountsTest.php` and `php bin/phpunit --filter MarkRead`
Expected: PASS.

- [ ] **Step 2: `EntryStateRepository::unreadCountsForUser`**

Replace the watermark line in the DQL:

```php
                 OR (es.isRead IS NULL AND (s.markedReadUntil IS NULL
                     OR e.effectiveDate > s.markedReadUntil))
```

- [ ] **Step 3: `MarkReadService::mark` — the bulk read-flip subquery**

```php
                 AND es.entry IN (
                     SELECT e.id FROM %s e
                     WHERE e.feed IN (:feeds) AND e.effectiveDate <= :until
                 )
```

Also update the class docblock's phrase "whose effectiveDate <= T" — it already says `effectiveDate`; it is now literally true, leave it as is.

- [ ] **Step 4: Run the suite, static checks, commit**

Run: `php bin/phpunit` → PASS. `composer check && composer md` → clean.

```bash
git add backend/src/Repository/EntryStateRepository.php backend/src/Service/Reader/MarkReadService.php
git commit -m "fix(#245): unread counts and mark-all-read compare effective_date"
```

---

### Task 6: Pruner uses the column

**Files:**
- Modify: `backend/src/Service/EntryPruner.php:58-75` (`pruneByAge`), `:95-122` (`excessEntryIds`)
- Test: `backend/tests/Service/EntryPrunerTest.php` (existing — pins the behaviour)

**Interfaces:**
- Consumes: DQL field `e.effectiveDate` (Task 2).
- Produces: identical pruning behaviour; the `HIDDEN` alias workaround disappears.

- [ ] **Step 1: Baseline**

Run: `php bin/phpunit tests/Service/EntryPrunerTest.php`
Expected: PASS.

- [ ] **Step 2: `pruneByAge` — cutoff on the column**

```php
        $ids = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e
             WHERE e.effectiveDate < :cutoff
             AND %s',
            Entry::class,
            $this->notProtectedDql(),
        ))
```

- [ ] **Step 3: `excessEntryIds` — order by the column, drop the HIDDEN workaround**

Remove the `// COALESCE can't sit in ORDER BY …` comment and replace the query:

```php
        /** @var list<int> $ids */
        $ids = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e
             WHERE e.feed = :feed
             AND %s
             ORDER BY e.effectiveDate DESC, e.id DESC',
            Entry::class,
            $this->notProtectedDql(),
        ))
            ->setParameter('feed', $feedId)
            ->setParameter('true', true, Types::BOOLEAN)
            ->setFirstResult($this->maxEntriesPerFeed)
            ->getSingleColumnResult();
```

The method docblock's "Ties on the effective date fall back to id" stays accurate.

- [ ] **Step 4: Run the suite, static checks, commit**

Run: `php bin/phpunit` → PASS. `composer check && composer md` → clean.

```bash
git add backend/src/Service/EntryPruner.php
git commit -m "fix(#245): pruner cutoff and ordering on effective_date"
```

---

### Task 7: Full verification

**Files:** none (verification only).

**Interfaces:**
- Consumes: everything above.
- Produces: a branch ready for PR.

- [ ] **Step 1: No stray COALESCE remains on entry dates**

Run: `grep -rn "COALESCE(e.publishedAt" src tests`
Expected: no matches. (`FeedRepository`'s `COALESCE(f.nextFetchAt, :epoch)` is a different concern and stays.)

- [ ] **Step 2: Both suite legs**

```bash
php bin/phpunit
docker compose exec php vendor/bin/phpunit
```

Expected: PASS on both. Known flake: the MySQL leg has order-dependent rate-limiter failures that pass in isolation — re-run a failing limiter test alone before blaming this change.

- [ ] **Step 3: Prove the filesort is gone**

```bash
docker compose exec php bin/console dbal:run-sql "EXPLAIN SELECT e0_.id FROM entry e0_ INNER JOIN subscription s1_ ON s1_.feed_id = e0_.feed_id AND s1_.user_id = 1 ORDER BY e0_.effective_date DESC, e0_.id DESC LIMIT 40"
```

Expected: the `entry` row uses key `idx_entry_effective` with `Backward index scan` and **no** `Using filesort` in Extra. If the optimizer still filesorts on the tiny dev dataset, note it in the PR (small-table plans differ) — the index existing and being usable is the acceptance bar.

- [ ] **Step 4: Lint gates and the dev log**

```bash
composer check
composer md
tail -100 var/log/dev.log
```

Expected: clean; no new deprecations or swallowed errors in the log. Run PhpStorm inspections (`mcp__phpstorm__lint_files`) on the touched PHP files if the tool is available; block on ERROR/WARNING.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin fix/245-entry-list-effective-date
```

PR into `develop`, body includes `Closes #245`, summarising: repair-path removal, the entity invariant, the index swap, and the EXPLAIN result. After merge, verify #245 closed automatically.
