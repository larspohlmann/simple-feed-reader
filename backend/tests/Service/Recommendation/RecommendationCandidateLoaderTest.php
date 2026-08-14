<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Recommendation\CandidatePoolRequest;
use App\Service\Recommendation\RecommendationCandidateLoader;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class RecommendationCandidateLoaderTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('candidates@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->subscription = new Subscription(
            $this->user,
            $this->feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->em->persist($this->subscription);

        $this->em->flush();
    }

    public function testOnlyUnreadEntriesAreReturned(): void
    {
        $readByFlag = $this->entry('read-by-flag', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $readByFlag);
        $state->setIsRead(true);
        $this->em->persist($state);

        $this->entry('read-by-watermark', '2026-07-11T00:00:00Z');
        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-12T00:00:00Z'));

        $unread = $this->entry('unread', '2026-07-13T00:00:00Z');

        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame([$unread->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testAnUnreadFavoritedEntryIsExcluded(): void
    {
        $favorited = $this->entry('favorited', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $favorited);
        $state->setIsFavorite(true);
        $this->em->persist($state);
        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame([], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testAnUnreadKeptEntryIsExcluded(): void
    {
        $kept = $this->entry('kept-entry', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $kept);
        $state->setIsKept(true);
        $this->em->persist($state);
        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame([], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testAnUnreadViewedEntryIsExcluded(): void
    {
        $viewed = $this->entry('viewed-entry', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $viewed);
        $state->markViewed(new \DateTimeImmutable('2026-07-10T01:00:00Z'));
        $this->em->persist($state);
        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame([], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testAnUnreadEntryWithNoStateRowIsReturned(): void
    {
        $noState = $this->entry('no-state', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame([$noState->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testAnUnreadEntryWithAllInteractionFlagsFalseIsReturned(): void
    {
        $untouched = $this->entry('untouched', '2026-07-10T00:00:00Z');
        $state = new EntryState($this->user, $untouched);
        $state->setIsFavorite(false);
        $state->setIsKept(false);
        $this->em->persist($state);
        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame([$untouched->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testReturnsTheUnreadCandidatesAsAMultiset(): void
    {
        $this->entry('older', '2026-07-10T00:00:00Z');
        $this->entry('newer', '2026-07-11T00:00:00Z');

        // load() no longer promises newest-first order — it selects the
        // newest N, then shuffles — so only membership and count are the
        // contract now.
        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertEqualsCanonicalizing(
            ['newer', 'older'],
            array_map(static fn ($l) => $l->title, $lines),
        );
    }

    public function testPoolSizeCapsTheListToTheNewestCandidates(): void
    {
        $this->entry('a', '2026-07-10T00:00:00Z');
        $this->entry('b', '2026-07-11T00:00:00Z');
        $this->entry('c', '2026-07-12T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), $this->poolRequest(poolSize: 2));

        // The selection is still the newest $poolSize; the oldest ('a') is
        // outside the newest-2 window and never appears, whatever the shuffle
        // does to the order of the survivors.
        self::assertCount(2, $lines);
        self::assertEqualsCanonicalizing(
            ['c', 'b'],
            array_map(static fn ($l) => $l->title, $lines),
        );
    }

    public function testTheSameSeedProducesTheSameOrderTwice(): void
    {
        foreach (range(1, 8) as $index) {
            $this->entry('entry-' . $index, sprintf('2026-07-%02dT00:00:00Z', 10 + $index));
        }

        $first = $this->loader()->load($this->userId(), $this->poolRequest(orderSeed: 4242));
        $second = $this->loader()->load($this->userId(), $this->poolRequest(orderSeed: 4242));

        self::assertSame(
            array_map(static fn ($l) => $l->title, $first),
            array_map(static fn ($l) => $l->title, $second),
        );
    }

    public function testAFixedSeedReordersTheNewestFirstInput(): void
    {
        foreach (range(1, 8) as $index) {
            $this->entry('entry-' . $index, sprintf('2026-07-%02dT00:00:00Z', 10 + $index));
        }

        // The newest-first order the SELECT produces before the shuffle: the
        // most recent date ('entry-8') first, down to 'entry-1'.
        $newestFirst = array_map(
            static fn (int $index) => 'entry-' . $index,
            range(8, 1),
        );

        $shuffled = array_map(
            static fn ($l) => $l->title,
            $this->loader()->load($this->userId(), $this->poolRequest(orderSeed: 4242)),
        );

        // Same multiset, different order — the shuffle actually happens.
        self::assertEqualsCanonicalizing($newestFirst, $shuffled);
        self::assertNotSame($newestFirst, $shuffled);
    }

    public function testTheShufflePreservesTheMultisetWithoutLossOrDuplication(): void
    {
        $expected = [];
        foreach (range(1, 8) as $index) {
            $entry = $this->entry('entry-' . $index, sprintf('2026-07-%02dT00:00:00Z', 10 + $index));
            $expected[] = $entry->getId();
        }

        $ids = array_map(
            static fn ($l) => $l->entryId,
            $this->loader()->load($this->userId(), $this->poolRequest(orderSeed: 4242)),
        );

        self::assertCount(\count($expected), $ids);
        self::assertEqualsCanonicalizing($expected, $ids);
    }

    public function testAnEmptyPoolPassesThroughUnchanged(): void
    {
        self::assertSame([], $this->loader()->load($this->userId(), $this->poolRequest(orderSeed: 4242)));
    }

    public function testASingleItemPoolPassesThroughUnchanged(): void
    {
        $only = $this->entry('only', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), $this->poolRequest(orderSeed: 4242));

        self::assertSame([$only->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testLinesForIdsDropsPrunedIds(): void
    {
        $entry = $this->entry('kept', '2026-07-10T00:00:00Z');
        $keptId = $entry->getId();
        self::assertNotNull($keptId);

        $linesById = $this->loader()->linesForIds($this->userId(), [$keptId, 999999]);

        self::assertSame([$keptId], array_keys($linesById));
        self::assertSame('kept', $linesById[$keptId]->title);
    }

    public function testLinesForIdsWithAnEmptyIdListReturnsNothing(): void
    {
        $this->entry('unused', '2026-07-10T00:00:00Z');

        self::assertSame([], $this->loader()->linesForIds($this->userId(), []));
    }

    /**
     * An empty id list must short-circuit before touching the database: an
     * `IN ()` built by hand is a SQL syntax error, and even where the ORM
     * would tolerate it, a result-only assertion cannot tell "the guard
     * returned early" from "the query ran and happened to find nothing" —
     * both produce []. Counting queries is the only way to pin the guard
     * itself, not just its externally visible result.
     */
    public function testLinesForIdsWithAnEmptyIdListNeverQueriesTheDatabase(): void
    {
        $this->entry('unused', '2026-07-10T00:00:00Z');

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->loader()->linesForIds($this->userId(), []);

        self::assertSame([], $recorder->queries());
    }

    public function testLinesForIdsResolvesMultipleEntries(): void
    {
        $first = $this->entry('first', '2026-07-10T00:00:00Z');
        $second = $this->entry('second', '2026-07-11T00:00:00Z');
        $firstId = $first->getId();
        $secondId = $second->getId();
        self::assertNotNull($firstId);
        self::assertNotNull($secondId);

        $linesById = $this->loader()->linesForIds($this->userId(), [$firstId, $secondId]);

        // linesForIds() returns a map keyed by entry id, not an ordered list
        // (callers that need snapshot order re-derive it themselves via
        // linesInSnapshotOrder()), so only set membership is a contract here.
        self::assertEqualsCanonicalizing([$firstId, $secondId], array_keys($linesById));
    }

    public function testLinesForIdsCarriesTheSubscriptionsCustomTitle(): void
    {
        $this->subscription->setCustomTitle('My Custom Feed');
        $this->em->flush();

        $entry = $this->entry('titled', '2026-07-10T00:00:00Z');
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        $linesById = $this->loader()->linesForIds($this->userId(), [$entryId]);

        self::assertSame('My Custom Feed', $linesById[$entryId]->feedName);
    }

    public function testFeedNamePrefersTheSubscriptionsCustomTitle(): void
    {
        $this->subscription->setCustomTitle('My Custom Feed');
        $this->em->flush();
        $this->entry('titled', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame('My Custom Feed', $lines[0]->feedName);
    }

    public function testDescriptionPrefersTheSummaryOverTheContentHtml(): void
    {
        $entry = $this->entry('described', '2026-07-10T00:00:00Z');
        $entry->setSummary('Summary text');
        $entry->setContentHtml('<p>Content text</p>');
        $this->em->flush();

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame('Summary text', $lines[0]->description);
    }

    public function testFeedNameFallsBackToTheFeedTitleWithoutACustomTitle(): void
    {
        $this->entry('untitled', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load($this->userId(), $this->poolRequest());

        self::assertSame('Example', $lines[0]->feedName);
    }

    public function testSummarizeReportsTheTotalAndTheDateSpan(): void
    {
        $oldest = $this->entry('oldest', '2026-07-10T00:00:00Z');
        $this->entry('middle', '2026-07-15T00:00:00Z');
        $newest = $this->entry('newest', '2026-07-20T00:00:00Z');

        $ids = [$oldest->getId() ?? 0, $newest->getId() ?? 0];
        // Only two of the three ids are passed, so the span and count reflect
        // exactly the set handed in, not the whole feed.
        $summary = $this->loader()->summarize($this->userId(), $ids);

        self::assertNotNull($summary);
        self::assertSame(2, $summary->total);
        self::assertSame('2026-07-10', $summary->oldest);
        self::assertSame('2026-07-20', $summary->newest);
    }

    public function testSummarizeReturnsNullForAnEmptyIdList(): void
    {
        $this->entry('unused', '2026-07-10T00:00:00Z');

        self::assertNull($this->loader()->summarize($this->userId(), []));
    }

    public function testSummarizeReturnsNullWhenEveryIdIsPruned(): void
    {
        $this->entry('present', '2026-07-10T00:00:00Z');

        // Ids that resolve to no present entry aggregate to a zero count with
        // null MIN/MAX, which the loader reports as no summary at all.
        self::assertNull($this->loader()->summarize($this->userId(), [999999, 1000000]));
    }

    public function testSummarizeExcludesAnEntryInAnUnsubscribedFeed(): void
    {
        $subscribed = $this->entry('subscribed', '2026-07-10T00:00:00Z');

        $otherFeed = new Feed('https://example.com/other.xml');
        $otherFeed->setTitle('Other');
        $this->em->persist($otherFeed);
        $foreignPublishedAt = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $foreign = new Entry(
            $otherFeed,
            'foreign',
            'https://example.com/foreign',
            'foreign',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $foreignPublishedAt,
        );
        $foreign->setPublishedAt($foreignPublishedAt);
        $this->em->persist($foreign);
        $this->em->flush();

        $summary = $this->loader()->summarize(
            $this->userId(),
            [$subscribed->getId() ?? 0, $foreign->getId() ?? 0],
        );

        // The unsubscribed entry is the newest, so if the subscription gate
        // leaked it the newest date would be 2026-08-01 and the count 2.
        self::assertNotNull($summary);
        self::assertSame(1, $summary->total);
        self::assertSame('2026-07-10', $summary->oldest);
        self::assertSame('2026-07-10', $summary->newest);
    }

    public function testSummarizeWithAnEmptyIdListNeverQueriesTheDatabase(): void
    {
        $this->entry('unused', '2026-07-10T00:00:00Z');

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->loader()->summarize($this->userId(), []);

        self::assertSame([], $recorder->queries());
    }

    public function testAnEntryOlderThanTheWindowIsExcluded(): void
    {
        $this->entry('too-old', '2026-07-09T23:59:59Z');
        $inside = $this->entry('inside', '2026-07-11T00:00:00Z');

        $lines = $this->loader()->load(
            $this->userId(),
            $this->poolRequest(since: '2026-07-10T00:00:00Z'),
        );

        self::assertSame([$inside->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testAnEntryExactlyOnTheWindowBoundaryIsIncluded(): void
    {
        $onBoundary = $this->entry('on-boundary', '2026-07-10T00:00:00Z');

        $lines = $this->loader()->load(
            $this->userId(),
            $this->poolRequest(since: '2026-07-10T00:00:00Z'),
        );

        // `>=`, not `>`: the boundary instant belongs to the window.
        self::assertSame([$onBoundary->getId()], array_map(static fn ($l) => $l->entryId, $lines));
    }

    public function testThePoolSizeStillCapsTheCandidatesInsideTheWindow(): void
    {
        $this->entry('older-inside', '2026-07-11T00:00:00Z');
        $this->entry('newer-inside', '2026-07-12T00:00:00Z');
        $this->entry('outside', '2026-07-09T00:00:00Z');

        $lines = $this->loader()->load(
            $this->userId(),
            $this->poolRequest(poolSize: 1, since: '2026-07-10T00:00:00Z'),
        );

        // The cap selects the newest inside the window, never reaching past it.
        self::assertSame(['newer-inside'], array_map(static fn ($l) => $l->title, $lines));
    }

    private function poolRequest(
        int $poolSize = 100,
        int $orderSeed = 1,
        string $since = '2000-01-01T00:00:00Z',
    ): CandidatePoolRequest {
        return new CandidatePoolRequest(
            since: new \DateTimeImmutable($since),
            poolSize: $poolSize,
            orderSeed: $orderSeed,
        );
    }

    private function entry(string $guid, string $published): Entry
    {
        $publishedAt = new \DateTimeImmutable($published);
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $publishedAt,
        );
        $entry->setPublishedAt($publishedAt);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function userId(): int
    {
        return $this->user->getId() ?? 0;
    }

    private function loader(): RecommendationCandidateLoader
    {
        /** @var RecommendationCandidateLoader $loader */
        $loader = self::getContainer()->get(RecommendationCandidateLoader::class);

        return $loader;
    }
}
