<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Refresh\BudgetedFeedQueue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BudgetedFeedQueueTest extends TestCase
{
    /** @return list<Feed> */
    private function feeds(int $count): array
    {
        return array_map(
            static fn (int $index): Feed => new Feed(sprintf('https://feed%d.example.com/rss', $index)),
            range(1, $count),
        );
    }

    public function testYieldsEveryFeedWhenTheBudgetIsAmple(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() + 300);

        $tickets = iterator_to_array($queue->tickets(), preserve_keys: false);

        self::assertCount(3, $tickets);
        self::assertSame(3, $queue->startedCount());
        self::assertSame(0, $queue->skippedCount());
    }

    public function testCarriesTheFeedsConditionalGetHeaders(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $feed = new Feed('https://one.example.com/feed');
        $feed->setEtag('"v1"');
        $feed->setLastModified('Mon, 20 Jul 2026 08:30:00 GMT');

        $queue = new BudgetedFeedQueue([$feed], $clock, $clock->now()->getTimestamp() + 300);
        $tickets = iterator_to_array($queue->tickets(), preserve_keys: false);

        self::assertSame('https://one.example.com/feed', $tickets[0]->url);
        self::assertSame('"v1"', $tickets[0]->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $tickets[0]->lastModified);
    }

    public function testStopsYieldingOnceTheDeadlineIsWithinTheSafetyMargin(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() + 100);

        $taken = [];
        foreach ($queue->tickets() as $ticket) {
            $taken[] = $ticket;
            // Simulate a wave that consumed almost the whole budget.
            $clock->sleep(95);
        }

        self::assertCount(1, $taken);
        self::assertSame(1, $queue->startedCount());
        self::assertSame(2, $queue->skippedCount());
    }

    /**
     * The user endpoint polls until `remaining` reaches 0. A run that starts
     * nothing leaves `remaining` unchanged and the client spins forever.
     */
    public function testAlwaysYieldsTheFirstFeedEvenWithNoBudgetLeft(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue($this->feeds(3), $clock, $clock->now()->getTimestamp() - 60);

        $taken = iterator_to_array($queue->tickets(), preserve_keys: false);

        self::assertCount(1, $taken);
        self::assertSame(1, $queue->startedCount());
        self::assertSame(2, $queue->skippedCount());
    }

    public function testAnEmptyFeedListStartsAndSkipsNothing(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $queue = new BudgetedFeedQueue([], $clock, $clock->now()->getTimestamp() + 300);

        self::assertSame([], iterator_to_array($queue->tickets(), preserve_keys: false));
        self::assertSame(0, $queue->startedCount());
        self::assertSame(0, $queue->skippedCount());
    }
}
