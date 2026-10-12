<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Refresh\Pass\BudgetedFeedQueue;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BudgetedFeedQueueTest extends TestCase
{
    use AssignsEntityIds;

    /** @return list<Feed> */
    private function feeds(int $count): array
    {
        return array_map(
            static fn (int $index): Feed => self::withId(
                new Feed(sprintf('https://feed%d.example.com/rss', $index)),
                $index,
            ),
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
        $feed = self::withId(new Feed('https://one.example.com/feed'), 1);
        $feed->recordCacheValidators('"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');

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

    public function testASocialFeedGetsNoTicketButItsOwnTurnAfterTheTickets(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://feed1.example.com/rss'), 1);
        $timeline = self::withId(self::socialFeed('social:bluesky:1:home'), 2);
        $queue = new BudgetedFeedQueue([$timeline, $rss], $clock, $clock->now()->getTimestamp() + 300);

        $tickets = iterator_to_array($queue->tickets());
        $social = iterator_to_array($queue->socialFeeds());

        self::assertSame([1], array_keys($tickets));
        self::assertSame([2 => $timeline], $social);
        self::assertSame([1, 2], $queue->startedFeedIds());
    }

    public function testASocialFeedWaitsForTheBudgetLikeAnyOther(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://feed1.example.com/rss'), 1);
        $timeline = self::withId(self::socialFeed('social:bluesky:1:home'), 2);
        $queue = new BudgetedFeedQueue([$rss, $timeline], $clock, $clock->now()->getTimestamp() + 5);

        iterator_to_array($queue->tickets());

        self::assertSame([], iterator_to_array($queue->socialFeeds()));
        self::assertSame(1, $queue->skippedCount());
    }

    public function testNamesEverySocialFeedIdInOrderWhetherTheBudgetStartsItOrNot(): void
    {
        $clock = new MockClock('2026-07-26 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://feed1.example.com/rss'), 1);
        $home = self::withId(self::socialFeed('social:bluesky:1:home'), 7);
        $list = self::withId(self::socialFeed('social:bluesky:1:list:x'), 4);
        $queue = new BudgetedFeedQueue([$rss, $home, $list], $clock, $clock->now()->getTimestamp() + 5);

        self::assertSame([7, 4], $queue->socialFeedIds());
    }

    private static function socialFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);

        return $feed;
    }
}
