# Restore: batch the two per-row lookups of the load pass (#456, #455)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two performance follow-ups of #412 on the restore's post-wipe load path: read back only the ids of the entry rows each batch just wrote instead of the whole feed's `(guid_hash, id)` map (#456), and look up the file's feed rows in one query instead of one per feed line (#455).

**Architecture:** For #456, `RestoreEntryLoader::insertBufferedEntries()` already knows the hashes it wrote (`$fresh`, at most 500). A new `EntryRepository::entryIdsByGuidHash(feedId, hashes)` fetches exactly those ids, and `RestoreFeedTarget::learn()` merges them into the map the target was built with. The per-feed `recordCreatedIds()` / `absorb()` diff, the `insertedGuidHashes` side set and the `bufferedFeedGotInserts` flag all disappear: once every insert reads its ids back immediately, one map answers both "do I know this hash?" and "what is its id?". The first snapshot in `RestoreLoadPass::feedTargets()` stays as it is (settled decision, see #456).

For #455, `BackupReader` guarantees every feed line precedes the first subscription line, so `RestoreLoadPass` holds the feed lines back and resolves the whole set with one `FeedRepository::findByUrlsIndexedByUrl()` query when the first subscription arrives (or when the entry phase starts, for a file with feeds and no subscriptions). Missing urls become new `Feed` rows exactly as before. Nothing is threaded through `AccountRestorer` or `RestoreLoader`: pass 1's url set is not needed, because pass 2 sees the same lines in the same order — and threading the inventory through would be a three-class tramp chain.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM/DBAL, PHPUnit (SQLite natively, MySQL in Docker), Infection (`composer infection:diff`, `minMsi: 80`), PHPMD, PHPStan max, phptramp.

**Spec:** https://github.com/larspohlmann/simple-feed-reader/issues/456 and https://github.com/larspohlmann/simple-feed-reader/issues/455 (both follow-ups of #412; the plan for #412 is `docs/superpowers/plans/2026-08-17-412-account-backup-restore.md`).

## Global Constraints

- Branch off `develop`: `fix/456-restore-batched-lookups`. Commit type `perf(#456): …` for Tasks 1–3, `perf(#455): …` for Task 4. PR body says `Closes #456` and `Closes #455`.
- #455 was closed as *not planned* on 2026-08-21 with no comment. Reopen it before opening the PR (`gh issue reopen 455`), otherwise `Closes #455` is a no-op and the link is lost.
- Performance only. Every existing restore test must stay green unchanged (`tests/Service/Backup/AccountRestorerTest.php`, `GoldenBackupRestoreTest.php`, `RestoreLoadPassTest.php`).
- Clean Code rules from `CLAUDE.md`: no boolean flags, guard clauses, comments only for a *why*, one to three lines.
- Every touched `src` file must be PHPMD-clean (`composer md`), PHPStan-clean (`composer stan`), and pass `composer cs`.
- `composer infection:diff` gates the touched lines at `minMsi: 80`; the unit tests in Task 2 exist to kill the mutants there.
- The read-back query carries at most `RestoreEntryLoader::BATCH` (500) hashes, so it is bounded by batch size, never by feed size.
- Run both suite legs before the PR: `php bin/phpunit` natively and `docker compose exec php vendor/bin/phpunit tests/Service/Backup tests/Repository` (the `IN (…)` list with 500 parameters must be proven on MySQL too).
- Do not touch `RestoreLoadPass::feedTargets()` or `EntryRepository::guidHashToIdMapForFeed()`'s query; only its docblock changes (the restore now uses it once, not twice).

---

### Task 1: `EntryRepository::entryIdsByGuidHash()`

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php` (after `guidHashToIdMapForFeed`, line 184–200)
- Create: `backend/tests/Repository/EntryIdsByGuidHashTest.php`

**Interfaces:**
- Produces: `EntryRepository::entryIdsByGuidHash(int $feedId, array $guidHashes): array` — `@param list<string> $guidHashes`, `@return array<string, int>` guid hash ⇒ entry id, only for rows of `$feedId` whose hash is in the list. An empty list returns `[]` without a query.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Tests\DbTestCase;

/**
 * The restore's post-insert read-back (#456): the ids of the rows one batch
 * just wrote, and nothing else of the feed.
 */
final class EntryIdsByGuidHashTest extends DbTestCase
{
    public function testReturnsTheAskedHashesOfTheOneFeedOnly(): void
    {
        $one = $this->feed('https://one.example/feed.xml');
        $two = $this->feed('https://two.example/feed.xml');
        $wanted = $this->entry($one, 'guid-a');
        $this->entry($one, 'guid-b');
        $this->entry($two, 'guid-a');
        $this->em->flush();

        $ids = $this->repository()->entryIdsByGuidHash(
            (int) $one->getId(),
            [hash('sha256', 'guid-a'), hash('sha256', 'guid-never-written')],
        );

        self::assertSame([hash('sha256', 'guid-a') => (int) $wanted->getId()], $ids);
    }

    public function testAnEmptyListAsksForNothing(): void
    {
        self::assertSame([], $this->repository()->entryIdsByGuidHash(1, []));
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.test/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-08-02 06:00:00'),
            new \DateTimeImmutable('2026-08-02 05:00:00'),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function repository(): EntryRepository
    {
        $repository = $this->em->getRepository(Entry::class);
        self::assertInstanceOf(EntryRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run (from `backend/`): `php bin/phpunit tests/Repository/EntryIdsByGuidHashTest.php`
Expected: both tests error with `Call to undefined method App\Repository\EntryRepository::entryIdsByGuidHash()`.

- [ ] **Step 3: Implement**

In `backend/src/Repository/EntryRepository.php`, replace `guidHashToIdMapForFeed()` (lines 174–200) with the pair below. The shared row-to-map loop moves into `idsByHash()`.

```php
    /**
     * The feed's whole guid hash ⇒ entry id map, as scalars — the restore's
     * pre-load snapshot, which both drops the file's entries the feed already
     * holds and attaches the file's entry states to rows whose ids the source
     * instance never knew. Ids and hashes only — hydrating the entities would
     * put a feed's entire back catalogue in memory for a two-column lookup.
     *
     * @return array<string, int>
     */
    public function guidHashToIdMapForFeed(int $feedId): array
    {
        /** @var list<array{guidHash: string, id: int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.guidHash AS guidHash', 'e.id AS id')
            ->andWhere('e.feed = :feed')
            ->setParameter('feed', $feedId)
            ->getQuery()
            ->getResult();

        return $this->idsByHash($rows);
    }

    /**
     * The ids of the rows a restore batch just inserted, found by the hashes
     * it wrote (#456). Bounded by the batch, never by the feed.
     *
     * @param list<string> $guidHashes
     *
     * @return array<string, int>
     */
    public function entryIdsByGuidHash(int $feedId, array $guidHashes): array
    {
        if ([] === $guidHashes) {
            return [];
        }

        /** @var list<array{guidHash: string, id: int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.guidHash AS guidHash', 'e.id AS id')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feedId)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getResult();

        return $this->idsByHash($rows);
    }

    /**
     * @param list<array{guidHash: string, id: int}> $rows
     *
     * @return array<string, int>
     */
    private function idsByHash(array $rows): array
    {
        $idsByHash = [];
        foreach ($rows as $row) {
            $idsByHash[$row['guidHash']] = $row['id'];
        }

        return $idsByHash;
    }
```

- [ ] **Step 4: Run to green**

Run: `php bin/phpunit tests/Repository/EntryIdsByGuidHashTest.php tests/Service/Backup`
Expected: all pass. Then `composer cs && composer stan && composer md` — clean.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EntryRepository.php backend/tests/Repository/EntryIdsByGuidHashTest.php
git commit -m "perf(#456): read entry ids back by guid hash, scoped to one feed"
```

---

### Task 2: The loader reads ids back per batch; the target learns them

**Files:**
- Modify: `backend/src/Service/Backup/RestoreFeedTarget.php` (whole file)
- Modify: `backend/src/Service/Backup/RestoreEntryLoader.php:45-48`, `:155-224`
- Create: `backend/tests/Service/Backup/RestoreFeedTargetTest.php`
- Create: `backend/tests/Service/Backup/RestoreEntryLoaderTest.php`

**Interfaces:**
- Consumes: `EntryRepository::entryIdsByGuidHash(int $feedId, list<string> $guidHashes): array<string, int>` (Task 1).
- Produces: `RestoreFeedTarget::learn(array<string, int> $entryIdsByGuidHash): void` merges into the target's map. `RestoreFeedTarget::markInserted()` and `absorb()` are deleted. `RestoreEntryLoader` loses `$bufferedFeedGotInserts` and `recordCreatedIds()`.

- [ ] **Step 1: Write the failing target test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\RestoreFeedTarget;
use PHPUnit\Framework\TestCase;

final class RestoreFeedTargetTest extends TestCase
{
    public function testKnowsTheRowsItWasBuiltWithAndTheOnesItLearns(): void
    {
        $target = new RestoreFeedTarget(7, true, ['old-hash' => 1]);
        self::assertTrue($target->knowsEntry('old-hash'));
        self::assertFalse($target->knowsEntry('new-hash'));
        self::assertNull($target->entryId('new-hash'));

        $target->learn(['new-hash' => 2]);

        self::assertTrue($target->knowsEntry('new-hash'));
        self::assertSame(2, $target->entryId('new-hash'));
        self::assertSame(1, $target->entryId('old-hash'));
    }

    public function testLearningKeepsWhatEarlierBatchesTaught(): void
    {
        $target = new RestoreFeedTarget(7, true, []);

        $target->learn(['first-batch' => 10]);
        $target->learn(['second-batch' => 11]);

        self::assertSame(10, $target->entryId('first-batch'));
        self::assertSame(11, $target->entryId('second-batch'));
    }
}
```

- [ ] **Step 2: Write the failing loader test**

This is the direct-invocation unit test the memory warns about, so it proves only two contracts, and Task 3 backs it with the round-trip suite: (a) the read-back asks for exactly the hashes the batch inserted, on that feed; (b) a read-back that comes up short is a loud failure, not a silent hole in the index.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\EntryBatchInserter;
use App\Service\Backup\RestoreEntryLoader;
use App\Service\Backup\RestoreFeedTarget;
use App\Service\Search\EntryIndexer;
use App\Service\Url\UrlNormalizer;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The read-back contract of #456, pinned directly: after a batch insert the
 * loader asks for the ids of exactly the hashes it wrote. AccountRestorerTest
 * proves the same path end to end; this test is the one that fails when a
 * refactor quietly goes back to re-reading the whole feed.
 */
final class RestoreEntryLoaderTest extends TestCase
{
    private const string FEED_URL = 'https://one.example/feed.xml';

    public function testTheIdReadBackAsksOnlyForTheHashesItJustInserted(): void
    {
        $known = $this->line('guid-known');
        $fresh = $this->line('guid-fresh');
        $entries = $this->createMock(EntryRepository::class);
        $entries->expects(self::once())
            ->method('entryIdsByGuidHash')
            ->with(7, [$fresh->guidHash])
            ->willReturn([$fresh->guidHash => 42]);
        $entries->method('entriesAfterId')->willReturn([]);
        $entries->expects(self::never())->method('guidHashToIdMapForFeed');
        $loader = $this->loader($entries);
        $loader->begin([self::FEED_URL => new RestoreFeedTarget(7, true, [$known->guidHash => 1])], $this->user());

        $loader->bufferEntry($known);
        $loader->bufferEntry($fresh);
        $loader->finish();

        self::assertSame(1, $loader->entriesCreated());
    }

    public function testAReadBackThatMissesARowItJustWroteIsALogicError(): void
    {
        $fresh = $this->line('guid-fresh');
        $entries = $this->createStub(EntryRepository::class);
        $entries->method('entryIdsByGuidHash')->willReturn([]);
        $loader = $this->loader($entries);
        $loader->begin([self::FEED_URL => new RestoreFeedTarget(7, true, [])], $this->user());

        $loader->bufferEntry($fresh);

        $this->expectException(\LogicException::class);
        $loader->finish();
    }

    private function loader(EntryRepository $entries): RestoreEntryLoader
    {
        return new RestoreEntryLoader(
            $this->createStub(EntityManagerInterface::class),
            $entries,
            new EntryBatchInserter($this->createStub(Connection::class), new UrlNormalizer()),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            new MockClock('2026-08-01 00:00:00', 'UTC'),
        );
    }

    private function user(): User
    {
        return new User('loader@example.com', new \DateTimeImmutable('2026-08-01'));
    }

    private function line(string $guid): EntryLine
    {
        return new EntryLine(
            feedUrl: self::FEED_URL,
            guid: $guid,
            guidHash: hash('sha256', $guid),
            url: 'https://example.test/' . $guid,
            title: 'Title ' . $guid,
            author: null,
            summary: null,
            contentHtml: null,
            imageUrl: null,
            imageWidth: null,
            imageHeight: null,
            publishedAt: null,
            createdAt: new \DateTimeImmutable('2026-08-02 06:00:00'),
            effectiveDate: new \DateTimeImmutable('2026-08-02 05:00:00'),
        );
    }
}
```

Check `EntryLine`'s constructor in `backend/src/Service/Backup/Dto/EntryLine.php` before running: the named arguments above follow `tests/Service/Backup/EntryBatchInserterTest.php::entryLine()`; copy that helper's argument list verbatim if it differs.

- [ ] **Step 3: Run both to verify they fail**

Run: `php bin/phpunit tests/Service/Backup/RestoreFeedTargetTest.php tests/Service/Backup/RestoreEntryLoaderTest.php`
Expected: `RestoreFeedTargetTest` errors with `Call to undefined method … learn()`. `RestoreEntryLoaderTest` fails the `once()` expectation on `entryIdsByGuidHash` (never called) and the `never()` expectation on `guidHashToIdMapForFeed`; the second test fails because no exception is thrown.

- [ ] **Step 4: Rewrite `RestoreFeedTarget`**

Replace the whole class body of `backend/src/Service/Backup/RestoreFeedTarget.php`:

```php
/**
 * Everything the entry and entry-state phases need to know about one feed,
 * captured as scalars before the entity manager is cleared: the feed's id,
 * whether this restore may create entries in it, and the guid hash ⇒ entry id
 * map that both de-duplicates inserts and resolves entry states.
 *
 * `acceptsNewEntries` is false as soon as any OTHER account subscribes to the
 * feed. A restore may not push articles into a stranger's unread list, so on
 * a shared feed the file's entries are dropped and the states that referenced
 * them are dropped with them.
 *
 * Mutable by design — it is a per-pass working set, never a shared service.
 */
final class RestoreFeedTarget
{
    /** @param array<string, int> $entryIdsByGuidHash the feed's rows before the load; every batch insert adds its own */
    public function __construct(
        public readonly int $feedId,
        public readonly bool $acceptsNewEntries,
        private array $entryIdsByGuidHash,
    ) {
    }

    public function knowsEntry(string $guidHash): bool
    {
        return isset($this->entryIdsByGuidHash[$guidHash]);
    }

    public function entryId(string $guidHash): ?int
    {
        return $this->entryIdsByGuidHash[$guidHash] ?? null;
    }

    /**
     * @param array<string, int> $entryIdsByGuidHash the ids read back for the rows one batch just inserted
     */
    public function learn(array $entryIdsByGuidHash): void
    {
        $this->entryIdsByGuidHash += $entryIdsByGuidHash;
    }
}
```

- [ ] **Step 5: Rewrite the loader's insert path**

In `backend/src/Service/Backup/RestoreEntryLoader.php`:

Delete the field at lines 45–46 (`private bool $bufferedFeedGotInserts = false;`).

Replace `closeBufferedFeed()` (lines 155–167):

```php
    private function closeBufferedFeed(): void
    {
        if ('' === $this->bufferedFeedUrl) {
            return;
        }

        $this->insertBufferedEntries();
        $this->bufferedFeedUrl = '';
    }
```

Replace `insertBufferedEntries()` (lines 169–195):

```php
    private function insertBufferedEntries(): void
    {
        $lines = $this->bufferedLines;
        $this->bufferedLines = [];
        if ([] === $lines) {
            return;
        }

        $target = $this->target($this->bufferedFeedUrl);
        if (!$target->acceptsNewEntries) {
            return;
        }

        $fresh = $this->unknownOf($lines, $target);
        if ([] === $fresh) {
            return;
        }

        try {
            $this->inserter->insert($target->feedId, $fresh);
        } catch (DbalException $e) {
            throw BackupLoadFailedException::from($e);
        }

        $this->entriesCreated += \count($fresh);
        $this->recordCreatedIds($target, $fresh);
    }
```

Replace `unknownOf()` (lines 197–217). Keying by hash collapses a guid the file repeats inside one batch; a repeat across batches is caught by `knowsEntry()` because the earlier batch's ids were learned before this one was built.

```php
    /**
     * EntryBatchInserter's contract puts de-duplication on its caller, so this
     * drops both the hashes the feed already holds and any the file repeats.
     *
     * @param list<EntryLine> $lines
     *
     * @return list<EntryLine>
     */
    private function unknownOf(array $lines, RestoreFeedTarget $target): array
    {
        $freshByHash = [];
        foreach ($lines as $line) {
            if (!$target->knowsEntry($line->guidHash)) {
                $freshByHash[$line->guidHash] = $line;
            }
        }

        return array_values($freshByHash);
    }
```

Replace `recordCreatedIds()` (lines 219–224). The multi-row INSERT yields no per-row lastInsertId, so the ids are read back by the hashes just written — at most one batch of them (#456).

```php
    /**
     * @param non-empty-list<EntryLine> $inserted
     */
    private function recordCreatedIds(RestoreFeedTarget $target, array $inserted): void
    {
        $hashes = array_map(static fn (EntryLine $line): string => $line->guidHash, $inserted);
        $idsByHash = $this->entries->entryIdsByGuidHash($target->feedId, $hashes);
        if (\count($idsByHash) !== \count($inserted)) {
            throw new \LogicException('An entry this restore just wrote cannot be read back.');
        }

        $target->learn($idsByHash);
        foreach ($idsByHash as $entryId) {
            $this->createdEntryIds[$entryId] = true;
        }
    }
```

Update the class docblock's last sentence (line 26–29) only if it still reads true; it does — the maps still arrive as the scalar target set. Leave it.

- [ ] **Step 6: Run to green**

Run: `php bin/phpunit tests/Service/Backup tests/Repository/EntryIdsByGuidHashTest.php`
Expected: all pass, including every existing `AccountRestorerTest` and `GoldenBackupRestoreTest` case, unchanged.

Then: `composer cs && composer stan && composer md && composer tramp` — clean. If PHPStan flags the `array_map` closure's return type, annotate `$hashes` with `/** @var list<string> $hashes */` and say why in one clause.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Backup/RestoreFeedTarget.php backend/src/Service/Backup/RestoreEntryLoader.php backend/tests/Service/Backup/RestoreFeedTargetTest.php backend/tests/Service/Backup/RestoreEntryLoaderTest.php
git commit -m "perf(#456): read restored entry ids back per batch instead of per feed"
```

Commit body (design rationale belongs in the commit):

```
The post-insert id lookup re-read every (guid_hash, id) pair of the feed
and diffed it against the pre-load snapshot to learn which ids were new.
For a feed at the 2000-entry cap with a handful of new rows in the file
that is a full re-read to find a small delta; across 115 feeds it is
~460,000 rows.

insertBufferedEntries() knows the hashes it just wrote, so the read-back
now asks for exactly those (at most one batch of 500) and merges the ids
into the target's map. With ids learned per batch, one map answers both
"do I know this hash?" and "what is its id?", so the insertedGuidHashes
side set, absorb() and the bufferedFeedGotInserts flag are gone.

A read-back that returns fewer ids than rows inserted is a LogicException:
the alternative is an entry that exists but never reaches the search
index and can carry no state, silently.
```

---

### Task 3: Round-trip safety net — mixed feed and a feed wider than one batch

The existing round-trip cases are all-new (`testRestoreOntoAnEmptyInstanceCreatesFeedsAndEntries`) or all-known (`testRoundTripReproducesTheAccountFieldForField`). Neither exercises a feed that is *partly* known, and none crosses the 500-row batch inside one feed — the two situations in which "merge the batch's ids into the snapshot" differs from "replace the snapshot with the full re-read". These two tests pass before and after Task 2; they are the net the issue asks for.

**Files:**
- Modify: `backend/tests/Service/Backup/AccountRestorerTest.php` (add two tests after `testARestoreCanBeRerunAfterItself`, line 748–777; add one seeding helper next to `seedRichAccountForCappedTarget`)

**Interfaces:**
- Consumes: the file's own helpers `seededUser()`, `backupOf()`, `restorer()`, `reloadUser()`, `entryStateRows()`, `scalarInt()`, `makeEntry()`, `deleteEveryFeed()`, and the constants `ONE_URL` / `TWO_URL`.

- [ ] **Step 1: Add the mixed-feed test**

Feed ONE keeps `guid-b` but loses `guid-a`. Both carry a state in the file (the seed gives `guid-a` one; the test adds one on `guid-b`), so the same feed has to resolve one state against an id read back after its insert and one against an id from the pre-load snapshot. A `learn()` that replaced the snapshot instead of merging into it would lose `guid-b`'s.

```php
    public function testStatesResolveAgainstBothTheKeptAndTheRecreatedRowsOfOneFeed(): void
    {
        $user = $this->seededUser('mixed-feed@example.com');
        $userId = (int) $user->getId();
        $kept = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'guid-b']);
        self::assertInstanceOf(Entry::class, $kept);
        $this->em->persist(new EntryState($user, $kept));
        $this->em->flush();
        $gzip = $this->backupOf($user);
        $statesBefore = $this->entryStateRows($userId);
        $this->em->getConnection()->executeStatement('DELETE FROM entry WHERE guid = ?', ['guid-a']);
        $this->em->clear();

        $result = $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        // guid-b and guid-c survive as shared rows; only guid-a is recreated.
        // Feed ONE's two states land on one new id and one old id.
        self::assertSame(1, $result->entries);
        self::assertSame(3, $result->entryStates);
        self::assertSame(3, $this->scalarInt('SELECT COUNT(*) FROM entry'));
        self::assertSame($statesBefore, $this->entryStateRows($userId));
    }
```

- [ ] **Step 2: Add the multi-batch test and its seeding helper**

`RestoreEntryLoader::BATCH` is 500. Feed ONE gets 499 extra entries, so it carries 501 and the loader inserts it in two batches; the state on `guid-a` (first batch) and the one added on the last extra entry (second batch) both have to resolve.

```php
    public function testAFeedWiderThanOneInsertBatchKeepsEveryBatchesIds(): void
    {
        $user = $this->seededUser('two-batches@example.com');
        $userId = (int) $user->getId();
        $this->seedEntriesBeyondOneBatch($user);
        $gzip = $this->backupOf($user);
        $statesBefore = $this->entryStateRows($userId);
        $this->deleteEveryFeed();

        $result = $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(502, $result->entries);
        self::assertSame(3, $result->entryStates);
        self::assertSame($statesBefore, $this->entryStateRows($userId));
    }
```

Helper, placed next to `seedRichAccountForCappedTarget()`:

```php
    /**
     * Feed ONE grows to 501 entries — one more than RestoreEntryLoader::BATCH
     * — with a state on the last of them, so the restore's second insert
     * batch has a state to resolve against ids read back after the first.
     */
    private function seedEntriesBeyondOneBatch(User $user): void
    {
        $one = $this->em->getRepository(Feed::class)->findOneBy(['url' => self::ONE_URL]);
        self::assertInstanceOf(Feed::class, $one);
        $last = null;
        for ($i = 0; $i < 499; ++$i) {
            $last = $this->makeEntry($one, sprintf('guid-wide-%03d', $i), 'Wide ' . $i, '2026-08-08');
        }
        self::assertInstanceOf(Entry::class, $last);
        $this->em->persist(new EntryState($user, $last));
        $this->em->flush();
    }
```

- [ ] **Step 3: Run the two new tests and the whole backup suite**

Run: `php bin/phpunit tests/Service/Backup`
Expected: green. If the multi-batch test takes more than a few seconds on SQLite, keep it — `EntryBatchInserterTest` already inserts 501 rows on every run, and this one is the only proof the loader's batch boundary is crossed.

- [ ] **Step 4: Verify the tests guard the change (break what they guard)**

Temporarily make `RestoreFeedTarget::learn()` *replace* the map (`$this->entryIdsByGuidHash = $entryIdsByGuidHash;`) and run `php bin/phpunit tests/Service/Backup/AccountRestorerTest.php`. Expected: `testStatesResolveAgainstBothTheKeptAndTheRecreatedRowsOfOneFeed` fails with `entryStates` 2 instead of 3 (feed ONE's map now holds only guid-a, so guid-b's state has nothing to attach to) and `testAFeedWiderThanOneInsertBatchKeepsEveryBatchesIds` fails the same way (the second batch's map drops guid-a, which the exporter placed in the first batch because it walks entries by id). Revert the sabotage. Do not commit it.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Service/Backup/AccountRestorerTest.php
git commit -m "test(#456): restore a partly known feed and one wider than an insert batch"
```

---

### Task 4: One feed lookup for the whole file (#455)

Independent of Tasks 1–3; it touches `RestoreLoadPass`, which they do not.

**Files:**
- Modify: `backend/src/Repository/FeedRepository.php` (add one method after `subscribedUrlSetForUser`, line 59–75)
- Modify: `backend/src/Service/Backup/RestoreLoadPass.php:41-42`, `:136-184`, `:218-237`
- Create: `backend/tests/Repository/FeedsByUrlTest.php`
- Modify: `backend/tests/Service/Backup/RestoreLoadPassTest.php` (class docblock, one test, two helpers)
- Modify: `backend/tests/Service/Backup/AccountRestorerTest.php` (one test after `testAFeedRowAnotherUserReadsIsNotModified`, line 684–705)

**Interfaces:**
- Produces: `FeedRepository::findByUrlsIndexedByUrl(array $urls): array` — `@param list<string> $urls`, `@return array<string, Feed>` url ⇒ Feed for the urls that have a row; an empty list returns `[]` without a query.
- Produces: `RestoreLoadPass::holdFeed(FeedLine)`, `resolveHeldFeeds()`, `createFeed(FeedLine): Feed` (all private). `loadFeed()` is gone.

- [ ] **Step 1: Write the failing repository test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;

/**
 * The restore's one feed lookup for a whole file (#455).
 */
final class FeedsByUrlTest extends DbTestCase
{
    public function testReturnsOnlyTheAskedUrlsIndexedByUrl(): void
    {
        $one = new Feed('https://one.example/feed.xml');
        $this->em->persist($one);
        $this->em->persist(new Feed('https://two.example/feed.xml'));
        $this->em->flush();

        $byUrl = $this->repository()->findByUrlsIndexedByUrl([
            'https://one.example/feed.xml',
            'https://never.example/feed.xml',
        ]);

        self::assertSame(['https://one.example/feed.xml' => $one], $byUrl);
    }

    public function testAnEmptyListAsksForNothing(): void
    {
        self::assertSame([], $this->repository()->findByUrlsIndexedByUrl([]));
    }

    private function repository(): FeedRepository
    {
        $repository = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Repository/FeedsByUrlTest.php`
Expected: both tests error with `Call to undefined method App\Repository\FeedRepository::findByUrlsIndexedByUrl()`.

- [ ] **Step 3: Implement the repository method**

Add after `subscribedUrlSetForUser()` in `backend/src/Repository/FeedRepository.php`:

```php
    /**
     * The feed rows behind a set of urls, indexed by url — one query for the
     * whole set a restore file declares (#455). A url with no row is absent.
     *
     * @param list<string> $urls
     *
     * @return array<string, Feed>
     */
    public function findByUrlsIndexedByUrl(array $urls): array
    {
        if ([] === $urls) {
            return [];
        }

        /** @var list<Feed> $feeds */
        $feeds = $this->createQueryBuilder('f')
            ->andWhere('f.url IN (:urls)')
            ->setParameter('urls', $urls)
            ->getQuery()
            ->getResult();

        $byUrl = [];
        foreach ($feeds as $feed) {
            $byUrl[$feed->getUrl()] = $feed;
        }

        return $byUrl;
    }
```

Run: `php bin/phpunit tests/Repository/FeedsByUrlTest.php` — green.

- [ ] **Step 4: Write the failing load-pass unit test**

Add to `backend/tests/Service/Backup/RestoreLoadPassTest.php`. Add the imports `App\Entity\Feed`, `App\Service\Backup\Dto\FeedLine`, `App\Service\Backup\Dto\SubscriptionLine`. Replace the first sentence of the class docblock ("A narrow unit test for the one branch AccountRestorerTest can no longer reach through content:") with "Two narrow unit tests. The first pins the one-query feed lookup of #455 (AccountRestorerTest proves the same path end to end). The second covers the one branch AccountRestorerTest can no longer reach through content:" and keep the rest.

```php
    public function testTheFeedLookupRunsOnceForTheWholeFileAndCreatesWhatItMisses(): void
    {
        $user = new User('one-lookup@example.com', new \DateTimeImmutable('2026-08-01'));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getReference')->willReturn($user);
        $feeds = $this->createMock(FeedRepository::class);
        $feeds->expects(self::once())
            ->method('findByUrlsIndexedByUrl')
            ->with(['https://known.example/feed.xml', 'https://new.example/feed.xml'])
            ->willReturn(['https://known.example/feed.xml' => new Feed('https://known.example/feed.xml')]);
        $feeds->expects(self::never())->method('findOneBy');
        $pass = new RestoreLoadPass(
            $em,
            $feeds,
            $this->createStub(EntryRepository::class),
            $this->harmlessEntryLoader($em),
        );

        $result = $pass->run($user, (function () {
            yield $this->feedLine('https://known.example/feed.xml');
            yield $this->feedLine('https://new.example/feed.xml');
            yield $this->subscriptionLine('https://known.example/feed.xml');
            yield $this->subscriptionLine('https://new.example/feed.xml');
        })());

        self::assertSame(1, $result->feeds);
        self::assertSame(2, $result->subscriptions);
    }

    private function feedLine(string $url): FeedLine
    {
        return new FeedLine(
            url: $url,
            siteUrl: null,
            title: null,
            description: null,
            faviconUrl: null,
            imageUrl: null,
            sourceFormat: 'xml',
        );
    }

    private function subscriptionLine(string $feedUrl): SubscriptionLine
    {
        return new SubscriptionLine(
            feedUrl: $feedUrl,
            customTitle: null,
            position: 0,
            markedReadUntil: null,
            createdAt: new \DateTimeImmutable('2026-07-01 08:00:00'),
            tags: [],
            includeInAllItems: true,
            includeInForYou: true,
        );
    }
```

Why the stubs hold: the stubbed `EntityManagerInterface` ignores `persist()`, `flush()` and `clear()`; `getReference()` returns the user so `startEntryPhase()` survives its own `?? throw`. The stubbed `EntryRepository` returns `[]` for `guidHashToIdMapForFeed()` (PHPUnit's default for an `array` return type), and `isReadByAnotherUser()` on the mock returns `false` the same way. Also rename the existing helper's docblock sentence "it is never actually called in this scenario" to "it is never actually called in the flush-fails scenario", since the new test does reach it.

- [ ] **Step 5: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Backup/RestoreLoadPassTest.php`
Expected: the new test fails on the `once()` expectation for `findByUrlsIndexedByUrl` (never called) and on `never()` for `findOneBy` (called twice).

- [ ] **Step 6: Rewrite the feed half of `RestoreLoadPass`**

In `backend/src/Service/Backup/RestoreLoadPass.php`:

Add a field after `$feedsByUrl` (line 41–42):

```php
    /** @var list<FeedLine> held back until one lookup resolves them all (#455) */
    private array $heldFeedLines = [];
```

In `accept()` (line 94), change the arm `$line instanceof FeedLine => $this->loadFeed($line),` to `$line instanceof FeedLine => $this->holdFeed($line),`.

Replace `loadFeed()` and its docblock (lines 136–162) with:

```php
    private function holdFeed(FeedLine $line): void
    {
        $this->heldFeedLines[] = $line;
    }

    /**
     * BackupReader puts every feed line before the first subscription, so by
     * the time anything needs a Feed the file's whole set is known and one
     * query resolves it (#455).
     *
     * A feed row is shared between accounts, so a known one is referenced and
     * never touched — not even to improve a null title. sourceFormat is
     * therefore written only on a row this restore creates, which is
     * SubscriptionCreator's trust rule at its strictest: a value asserted by
     * an uploaded file may not overwrite what the instance already learned.
     */
    private function resolveHeldFeeds(): void
    {
        $lines = $this->heldFeedLines;
        $this->heldFeedLines = [];
        if ([] === $lines) {
            return;
        }

        $urls = array_map(static fn (FeedLine $line): string => $line->url, $lines);
        $this->feedsByUrl += $this->feeds->findByUrlsIndexedByUrl($urls);
        foreach ($lines as $line) {
            $this->feedsByUrl[$line->url] ??= $this->createFeed($line);
        }
    }

    private function createFeed(FeedLine $line): Feed
    {
        $feed = new Feed($line->url);
        $feed->setSiteUrl($line->siteUrl);
        $feed->setTitle($line->title);
        $feed->setDescription($line->description);
        $feed->setFaviconUrl($line->faviconUrl);
        $feed->setImageUrl($line->imageUrl);
        $feed->setSourceFormat($line->sourceFormat);
        $this->em->persist($feed);
        ++$this->counts['feeds'];

        return $feed;
    }
```

Make `loadSubscription()` (line 164) start with `$this->resolveHeldFeeds();` before the `$feed = $this->feedsByUrl[...] ?? throw` line. In `startEntryPhase()` (line 218), add `$this->resolveHeldFeeds();` directly after `$this->entryPhaseStarted = true;` and before the `try { $this->em->flush(); }`, so a file that declares feeds but subscribes to none still creates them exactly as today.

- [ ] **Step 7: Run to green**

Run: `php bin/phpunit tests/Service/Backup tests/Repository/FeedsByUrlTest.php`
Expected: green, every existing restore test unchanged. Then `composer cs && composer stan && composer md && composer tramp` — clean. PHPMD: `RestoreLoadPass` gains one field (eleven with the constructor's four; the `TooManyFields` threshold is fifteen) and loses none of its method budget.

- [ ] **Step 8: Add the round-trip net**

In `AccountRestorerTest`, after `testAFeedRowAnotherUserReadsIsNotModified()`: the existing cases restore onto all-known feeds or all-missing feeds. This one mixes them in a single lookup — one url resolves, the other is created — and checks the known row keeps the title the file cannot improve.

```php
    public function testOneLookupReferencesTheKnownFeedAndCreatesTheMissingOne(): void
    {
        $user = $this->seededUser('mixed-feeds@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->backupOf($user);
        $before = $this->subscriptionShapes($userId);
        $this->em->getConnection()->executeStatement('DELETE FROM feed WHERE url = ?', [self::TWO_URL]);
        $this->em->clear();

        $result = $this->restorer()->restore($this->reloadUser($userId), $gzip, 'REPLACE');

        self::assertSame(1, $result->feeds);
        self::assertSame(2, $result->subscriptions);
        self::assertSame(2, $this->scalarInt('SELECT COUNT(*) FROM feed'));
        self::assertSame($before, $this->subscriptionShapes($userId));
        $this->em->clear();
        $one = $this->em->getRepository(Feed::class)->findOneBy(['url' => self::ONE_URL]);
        self::assertInstanceOf(Feed::class, $one);
        self::assertSame('W/"seeded-etag"', $one->getEtag());
    }
```

The etag assertion is the "not touched" proof: the seed writes fetch bookkeeping the backup never carries, so a feed row this restore *created* would have a null etag (`testRestoreOntoAnEmptyInstanceCreatesFeedsAndEntries` asserts exactly that for the other direction).

Run: `php bin/phpunit tests/Service/Backup/AccountRestorerTest.php` — green.

- [ ] **Step 9: Verify the net (break what it guards)**

Temporarily make `resolveHeldFeeds()` skip the lookup (`$this->feedsByUrl += [];`) and run `AccountRestorerTest`. Expected: `testOneLookupReferencesTheKnownFeedAndCreatesTheMissingOne` fails on the unique `feed.url` index (feed ONE inserted twice) or on `feeds` being 2, and `testRoundTripReproducesTheAccountFieldForField` fails on `feeds` being 2 instead of 0. Revert.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Repository/FeedRepository.php backend/src/Service/Backup/RestoreLoadPass.php backend/tests/Repository/FeedsByUrlTest.php backend/tests/Service/Backup/RestoreLoadPassTest.php backend/tests/Service/Backup/AccountRestorerTest.php
git commit -m "perf(#455): resolve a restore's feed rows in one query"
```

Commit body:

```
RestoreLoadPass looked a feed row up once per feed line, 115 indexed
round trips for the baseline account. BackupReader already guarantees
that every feed line precedes the first subscription, so the pass now
holds the feed lines back and resolves the whole set with one
WHERE url IN (...) query at the first subscription (or at the entry
phase, for a file that declares feeds and subscribes to none).

The url set from pass 1 is deliberately not threaded through
AccountRestorer and RestoreLoader: pass 2 reads the same lines in the
same order, and carrying the inventory across three classes would be
exactly the tramp chain phptramp exists to refuse.
```

---

### Task 5: Gates, MySQL leg, PR

**Files:** none new.

- [ ] **Step 1: Full native suite and every gate**

From `backend/`:

```bash
php bin/phpunit && composer check && composer md && composer infection:diff
```

Expected: green; Infection at or above `minMsi: 80` on the touched lines. Escaped mutants worth expecting and their killers: the `count() !==` guard (killed by `testAReadBackThatMissesARowItJustWroteIsALogicError`), the `+=` in `learn()` (killed by `testLearningKeepsWhatEarlierBatchesTaught`), the two `[] === …` guards in the repositories (killed by each `testAnEmptyListAsksForNothing`), the `??=` in `resolveHeldFeeds()` (killed by `testTheFeedLookupRunsOnceForTheWholeFileAndCreatesWhatItMisses`, which would see `feeds` become 2).

- [ ] **Step 2: MySQL leg**

The two `IN (…)` lists carry up to 500 bound parameters. Run from the repo root, with the stack up and current (check the container is current before trusting it):

```bash
docker compose exec php vendor/bin/phpunit tests/Service/Backup tests/Repository/EntryIdsByGuidHashTest.php tests/Repository/FeedsByUrlTest.php
```

Expected: green.

- [ ] **Step 3: PhpStorm inspections on the changed PHP**

`mcp__phpstorm__lint_files` on `RestoreEntryLoader.php`, `RestoreFeedTarget.php`, `RestoreLoadPass.php`, `EntryRepository.php`, `FeedRepository.php` and the six test files. Block on ERROR and WARNING.

- [ ] **Step 4: Scan the dev log**

`ls -t backend/var/log/dev-*.log | head -1` — no new deprecation or swallowed error from the restore path.

- [ ] **Step 5: Push and open the PR against `develop`**

```bash
gh issue reopen 455
git push -u origin fix/456-restore-batched-lookups
```

PR title: `perf(#456, #455): batch the restore's feed lookup and post-insert id read-back`. Body: the commit rationales from Tasks 2 and 4, the before/after query shapes (`SELECT guid_hash, id FROM entry WHERE feed_id = ?` once per feed with inserts → `… AND guid_hash IN (≤500)` once per insert batch; `SELECT … FROM feed WHERE url = ?` once per feed line → `… WHERE url IN (…)` once per file), the three new round-trip tests, and `Closes #456` plus `Closes #455`. After the merge, verify both issues closed by themselves.

---

## Self-review

- **Spec coverage, #456.** Scoped query: Task 1. Merge instead of re-pull-and-diff: Task 2. Bounded by batch size: `recordCreatedIds()` runs per `insertBufferedEntries()` call, which holds at most `BATCH` lines. First snapshot untouched: global constraint. Round-trip tests as the safety net: Task 3 plus the unchanged existing suite.
- **Spec coverage, #455.** One `WHERE url IN (:urls)` query: Task 4 Step 3. Built from the file's declared set before any Feed is needed: Step 6 holds the lines and resolves at the first subscription or the entry phase, which the reader's ordering makes the same moment pass 1's set would have been complete. Fallback `new Feed($url)` for absent urls: `createFeed()`. The issue's "lazily on the first FeedLine" variant is impossible without the inventory (the set is not complete at the first feed line); the plan says why the inventory is not threaded through.
- **Placeholders.** None. The `EntryLine` argument list is flagged as "copy from `EntryBatchInserterTest::entryLine()` if it differs" — a verification step, not a gap.
- **Type consistency.** `entryIdsByGuidHash(int, list<string>): array<string, int>` in Tasks 1 and 2; `learn(array<string, int>): void` in Task 2 and the sabotage step of Task 3; `recordCreatedIds(RestoreFeedTarget, non-empty-list<EntryLine>)` only inside Task 2; `findByUrlsIndexedByUrl(list<string>): array<string, Feed>` in Task 4 Steps 3, 4 and 6.
- **Risk noted for the reviewer.** `unknownOf()` no longer mutates the target before the insert. Its within-batch dedupe now rests on keying `$freshByHash` by hash, and cross-batch dedupe on `learn()` having run before the next batch is built. `testAFeedWiderThanOneInsertBatchKeepsEveryBatchesIds` covers the second; the first cannot be reached through the real exporter (it never writes a guid twice) and is pinned by the `array_values($freshByHash)` shape alone — if a reviewer wants it proven, `RestoreEntryLoaderTest` can buffer the same `EntryLine` twice and expect one `entryIdsByGuidHash` call with one hash.
