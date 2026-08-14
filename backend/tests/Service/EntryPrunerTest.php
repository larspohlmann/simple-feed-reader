<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\EntryPruner;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class EntryPrunerTest extends DbTestCase
{
    private EntryPruner $pruner;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->pruner = new EntryPruner($this->em, $this->clock);
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
        $this->em->persist($feed);
        $this->em->flush();

        $fetchedAt = $createdAt ?? $this->clock->now();
        for ($i = 0; $i < $count; ++$i) {
            $this->persistEntry($feed, sprintf('entry-%d', $i), $fetchedAt);
        }
        $this->em->flush();

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
        $this->em->flush();

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
        $this->em->persist($entry);

        return $entry;
    }

    private function findByGuid(Feed $feed, string $guid): ?Entry
    {
        return $this->em->getRepository(Entry::class)->findOneBy(['feed' => $feed, 'guid' => $guid]);
    }

    /** @return list<Entry> */
    private function findAllEntries(Feed $feed): array
    {
        return $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]);
    }

    /** @return list<string> */
    private function remainingGuids(Feed $feed): array
    {
        return array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            $this->findAllEntries($feed),
        );
    }

    public function testKeepsAnOldArticleThatWasFetchedRecently(): void
    {
        // Twenty recent filler entries hold the feed above the floor, so the
        // archive entry below falls beyond the newest-twenty boundary — this
        // isolates the age check itself, since a below-floor feed would
        // survive regardless of which date the age pass reads.
        $feed = $this->feedWithEntries(20, $this->daysAgo(1));
        $this->seedEntry($feed, 'archive', $this->daysAgo(2), $this->daysAgo(2000));

        $this->pruner->prune();

        self::assertNotNull($this->findByGuid($feed, 'archive'));
    }

    /**
     * 30 entries sharing one `createdAt`, all 100 days old: the floor keeps
     * exactly 20, tie-broken by id — the 10 lowest ids (entry-0..entry-9,
     * the ones fetched first in the burst) go, the 20 highest survive.
     */
    public function testDeletesAnArticleFetchedBeforeTheRetentionWindow(): void
    {
        $feed = $this->feedWithEntries(30, $this->daysAgo(100));

        self::assertSame(10, $this->pruner->prune());
        self::assertEqualsCanonicalizing(
            array_map(static fn (int $i): string => "entry-{$i}", range(10, 29)),
            $this->remainingGuids($feed),
        );
    }

    /**
     * 25 entries sharing one `createdAt`, all 100 days old: the floor keeps
     * the 20 highest ids (entry-5..entry-24) and drops the 5 lowest.
     */
    public function testNeverDeletesAFeedsNewestTwentyEntries(): void
    {
        $feed = $this->feedWithEntries(25, $this->daysAgo(100));

        $this->pruner->prune();

        self::assertEqualsCanonicalizing(
            array_map(static fn (int $i): string => "entry-{$i}", range(5, 24)),
            $this->remainingGuids($feed),
        );
    }

    public function testAFeedOfTwentyOldEntriesLosesNone(): void
    {
        $this->feedWithEntries(20, $this->daysAgo(100));

        self::assertSame(0, $this->pruner->prune());
    }

    /**
     * Two separate feeds, each 21 entries sharing one `createdAt`, all 100
     * days old: the floor keeps 20 per feed and drops exactly the lowest id
     * (entry-0) in each — the total must be the sum across feeds, not just
     * the last feed scanned.
     */
    public function testAgePassSumsDeletionsAcrossFeeds(): void
    {
        $feedA = $this->feedWithEntries(21, $this->daysAgo(100));
        $feedB = $this->feedWithEntries(21, $this->daysAgo(100));

        self::assertSame(2, $this->pruner->prune());
        self::assertEqualsCanonicalizing(
            array_map(static fn (int $i): string => "entry-{$i}", range(1, 20)),
            $this->remainingGuids($feedA),
        );
        self::assertEqualsCanonicalizing(
            array_map(static fn (int $i): string => "entry-{$i}", range(1, 20)),
            $this->remainingGuids($feedB),
        );
    }

    /**
     * Two separate feeds, each one entry past the cap: the total must be the
     * sum across feeds, not just the last feed scanned.
     */
    public function testCapPassSumsDeletionsAcrossFeeds(): void
    {
        // A cap above MIN_ENTRIES_PER_FEED, so the #384 clamp doesn't mask
        // the boundary this test exercises.
        $cap = 22;
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: $cap);

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
        // A cap above MIN_ENTRIES_PER_FEED, so the #384 clamp doesn't mask
        // the boundary this test exercises.
        $cap = 22;
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: $cap);

        $feed = $this->feedWithEntries($cap + 2, $this->daysAgo(1));

        self::assertSame(2, $pruner->prune());
        self::assertEqualsCanonicalizing(
            array_map(static fn (int $i): string => "entry-{$i}", range(2, $cap + 1)),
            $this->remainingGuids($feed),
        );
    }

    public function testPrunesOldEntriesButKeepsProtectedAndRecent(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);
        $this->em->flush();

        // Twenty recent filler entries hold the feed above the floor, so the
        // four old entries below all fall beyond the newest-twenty boundary.
        $feed = $this->feedWithEntries(20, $this->daysAgo(5));

        $old = $this->daysAgo(120);
        $this->seedEntry($feed, 'old-plain', $old);
        $favorite = $this->seedEntry($feed, 'old-favorite', $old);
        $kept = $this->seedEntry($feed, 'old-kept', $old);
        $oldButRead = $this->seedEntry($feed, 'old-read', $old);

        $favoriteState = new EntryState($user, $favorite);
        $favoriteState->setIsFavorite(true);
        $keptState = new EntryState($user, $kept);
        $keptState->setIsKept(true);
        $readState = new EntryState($user, $oldButRead);
        $readState->setIsRead(true);
        $this->em->persist($favoriteState);
        $this->em->persist($keptState);
        $this->em->persist($readState);
        $this->em->flush();

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
        $feed = new Feed('https://example.com/feed');
        $alice = new User('alice@example.com', $this->clock->now());
        $bob = new User('bob@example.com', $this->clock->now());
        $this->em->persist($feed);
        $this->em->persist($alice);
        $this->em->persist($bob);

        $shared = $this->persistEntry($feed, 'shared', $this->daysAgo(200));
        $this->em->flush();

        $aliceRead = new EntryState($alice, $shared);
        $aliceRead->setIsRead(true);
        $bobKept = new EntryState($bob, $shared);
        $bobKept->setIsKept(true);
        $this->em->persist($aliceRead);
        $this->em->persist($bobKept);
        $this->em->flush();

        self::assertSame(0, $this->pruner->prune());
        self::assertCount(1, $this->findAllEntries($feed));
    }

    public function testDeletingEntryRemovesItsStateRows(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);

        // Twenty recent filler entries hold the feed above the floor, so the
        // doomed entry below falls beyond the newest-twenty boundary.
        $feed = $this->feedWithEntries(20, $this->daysAgo(5));
        $doomed = $this->seedEntry($feed, 'doomed', $this->daysAgo(200));
        $state = new EntryState($user, $doomed);
        $state->setIsRead(true);
        $this->em->persist($state);
        $this->em->flush();

        self::assertSame(1, $this->pruner->prune());
        self::assertCount(0, $this->em->getRepository(EntryState::class)->findAll());
    }

    public function testEntryWithoutPublishedAtUsesCreatedAt(): void
    {
        $feed = $this->feedWithEntries(20, $this->daysAgo(5));
        $undatedCreatedAt = $this->daysAgo(200);
        $undated = new Entry($feed, 'undated', null, 'No date', $undatedCreatedAt, $undatedCreatedAt);
        $this->em->persist($undated);
        $this->em->flush();

        self::assertSame(1, $this->pruner->prune());
    }

    public function testRecentUndatedEntrySurvives(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $freshCreatedAt = $this->daysAgo(2);
        $fresh = new Entry($feed, 'fresh-undated', null, 'No date', $freshCreatedAt, $freshCreatedAt);
        $this->em->persist($fresh);
        $this->em->flush();

        self::assertSame(0, $this->pruner->prune());
    }

    public function testNothingToPruneReturnsZero(): void
    {
        self::assertSame(0, $this->pruner->prune());
    }

    public function testCapsEntriesPerFeedKeepingNewestAndProtected(): void
    {
        // A cap above MIN_ENTRIES_PER_FEED, so the #384 clamp doesn't mask
        // the boundary this test exercises.
        $cap = 22;
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: $cap);

        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);

        // `$cap` recent filler entries hold the feed at the cap, so the two
        // older entries below both fall beyond the boundary.
        $feed = $this->feedWithEntries($cap, $this->daysAgo(1));
        $this->seedEntry($feed, 'old-unprotected', $this->daysAgo(3));
        $protected = $this->seedEntry($feed, 'old-protected', $this->daysAgo(2));

        // The older of the two is kept, so it survives despite being beyond the cap.
        $keptState = new EntryState($user, $protected);
        $keptState->setIsKept(true);
        $this->em->persist($keptState);
        $this->em->flush();

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
        // A cap above MIN_ENTRIES_PER_FEED, so the #384 clamp doesn't mask
        // the boundary this test exercises.
        $cap = 22;
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: $cap);

        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);

        // `$cap` recent filler entries hold the feed at the cap, so the
        // older entry below falls beyond the boundary.
        $feed = $this->feedWithEntries($cap, $this->daysAgo(1));
        $oldest = $this->seedEntry($feed, 'oldest', $this->daysAgo(2));

        $readState = new EntryState($user, $oldest);
        $readState->setIsRead(true);
        $this->em->persist($readState);
        $this->em->flush();

        self::assertSame(1, $pruner->prune());
        self::assertNull($this->findByGuid($feed, 'oldest'));
    }

    /**
     * Pins the semantic this task chose over the pre-existing one: a
     * protected entry still occupies a ranking slot among the newest `keep`,
     * rather than being excluded from the ranking before the cap is applied.
     * With the favorite at the very top, cap 3 keeps only its two youngest
     * non-protected neighbours and drops the two oldest — not just one.
     */
    public function testProtectedNewestEntryStillOccupiesARankingSlot(): void
    {
        // A cap above MIN_ENTRIES_PER_FEED, so the #384 clamp doesn't mask
        // the boundary this test exercises.
        $cap = 22;
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: $cap);

        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);

        // `$cap + 1` same-day entries (tie-broken by id, oldest-inserted
        // lowest) plus one favorite strictly newer than all of them: the
        // favorite still occupies a ranking slot, so it isn't "one extra
        // keep" — the two lowest ids in the tied group fall beyond the cap,
        // not just one.
        $feed = $this->feedWithEntries($cap + 1, $this->daysAgo(2));
        $favorite = $this->seedEntry($feed, 'favorite-newest', $this->daysAgo(1));

        $favoriteState = new EntryState($user, $favorite);
        $favoriteState->setIsFavorite(true);
        $this->em->persist($favoriteState);
        $this->em->flush();

        self::assertSame(2, $pruner->prune());
        $expected = array_merge(
            ['favorite-newest'],
            array_map(static fn (int $i): string => "entry-{$i}", range(2, $cap)),
        );
        self::assertEqualsCanonicalizing($expected, $this->remainingGuids($feed));
    }

    public function testFeedAtOrUnderCapIsUntouched(): void
    {
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: 3);

        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        for ($i = 0; $i < 3; ++$i) {
            $this->persistEntry($feed, 'entry-' . $i, $this->daysAgo($i + 1));
        }
        $this->em->flush();

        self::assertSame(0, $pruner->prune());
        self::assertCount(3, $this->findAllEntries($feed));
    }

    public function testPruningTheLastEntryOfACompletedRunAlsoDropsTheRun(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);

        // Twenty recent filler entries hold the feed above the floor, so the
        // doomed entry below falls beyond the newest-twenty boundary.
        $feed = $this->feedWithEntries(20, $this->daysAgo(5));
        $doomed = $this->seedEntry($feed, 'doomed', $this->daysAgo(200));

        $run = new RecommendationRun($user, $this->clock->now());
        $run->snapshot([[1]]);
        $run->complete($this->clock->now());
        $this->em->persist($run);
        $this->em->persist(new RecommendationItem($run, $doomed, 1, 'because'));
        $this->em->flush();
        $runId = $run->getId();

        // The run left empty by the doomed entry's deletion is bookkeeping,
        // not an entry: it must not inflate the count the refresh summary
        // shows the user. Only the entry counts toward the total.
        $pruned = $this->pruner->prune();
        $this->em->clear();

        self::assertSame(1, $pruned);
        self::assertNull($this->em->getRepository(RecommendationRun::class)->find($runId));
    }

    /**
     * The run-deletion count must never leak into the entry count: a refresh
     * that removes zero entries but leaves several runs empty (their items'
     * entries pruned in an earlier pass) reports zero, not the run count.
     */
    public function testEmptyRunsAloneReportZeroPruned(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);

        for ($i = 0; $i < 3; $i++) {
            $run = new RecommendationRun($user, $this->clock->now());
            $run->snapshot([[1]]);
            $run->complete($this->clock->now());
            $this->em->persist($run);
        }
        $this->em->flush();

        $pruned = $this->pruner->prune();

        self::assertSame(0, $pruned);
    }

    public function testARunningRunWithNoItemsSurvivesPruning(): void
    {
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($user);
        $run = new RecommendationRun($user, $this->clock->now());
        $run->snapshot([[1]]);
        $this->em->persist($run);
        $this->em->flush();
        $runId = $run->getId();
        $this->em->clear();

        $this->pruner->prune();
        $this->em->clear();

        self::assertNotNull($this->em->getRepository(RecommendationRun::class)->find($runId));
    }

    /**
     * `maxEntriesPerFeed` is overridable via the service definition; a value
     * configured below the 20-entry floor must not defeat it, and the clamp
     * must also stop `rankBoundaryBeyond()` from turning a `keep` of 0 into a
     * negative `setFirstResult()`.
     */
    public function testCapBelowTheFloorIsClampedToTheFloor(): void
    {
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: 0);

        $feed = $this->feedWithEntries(25, $this->daysAgo(1));

        self::assertSame(5, $pruner->prune());
        self::assertCount(20, $this->findAllEntries($feed));
    }

    public function testCapIsPerFeedNotGlobal(): void
    {
        $pruner = new EntryPruner($this->em, $this->clock, maxEntriesPerFeed: 3);

        // Two feeds, each at the cap — globally 4 entries, but per-feed nothing
        // exceeds the cap, so a global cap would wrongly delete here.
        foreach (['https://a.example/feed', 'https://b.example/feed'] as $n => $url) {
            $feed = new Feed($url);
            $this->em->persist($feed);
            $this->persistEntry($feed, "feed{$n}-a", $this->daysAgo(2));
            $this->persistEntry($feed, "feed{$n}-b", $this->daysAgo(1));
        }
        $this->em->flush();

        self::assertSame(0, $pruner->prune());
        self::assertCount(4, $this->em->getRepository(Entry::class)->findAll());
    }
}
