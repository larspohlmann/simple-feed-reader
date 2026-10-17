<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Enum\SourceFormat;
use App\Service\Social\Support\ExportedFeed;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class ExportedFeedTest extends TestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';
    private const string RUSTY_RSS = 'https://bsky.app/profile/' . self::RUSTY . '/rss';

    public function testAnAuthorFeedIsExportedAsItsProfilesPublicRss(): void
    {
        $author = self::feed('social:bluesky:4:author:' . self::RUSTY, SourceFormat::BLUESKY_API);

        self::assertSame(self::RUSTY_RSS, ExportedFeed::urlOf($author));
        self::assertSame(SourceFormat::XML, ExportedFeed::sourceFormatOf($author));
    }

    public function testAnyOtherFeedIsExportedAsItIs(): void
    {
        $scraped = self::feed('https://blog.example.com/', SourceFormat::SCRAPED);
        $mastodonAuthor = self::feed('social:mastodon:4:author:' . self::RUSTY, SourceFormat::MASTODON_API);

        self::assertSame('https://blog.example.com/', ExportedFeed::urlOf($scraped));
        self::assertSame(SourceFormat::SCRAPED, ExportedFeed::sourceFormatOf($scraped));
        self::assertSame('social:mastodon:4:author:' . self::RUSTY, ExportedFeed::urlOf($mastodonAuthor));
        self::assertSame(SourceFormat::MASTODON_API, ExportedFeed::sourceFormatOf($mastodonAuthor));
    }

    public function testAnAuthorFeedAndTheRssFeedItReplacesExportAsTheFirstOfThem(): void
    {
        $rss = self::subscription(self::feed(self::RUSTY_RSS, SourceFormat::XML));
        $author = self::subscription(self::feed('social:bluesky:4:author:' . self::RUSTY, SourceFormat::BLUESKY_API));
        $other = self::subscription(self::feed('https://blog.example.com/feed.xml', SourceFormat::XML));

        self::assertSame([$rss, $other], ExportedFeed::withoutRepeats([$rss, $author, $other]));
        self::assertSame([$author, $other], ExportedFeed::withoutRepeats([$author, $rss, $other]));
    }

    private static function feed(string $url, string $sourceFormat): Feed
    {
        $feed = new Feed($url);
        $feed->setSourceFormat($sourceFormat);

        return $feed;
    }

    private static function subscription(Feed $feed): Subscription
    {
        return new Subscription(SocialAccounts::owner(), $feed, new \DateTimeImmutable('2026-10-17 12:00:00'));
    }
}
