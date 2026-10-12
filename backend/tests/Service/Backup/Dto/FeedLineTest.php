<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Enum\SourceFormat;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedLineTest extends TestCase
{
    /** @param array<string, mixed> $line */
    #[DataProvider('privateFeedLines')]
    public function testAPrivateSocialFeedIsRefused(array $line): void
    {
        $this->expectException(InvalidBackupException::class);

        FeedLine::fromLine($line);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function privateFeedLines(): iterable
    {
        yield 'a social url, posing as rss' => [self::line('social:bluesky:5:home', SourceFormat::XML)];
        yield 'a social format' => [self::line('https://example.com/feed.xml', SourceFormat::MASTODON_API)];
    }

    public function testAnOrdinaryFeedIsRead(): void
    {
        $line = FeedLine::fromLine(self::line('https://example.com/feed.xml', SourceFormat::XML));

        self::assertSame('https://example.com/feed.xml', $line->url);
    }

    /** @return array<string, mixed> */
    private static function line(string $url, string $sourceFormat): array
    {
        return [
            'url' => $url,
            'siteUrl' => null,
            'title' => 'Feed',
            'description' => null,
            'faviconUrl' => null,
            'imageUrl' => null,
            'sourceFormat' => $sourceFormat,
        ];
    }
}
