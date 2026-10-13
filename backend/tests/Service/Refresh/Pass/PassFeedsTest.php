<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PassFeedsTest extends TestCase
{
    use AssignsEntityIds;

    public function testTellsAPrivateFeedFromAnRssFeed(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $timeline = self::privateFeed(2);

        $feeds = self::passFeeds([$rss, $timeline->getFeed()], [2 => $timeline]);

        self::assertFalse($feeds->isPrivate(1));
        self::assertTrue($feeds->isPrivate(2));
    }

    public function testNamesEveryPrivateFeedIdInOrderWhetherTheBudgetStartsItOrNot(): void
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $home = self::privateFeed(7)->getFeed();
        $list = self::privateFeed(4)->getFeed();

        $pass = new RefreshPass([$rss, $home, $list], $clock, $clock->now()->getTimestamp() + 5);

        self::assertSame([7, 4], PassFeeds::privateFeedIdsOf($pass));
    }

    public function testFindsAPrivateFeedsSocialFeed(): void
    {
        $timeline = self::privateFeed(2);

        self::assertSame($timeline, self::passFeeds([$timeline->getFeed()], [2 => $timeline])->socialFeedOf(2));
    }

    public function testAPrivateFeedWithoutItsSocialFeedIsOrphaned(): void
    {
        $orphan = self::privateFeed(2)->getFeed();

        $this->expectException(PrivateFeedOrphanedException::class);

        self::passFeeds([$orphan], [])->socialFeedOf(2);
    }

    public function testStartsWhatThePassStarts(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $timeline = self::privateFeed(2);

        $started = iterator_to_array(self::passFeeds([$timeline->getFeed(), $rss], [2 => $timeline])->started());

        self::assertSame([2, 1], array_keys($started));
    }

    /**
     * @param list<Feed>             $feeds
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     */
    private static function passFeeds(array $feeds, array $socialFeedsByFeedId): PassFeeds
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');

        return new PassFeeds(
            new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300),
            $socialFeedsByFeedId,
        );
    }

    private static function privateFeed(int $feedId): SocialFeed
    {
        $feed = self::withId(new Feed(sprintf('social:mastodon:7:%d', $feedId)), $feedId);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        return new SocialFeed(
            self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7),
            $feed,
            SocialFeedKind::Home,
            '',
        );
    }
}
