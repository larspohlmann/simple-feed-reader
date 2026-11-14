<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Bluesky\BlueskyPendingEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyPendingEmbedTest extends TestCase
{
    private const string AT_POST = 'at://did:plc:bob/app.bsky.feed.post/3abc';

    #[DataProvider('entries')]
    public function testOnlyAPostFromABlueskyRssFeedWaitsForTheAppView(
        string $sourceFormat,
        string $guid,
        bool $awaits,
    ): void {
        $feed = new Feed('https://bsky.app/profile/did:plc:bob/rss');
        $feed->setSourceFormat($sourceFormat);
        $now = new \DateTimeImmutable('2026-10-12 12:00:00');

        $entry = new Entry($feed, $guid, null, 'Post', $now, $now);

        self::assertSame($awaits, (new BlueskyPendingEmbed())->awaitsEmbed($entry));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function entries(): iterable
    {
        yield 'rss post' => [SourceFormat::XML, self::AT_POST, true];
        yield 'timeline post, embed already in hand' => [SourceFormat::BLUESKY_API, self::AT_POST, false];
        yield 'not a post' => [SourceFormat::XML, 'https://example.com/a', false];
    }
}
