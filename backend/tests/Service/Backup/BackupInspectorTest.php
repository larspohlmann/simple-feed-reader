<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupInspector;
use App\Service\Backup\BackupReader;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupInspectorTest extends TestCase
{
    private const string FEED_URL = 'https://f.example/feed.xml';

    /** @param list<array<string, mixed>> $lines */
    private static function gzipOf(array $lines): string
    {
        $ndjson = implode("\n", array_map(
            static fn (array $line): string => json_encode($line, \JSON_THROW_ON_ERROR),
            $lines,
        )) . "\n";

        return (string) gzencode($ndjson);
    }

    /** @return array<string, mixed> */
    private static function header(int $schemaVersion = 1): array
    {
        return [
            'kind' => 'header',
            'schemaVersion' => $schemaVersion,
            'createdAt' => '2026-08-17T09:00:00+00:00',
            'sourceUrl' => 'https://source.example',
            'sourceEmail' => 'source@example.com',
        ];
    }

    /** @return array<string, mixed> */
    private static function account(): array
    {
        return [
            'kind' => 'account',
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'recommendationSettings' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function tag(string $name): array
    {
        return ['kind' => 'tag', 'name' => $name, 'color' => null, 'icon' => null, 'position' => 0];
    }

    /** @return array<string, mixed> */
    private static function feed(string $url): array
    {
        return ['kind' => 'feed', 'url' => $url, 'siteUrl' => null, 'title' => null,
            'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'];
    }

    /**
     * @param list<array<string, mixed>> $tags
     *
     * @return array<string, mixed>
     */
    private static function subscription(string $feedUrl, array $tags = []): array
    {
        return ['kind' => 'subscription', 'feedUrl' => $feedUrl, 'customTitle' => null,
            'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00',
            'tags' => $tags];
    }

    /** @return array<string, mixed> */
    private static function entry(string $feedUrl): array
    {
        return ['kind' => 'entry', 'feedUrl' => $feedUrl, 'guid' => 'g', 'guidHash' => 'h',
            'url' => null, 'title' => 'One', 'author' => null, 'summary' => null,
            'contentHtml' => null, 'imageUrl' => null, 'imageWidth' => null, 'imageHeight' => null,
            'publishedAt' => null, 'createdAt' => '2026-08-01T00:00:00+00:00',
            'effectiveDate' => '2026-08-01T00:00:00+00:00'];
    }

    /** @return array<string, mixed> */
    private static function entryState(string $feedUrl): array
    {
        return ['kind' => 'entryState', 'feedUrl' => $feedUrl, 'guidHash' => 'h', 'isRead' => true,
            'isFavorite' => false, 'isKept' => false, 'readAt' => null, 'isViewed' => false,
            'viewedAt' => null];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, mixed>
     */
    private static function footer(array $counts = []): array
    {
        return ['kind' => 'footer', 'counts' => $counts + [
            'tag' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 0, 'entryState' => 0,
        ]];
    }

    private static function inspector(): BackupInspector
    {
        return new BackupInspector(new BackupReader());
    }

    public function testCountsEveryKind(): void
    {
        $gzip = self::gzipOf([
            self::header(), self::account(),
            self::tag('A'), self::tag('B'),
            self::feed(self::FEED_URL),
            self::subscription(self::FEED_URL),
            self::footer(['tag' => 2, 'feed' => 1, 'subscription' => 1]),
        ]);

        $inventory = self::inspector()->inspect($gzip);

        self::assertSame(2, $inventory->tags);
        self::assertSame(1, $inventory->feeds);
        self::assertSame(1, $inventory->subscriptions);
        self::assertSame(0, $inventory->entries);
        self::assertSame('source@example.com', $inventory->header->sourceEmail);
    }

    public function testABrokenFileRefusesInsteadOfCounting(): void
    {
        $this->expectException(InvalidBackupException::class);

        self::inspector()->inspect((string) gzencode("{\"kind\":\"header\"}\n"));
    }

    /**
     * Every cross-reference a backup makes must resolve inside the same file,
     * and pass 1 is the only place that verdict is worth anything — pass 2
     * runs after the wipe, where the same refusal costs the account
     * everything it held.
     *
     * @return iterable<string, array{list<array<string, mixed>>, array<string, int>}>
     */
    public static function danglingReferences(): iterable
    {
        yield 'a subscription to an undeclared feed' => [
            [self::subscription(self::FEED_URL)],
            ['subscription' => 1],
        ];

        yield 'a subscription naming an undeclared tag' => [
            [self::feed(self::FEED_URL), self::subscription(self::FEED_URL, [['name' => 'Ghost', 'position' => 0]])],
            ['feed' => 1, 'subscription' => 1],
        ];

        yield 'an entry for a feed no subscription names' => [
            [self::feed(self::FEED_URL), self::entry(self::FEED_URL)],
            ['feed' => 1, 'entry' => 1],
        ];

        yield 'an entry state for a feed no subscription names' => [
            [self::feed(self::FEED_URL), self::entryState(self::FEED_URL)],
            ['feed' => 1, 'entryState' => 1],
        ];

        yield 'the same tag name twice' => [
            [self::tag('A'), self::tag('A')],
            ['tag' => 2],
        ];

        yield 'the same feed url twice' => [
            [self::feed(self::FEED_URL), self::feed(self::FEED_URL)],
            ['feed' => 2],
        ];

        yield 'the same subscription feed url twice' => [
            [
                self::feed(self::FEED_URL),
                self::subscription(self::FEED_URL),
                self::subscription(self::FEED_URL),
            ],
            ['feed' => 1, 'subscription' => 2],
        ];
    }

    /**
     * @param list<array<string, mixed>> $body
     * @param array<string, int>         $counts
     */
    #[DataProvider('danglingReferences')]
    public function testAReferenceTheFileNeverDeclaresIsRefusedInPassOne(array $body, array $counts): void
    {
        $gzip = self::gzipOf([self::header(), self::account(), ...$body, self::footer($counts)]);

        $this->expectException(InvalidBackupException::class);

        self::inspector()->inspect($gzip);
    }

    public function testAFullyResolvedFileIsAccepted(): void
    {
        $gzip = self::gzipOf([
            self::header(), self::account(),
            self::tag('A'),
            self::feed(self::FEED_URL),
            self::subscription(self::FEED_URL, [['name' => 'A', 'position' => 0]]),
            self::entry(self::FEED_URL),
            self::entryState(self::FEED_URL),
            self::footer(['tag' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 1, 'entryState' => 1]),
        ]);

        $inventory = self::inspector()->inspect($gzip);

        self::assertSame(1, $inventory->entries);
        self::assertSame(1, $inventory->entryStates);
    }
}
