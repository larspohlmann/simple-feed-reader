<?php

declare(strict_types=1);

namespace App\Tests\Service\Retention;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\Retention\EntryPruner;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class EntryPrunerTest extends DbTestCase
{
    /** EntryPruner's private floor: a feed filled to it puts any older entry seeded afterwards past it. */
    private const int FLOOR = 20;

    /** Above the floor, so the pruner's clamp cannot mask the cap boundary a test exercises. */
    private const int CAP_ABOVE_THE_FLOOR = self::FLOOR + 2;

    private EntryPruner $pruner;
    private MockClock $clock;
    private RecordingSearchIndexWriter $indexWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer());
    }

    private function indexer(): EntryIndexer
    {
        return new EntryIndexer($this->indexWriter, new NullLogger());
    }

    private function retention(): RetentionRepository
    {
        return new RetentionRepository($this->entityManager, new RowIds($this->entityManager));
    }

    private function daysAgo(int $days): \DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('-%d days', $days));
    }

    /**
     * A feed with `$count` entries, all fetched at `$createdAt` (defaults to
     * now). Entries rank newest-first by insertion order for callers that
     * care about which ones a floor or a cap would drop.
     */
    private function feedWithEntries(int $count, ?\DateTimeImmutable $createdAt = null): Feed
    {
        $feed = new Feed('https://example.com/feed-' . uniqid('', true));
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        $fetchedAt = $createdAt ?? $this->clock->now();
        for ($index = 0; $index < $count; ++$index) {
            $this->persistEntry($feed, sprintf('entry-%d', $index), $fetchedAt);
        }
        $this->entityManager->flush();

        return $feed;
    }

    /**
     * One extra entry on an existing feed, fetched at `$createdAt` and sorted
     * at `$effectiveDate` (defaults to `$createdAt`).
     */
    private function seedEntry(
        Feed $feed,
        string $guid,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $effectiveDate = null,
    ): Entry {
        $entry = $this->persistEntry($feed, $guid, $createdAt, $effectiveDate);
        $this->entityManager->flush();

        return $entry;
    }

    private function persistEntry(
        Feed $feed,
        string $guid,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $effectiveDate = null,
    ): Entry {
        $entry = new Entry($feed, $guid, null, 'Title ' . $guid, $createdAt, $effectiveDate ?? $createdAt);
        $entry->setPublishedAt($createdAt);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function findByGuid(Feed $feed, string $guid): ?Entry
    {
        return $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed, 'guid' => $guid]);
    }

    /** @return list<Entry> */
    private function findAllEntries(Feed $feed): array
    {
        return $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $feed]);
    }

    /** @return list<string> */
    private function remainingGuids(Feed $feed): array
    {
        return array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            $this->findAllEntries($feed),
        );
    }

    /** @return list<string> */
    private static function entryGuids(int $first, int $last): array
    {
        return array_map(static fn (int $index): string => "entry-{$index}", range($first, $last));
    }

    public function testKeepsAnOldArticleThatWasFetchedRecently(): void
    {
        $feed = $this->feedWithEntries(self::FLOOR, $this->daysAgo(1));
        $this->seedEntry($feed, 'archive', $this->daysAgo(2), $this->daysAgo(2000));

        $this->pruner->prune();

        self::assertNotNull($this->findByGuid($feed, 'archive'));
    }

    /** One burst shares a `createdAt`, so the floor breaks the tie by id: the lowest ids, fetched first, go. */
    public function testDeletesAnArticleFetchedBeforeTheRetentionWindow(): void
    {
        $feed = $this->feedWithEntries(self::FLOOR + 10, $this->daysAgo(100));

        self::assertSame(10, $this->pruner->prune());
        self::assertEqualsCanonicalizing(self::entryGuids(10, self::FLOOR + 9), $this->remainingGuids($feed));
    }

    /** Only the cutoff separates the stale entry from the recent one past the floor. */
    public function testAgePassDeletesOnlyTheEntryPastTheCutoff(): void
    {
        $feed = $this->feedWithEntries(self::FLOOR + 1, $this->daysAgo(1));
        $this->seedEntry($feed, 'stale', $this->daysAgo(100));

        self::assertSame(1, $this->pruner->prune());
        self::assertNull($this->findByGuid($feed, 'stale'));
        self::assertCount(self::FLOOR + 1, $this->findAllEntries($feed));
    }

    /** A bulk DELETE fires no ORM event, so the pruner forgets the ids itself: exactly the ids the DELETE removed. */
    public function testPruningTellsTheIndexToForgetExactlyTheDeletedIds(): void
    {
        $feed = $this->feedWithEntries(self::FLOOR + 10, $this->daysAgo(100));
        $idsByGuid = [];
        foreach ($this->findAllEntries($feed) as $entry) {
            $idsByGuid[$entry->getGuid()] = $entry->getId();
        }
        $expectedForgottenIds = array_map(
            static fn (int $index): int => (int) $idsByGuid["entry-{$index}"],
            range(0, 9),
        );

        $this->pruner->prune();

        self::assertEqualsCanonicalizing($expectedForgottenIds, array_merge(...$this->indexWriter->forgets));
    }

    /**
     * An unreachable search engine must never fail a prune: EntryIndexer swallows the outage, and this pins the wiring.
     */
    public function testPruningSucceedsEvenWhenTheIndexIsUnreachable(): void
    {
        $feed = $this->feedWithEntries(self::FLOOR + 10, $this->daysAgo(100));
        $failingWriter = new RecordingSearchIndexWriter(new SearchEngineUnavailableException('down'));
        $pruner = new EntryPruner($this->retention(), $this->clock, new EntryIndexer($failingWriter, new NullLogger()));

        self::assertSame(10, $pruner->prune());
        self::assertEqualsCanonicalizing(self::entryGuids(10, self::FLOOR + 9), $this->remainingGuids($feed));
    }

    public function testNeverDeletesAFeedsNewestEntriesWithinTheFloor(): void
    {
        $feed = $this->feedWithEntries(self::FLOOR + 5, $this->daysAgo(100));

        $this->pruner->prune();

        self::assertEqualsCanonicalizing(self::entryGuids(5, self::FLOOR + 4), $this->remainingGuids($feed));
    }

    public function testAFeedOfOldEntriesAtTheFloorLosesNone(): void
    {
        $this->feedWithEntries(self::FLOOR, $this->daysAgo(100));

        self::assertSame(0, $this->pruner->prune());
    }

    /** Two feeds one old entry past the floor each lose entry-0; the total sums both feeds, not the last one. */
    public function testAgePassSumsDeletionsAcrossFeeds(): void
    {
        $feedA = $this->feedWithEntries(self::FLOOR + 1, $this->daysAgo(100));
        $feedB = $this->feedWithEntries(self::FLOOR + 1, $this->daysAgo(100));

        self::assertSame(2, $this->pruner->prune());
        self::assertEqualsCanonicalizing(self::entryGuids(1, self::FLOOR), $this->remainingGuids($feedA));
        self::assertEqualsCanonicalizing(self::entryGuids(1, self::FLOOR), $this->remainingGuids($feedB));
    }

    /**
     * Two separate feeds, each one entry past the cap: the total must be the
     * sum across feeds, not just the last feed scanned.
     */
    public function testCapPassSumsDeletionsAcrossFeeds(): void
    {
        $cap = self::CAP_ABOVE_THE_FLOOR;
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);

        $this->feedWithEntries($cap + 1, $this->daysAgo(1));
        $this->feedWithEntries($cap + 1, $this->daysAgo(1));

        self::assertSame(2, $pruner->prune());
    }

    /**
     * `$cap + 2` entries sharing one `createdAt` (a burst fetch): the cap
     * keeps the highest ids and drops the two lowest, tie-broken by id
     * alone.
     */
    public function testCapPassBreaksATieById(): void
    {
        $cap = self::CAP_ABOVE_THE_FLOOR;
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);

        $feed = $this->feedWithEntries($cap + 2, $this->daysAgo(1));

        self::assertSame(2, $pruner->prune());
        self::assertEqualsCanonicalizing(self::entryGuids(2, $cap + 1), $this->remainingGuids($feed));
    }

    public function testPrunesOldEntriesButKeepsProtectedAndRecent(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $feed = $this->feedWithEntries(self::FLOOR, $this->daysAgo(5));

        $old = $this->daysAgo(120);
        $this->seedEntry($feed, 'old-plain', $old);
        $favorite = $this->seedEntry($feed, 'old-favorite', $old);
        $kept = $this->seedEntry($feed, 'old-kept', $old);
        $oldButRead = $this->seedEntry($feed, 'old-read', $old);

        $favoriteState = new EntryState($user, $favorite);
        $favoriteState->markFavorite();
        $keptState = new EntryState($user, $kept);
        $keptState->markKept();
        $readState = new EntryState($user, $oldButRead);
        $readState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($favoriteState);
        $this->entityManager->persist($keptState);
        $this->entityManager->persist($readState);
        $this->entityManager->flush();

        $pruned = $this->pruner->prune();

        self::assertSame(2, $pruned);
        $remainingGuids = array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            array_filter($this->findAllEntries($feed), static fn (Entry $entry): bool => !str_starts_with(
                $entry->getGuid(),
                'entry-',
            )),
        );
        sort($remainingGuids);
        self::assertSame(['old-favorite', 'old-kept'], $remainingGuids);
    }

    public function testProtectionAppliesAcrossUsers(): void
    {
        $alice = new User('alice@example.com', $this->clock->now());
        $bob = new User('bob@example.com', $this->clock->now());
        $this->entityManager->persist($alice);
        $this->entityManager->persist($bob);

        $feed = $this->feedWithEntries(self::FLOOR, $this->daysAgo(5));
        $shared = $this->seedEntry($feed, 'shared', $this->daysAgo(200));

        $aliceRead = new EntryState($alice, $shared);
        $aliceRead->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $bobKept = new EntryState($bob, $shared);
        $bobKept->markKept();
        $this->entityManager->persist($aliceRead);
        $this->entityManager->persist($bobKept);
        $this->entityManager->flush();

        self::assertSame(0, $this->pruner->prune());
        self::assertNotNull($this->findByGuid($feed, 'shared'));
    }

    public function testDeletingEntryRemovesItsStateRows(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);

        $feed = $this->feedWithEntries(self::FLOOR, $this->daysAgo(5));
        $doomed = $this->seedEntry($feed, 'doomed', $this->daysAgo(200));
        $state = new EntryState($user, $doomed);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        self::assertSame(1, $this->pruner->prune());
        self::assertCount(0, $this->entityManager->getRepository(EntryState::class)->findAll());
    }

    public function testEntryWithoutPublishedAtUsesCreatedAt(): void
    {
        $feed = $this->feedWithEntries(self::FLOOR, $this->daysAgo(5));
        $undatedCreatedAt = $this->daysAgo(200);
        $undated = new Entry($feed, 'undated', null, 'No date', $undatedCreatedAt, $undatedCreatedAt);
        $this->entityManager->persist($undated);
        $this->entityManager->flush();

        self::assertSame(1, $this->pruner->prune());
    }

    public function testRecentUndatedEntrySurvives(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->entityManager->persist($feed);
        $freshCreatedAt = $this->daysAgo(2);
        $fresh = new Entry($feed, 'fresh-undated', null, 'No date', $freshCreatedAt, $freshCreatedAt);
        $this->entityManager->persist($fresh);
        $this->entityManager->flush();

        self::assertSame(0, $this->pruner->prune());
    }

    public function testNothingToPruneReturnsZero(): void
    {
        self::assertSame(0, $this->pruner->prune());
    }

    public function testCapsEntriesPerFeedKeepingNewestAndProtected(): void
    {
        $cap = self::CAP_ABOVE_THE_FLOOR;
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);

        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);

        // `$cap` recent filler entries hold the feed at the cap, so the two
        // older entries below both fall beyond the boundary.
        $feed = $this->feedWithEntries($cap, $this->daysAgo(1));
        $this->seedEntry($feed, 'old-unprotected', $this->daysAgo(3));
        $protected = $this->seedEntry($feed, 'old-protected', $this->daysAgo(2));

        // One of the two is kept, so it survives despite being beyond the cap;
        // its unprotected sibling is the only entry this prune may delete.
        $keptState = new EntryState($user, $protected);
        $keptState->markKept();
        $this->entityManager->persist($keptState);
        $this->entityManager->flush();

        self::assertSame(1, $pruner->prune());
        self::assertNull($this->findByGuid($feed, 'old-unprotected'));
        self::assertNotNull($this->findByGuid($feed, 'old-protected'));
    }

    /**
     * A read-but-not-favorited-or-kept entry is not protected: only
     * favoriting or keeping guards an entry, so the cap pass must still
     * delete it once it falls beyond the cap.
     */
    public function testCapPassDeletesEntryWithOnlyAReadState(): void
    {
        $cap = self::CAP_ABOVE_THE_FLOOR;
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);

        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);

        // `$cap` recent filler entries hold the feed at the cap, so the
        // older entry below falls beyond the boundary.
        $feed = $this->feedWithEntries($cap, $this->daysAgo(1));
        $oldest = $this->seedEntry($feed, 'oldest', $this->daysAgo(2));

        $readState = new EntryState($user, $oldest);
        $readState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($readState);
        $this->entityManager->flush();

        self::assertSame(1, $pruner->prune());
        self::assertNull($this->findByGuid($feed, 'oldest'));
    }

    /**
     * A protected entry still takes one of the newest `keep` ranking slots rather than standing outside the ranking,
     * so a favorite above `$cap + 1` older entries leaves two of them past the cap, not one.
     */
    public function testProtectedNewestEntryStillOccupiesARankingSlot(): void
    {
        $cap = self::CAP_ABOVE_THE_FLOOR;
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);

        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);

        // `$cap + 1` same-day entries, tie-broken by id, under one strictly newer favorite.
        $feed = $this->feedWithEntries($cap + 1, $this->daysAgo(2));
        $favorite = $this->seedEntry($feed, 'favorite-newest', $this->daysAgo(1));

        $favoriteState = new EntryState($user, $favorite);
        $favoriteState->markFavorite();
        $this->entityManager->persist($favoriteState);
        $this->entityManager->flush();

        self::assertSame(2, $pruner->prune());
        $expected = array_merge(['favorite-newest'], self::entryGuids(2, $cap));
        self::assertEqualsCanonicalizing($expected, $this->remainingGuids($feed));
    }

    public function testFeedAtOrUnderCapIsUntouched(): void
    {
        $cap = self::CAP_ABOVE_THE_FLOOR;
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);

        $atTheCap = $this->feedWithEntries($cap, $this->daysAgo(1));
        $underTheCap = $this->feedWithEntries($cap - 1, $this->daysAgo(1));

        self::assertSame(0, $pruner->prune());
        self::assertCount($cap, $this->findAllEntries($atTheCap));
        self::assertCount($cap - 1, $this->findAllEntries($underTheCap));
    }

    public function testPruningTheLastEntryOfACompletedRunAlsoDropsTheRun(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);

        $feed = $this->feedWithEntries(self::FLOOR, $this->daysAgo(5));
        $doomed = $this->seedEntry($feed, 'doomed', $this->daysAgo(200));

        $run = new RecommendationRun($user, $this->clock->now());
        $run->snapshot(RecommendationEngineKind::Llm, null, [[1]]);
        $run->complete($this->clock->now());
        $this->entityManager->persist($run);
        $this->entityManager->persist(new RecommendationItem($run, $doomed, 1, 'because'));
        $this->entityManager->flush();
        $runId = $run->getId();

        // The run left empty by the doomed entry's deletion is bookkeeping,
        // not an entry: it must not inflate the count the refresh summary
        // shows the user. Only the entry counts toward the total.
        $pruned = $this->pruner->prune();
        $this->entityManager->clear();

        self::assertSame(1, $pruned);
        self::assertNull($this->entityManager->getRepository(RecommendationRun::class)->find($runId));
    }

    /**
     * The run-deletion count must never leak into the entry count: a refresh
     * that removes zero entries but leaves several runs empty (their items'
     * entries pruned in an earlier pass) reports zero, not the run count.
     */
    public function testEmptyRunsAloneReportZeroPruned(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);

        for ($index = 0; $index < 3; $index++) {
            $run = new RecommendationRun($user, $this->clock->now());
            $run->snapshot(RecommendationEngineKind::Llm, null, [[1]]);
            $run->complete($this->clock->now());
            $this->entityManager->persist($run);
        }
        $this->entityManager->flush();

        $pruned = $this->pruner->prune();

        self::assertSame(0, $pruned);
    }

    public function testARunningRunWithNoItemsSurvivesPruning(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->entityManager->persist($user);
        $run = new RecommendationRun($user, $this->clock->now());
        $run->snapshot(RecommendationEngineKind::Llm, null, [[1]]);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $runId = $run->getId();
        $this->entityManager->clear();

        $this->pruner->prune();
        $this->entityManager->clear();

        self::assertNotNull($this->entityManager->getRepository(RecommendationRun::class)->find($runId));
    }

    /**
     * A `maxEntriesPerFeed` set below the floor is raised to it, which also keeps `rankBoundaryBeyond()`
     * from turning a `keep` of 0 into a negative `setFirstResult()`.
     */
    public function testCapBelowTheFloorIsClampedToTheFloor(): void
    {
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: 0);

        $feed = $this->feedWithEntries(self::FLOOR + 5, $this->daysAgo(1));

        self::assertSame(5, $pruner->prune());
        self::assertCount(self::FLOOR, $this->findAllEntries($feed));
    }

    /** Ranked across all feeds, the cap would reach into the older feed or put the boundary inside the newer one. */
    public function testCapIsPerFeedNotGlobal(): void
    {
        $cap = self::CAP_ABOVE_THE_FLOOR;
        $pruner = new EntryPruner($this->retention(), $this->clock, $this->indexer(), maxEntriesPerFeed: $cap);

        $newerFeedAtTheCap = $this->feedWithEntries($cap, $this->daysAgo(1));
        $overTheCap = $this->feedWithEntries($cap + 1, $this->daysAgo(2));
        $olderFeedAtTheCap = $this->feedWithEntries($cap, $this->daysAgo(3));

        self::assertSame(1, $pruner->prune());
        self::assertEqualsCanonicalizing(self::entryGuids(1, $cap), $this->remainingGuids($overTheCap));
        self::assertCount($cap, $this->findAllEntries($newerFeedAtTheCap));
        self::assertCount($cap, $this->findAllEntries($olderFeedAtTheCap));
    }
}
