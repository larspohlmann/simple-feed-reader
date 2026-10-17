<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\MastodonPendingThread;
use PHPUnit\Framework\TestCase;

final class MastodonPendingThreadTest extends TestCase
{
    private const string STATUS = 'https://mastodon.social/@alice/113';

    public function testAMastodonRssPostAwaitsItsThread(): void
    {
        $entry = self::entry(SocialNetwork::Mastodon, SourceFormat::XML, self::STATUS);

        self::assertTrue((new MastodonPendingThread())->awaitsThread($entry));
    }

    public function testAMastodonTimelinePostAlreadyCarriesItsThread(): void
    {
        $entry = self::entry(SocialNetwork::Mastodon, SourceFormat::MASTODON_API, self::STATUS);

        self::assertFalse((new MastodonPendingThread())->awaitsThread($entry));
    }

    public function testABlueskyPostIsNotFoldedHere(): void
    {
        $entry = self::entry(
            SocialNetwork::Bluesky,
            SourceFormat::XML,
            'at://did:plc:bob/app.bsky.feed.post/3abc',
        );

        self::assertFalse((new MastodonPendingThread())->awaitsThread($entry));
    }

    public function testAMastodonItemThatIsNoStatusAddressIsNotFolded(): void
    {
        $entry = self::entry(SocialNetwork::Mastodon, SourceFormat::XML, 'https://mastodon.social/about');

        self::assertFalse((new MastodonPendingThread())->awaitsThread($entry));
    }

    private static function entry(SocialNetwork $network, string $sourceFormat, string $guid): Entry
    {
        $feed = new Feed('https://mastodon.social/@alice.rss');
        $feed->classifyAs($network);
        $feed->setSourceFormat($sourceFormat);
        $now = new \DateTimeImmutable('2026-10-17 10:00:00');

        return new Entry($feed, $guid, null, 'Post', $now, $now);
    }
}
