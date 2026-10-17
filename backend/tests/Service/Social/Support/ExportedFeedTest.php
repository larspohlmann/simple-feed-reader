<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Enum\SourceFormat;
use App\Service\Social\Model\ExportedSubscriptionModel;
use App\Service\Social\Support\ExportedFeed;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class ExportedFeedTest extends TestCase
{
    private const string RUSTY_RSS = 'https://bsky.app/profile/' . BlueskyProfiles::RUSTY . '/rss';

    public function testAnAuthorFeedIsExportedAsItsProfilesPublicRss(): void
    {
        $author = self::subscription('social:bluesky:4:author:' . BlueskyProfiles::RUSTY, SourceFormat::BLUESKY_API);

        self::assertEquals(
            new ExportedSubscriptionModel($author, self::RUSTY_RSS, SourceFormat::XML),
            ExportedFeed::of($author),
        );
    }

    public function testAnyOtherFeedIsExportedAsItIs(): void
    {
        $scraped = self::subscription('https://blog.example.com/', SourceFormat::SCRAPED);
        $mastodonAuthor = self::subscription(
            'social:mastodon:4:author:' . BlueskyProfiles::RUSTY,
            SourceFormat::MASTODON_API,
        );

        self::assertEquals(
            new ExportedSubscriptionModel($scraped, 'https://blog.example.com/', SourceFormat::SCRAPED),
            ExportedFeed::of($scraped),
        );
        self::assertEquals(
            new ExportedSubscriptionModel(
                $mastodonAuthor,
                'social:mastodon:4:author:' . BlueskyProfiles::RUSTY,
                SourceFormat::MASTODON_API,
            ),
            ExportedFeed::of($mastodonAuthor),
        );
    }

    public function testAnAuthorFeedAndTheRssFeedItReplacesExportAsTheFirstOfThem(): void
    {
        $rss = self::subscription(self::RUSTY_RSS, SourceFormat::XML);
        $author = self::subscription('social:bluesky:4:author:' . BlueskyProfiles::RUSTY, SourceFormat::BLUESKY_API);
        $other = self::subscription('https://blog.example.com/feed.xml', SourceFormat::XML);

        self::assertSame([$rss, $other], self::subscriptionsOf(ExportedFeed::withoutRepeats([$rss, $author, $other])));
        self::assertSame(
            [$author, $other],
            self::subscriptionsOf(ExportedFeed::withoutRepeats([$author, $rss, $other])),
        );
    }

    /**
     * @param list<ExportedSubscriptionModel> $exported
     *
     * @return list<Subscription>
     */
    private static function subscriptionsOf(array $exported): array
    {
        return array_map(
            static fn (ExportedSubscriptionModel $subscription): Subscription => $subscription->subscription,
            $exported,
        );
    }

    private static function subscription(string $url, string $sourceFormat): Subscription
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);

        return new Subscription(SocialAccounts::owner(), $feed, new \DateTimeImmutable('2026-10-17 12:00:00'));
    }
}
