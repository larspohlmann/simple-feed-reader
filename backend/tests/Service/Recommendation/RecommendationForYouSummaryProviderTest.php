<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationItemRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\RecommendationForYouSummaryProvider;
use App\Tests\DbTestCase;

/**
 * The header/sidebar summary is a different read than the run report: the
 * report describes the latest run (which may have failed), while this
 * describes the surviving list — the deduped item count and the newest
 * *completed* run's timestamp. A failed run after two completed ones must
 * not move generatedAt, and a duplicate entry across runs must not double
 * count.
 */
final class RecommendationForYouSummaryProviderTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('for-you@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $this->em->flush();
    }

    private function entry(string $guid): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function seedRun(string $status, string $completedAt): RecommendationRun
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);

        if ($status === RecommendationRun::STATUS_COMPLETED) {
            $run->complete(new \DateTimeImmutable($completedAt));
        }

        if ($status === RecommendationRun::STATUS_FAILED) {
            $run->fail('boom', new \DateTimeImmutable($completedAt));
        }

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function item(RecommendationRun $run, Entry $entry, int $position): RecommendationItem
    {
        $item = new RecommendationItem($run, $entry, $position, 'reason');
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function provider(): RecommendationForYouSummaryProvider
    {
        $items = $this->em->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $items);
        $runs = $this->em->getRepository(RecommendationRun::class);
        self::assertInstanceOf(RecommendationRunRepository::class, $runs);

        return new RecommendationForYouSummaryProvider($items, $runs);
    }

    public function testCountsDedupedItemsAndUsesTheNewestCompletedRunsTimestamp(): void
    {
        $entryShared = $this->entry('shared');
        $entryOnlyInFirstRun = $this->entry('first-only');

        $firstRun = $this->seedRun(RecommendationRun::STATUS_COMPLETED, '2026-08-07T09:05:00Z');
        $this->item($firstRun, $entryShared, 1);
        $this->item($firstRun, $entryOnlyInFirstRun, 2);

        $secondRun = $this->seedRun(RecommendationRun::STATUS_COMPLETED, '2026-08-07T10:05:00Z');
        $this->item($secondRun, $entryShared, 1);

        $this->seedRun(RecommendationRun::STATUS_FAILED, '2026-08-07T11:05:00Z');

        $summary = $this->provider()->forUser($this->user);

        // "shared" is deduped to its newest run, so only 2 distinct entries
        // survive, not the 3 raw items across both completed runs.
        self::assertSame(2, $summary->itemCount);
        self::assertSame(
            '2026-08-07T10:05:00+00:00',
            $summary->generatedAt?->format(\DateTimeInterface::ATOM),
        );
        // The identity of that same newest completed run — not the failed one
        // after it — so the client can suppress its divider by id, not by time.
        self::assertSame($secondRun->getId(), $summary->newestRunId);
    }

    public function testAUserWithNoRunsGetsAnEmptySummary(): void
    {
        $summary = $this->provider()->forUser($this->user);

        self::assertSame(0, $summary->itemCount);
        self::assertNull($summary->generatedAt);
        self::assertNull($summary->newestRunId);
    }
}
