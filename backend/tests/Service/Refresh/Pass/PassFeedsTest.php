<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassLane\FeedLane;
use App\Service\Refresh\Pass\RefreshPass;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\PrivateFeeds;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PassFeedsTest extends TestCase
{
    use AssignsEntityIds;

    public function testGivesEachFeedTheLaneOfItsKind(): void
    {
        $feedLane = new FeedLane(2);
        $timelineLane = new FeedLane(3);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);

        $feeds = new PassFeeds(self::pass([$rss, PrivateFeeds::mastodonFeed(2)]), $feedLane, $timelineLane);

        self::assertSame($feedLane, $feeds->laneOf(1));
        self::assertSame($timelineLane, $feeds->laneOf(2));
        self::assertSame([$feedLane, $timelineLane], $feeds->lanes());
    }

    public function testNamesEveryPrivateFeedIdInOrderWhetherTheBudgetStartsItOrNot(): void
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);

        $pass = new RefreshPass(
            [$rss, PrivateFeeds::mastodonFeed(7), PrivateFeeds::mastodonFeed(4)],
            $clock,
            $clock->now()->getTimestamp() + 5,
        );

        self::assertSame([7, 4], PassFeeds::privateFeedIdsOf($pass));
    }

    public function testStartsWhatThePassStarts(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $feeds = new PassFeeds(self::pass([PrivateFeeds::mastodonFeed(2), $rss]), new FeedLane(2), new FeedLane(3));

        self::assertSame([2, 1], array_keys(iterator_to_array($feeds->started())));
    }

    /** @param list<Feed> $feeds */
    private static function pass(array $feeds): RefreshPass
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');

        return new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300);
    }
}
