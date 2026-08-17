<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupInspector;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\TestCase;

final class BackupInspectorTest extends TestCase
{
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

    public function testCountsEveryKind(): void
    {
        $gzip = self::gzipOf([
            self::header(), self::account(),
            ['kind' => 'tag', 'name' => 'A', 'color' => null, 'icon' => null, 'position' => 0],
            ['kind' => 'tag', 'name' => 'B', 'color' => null, 'icon' => null, 'position' => 1],
            ['kind' => 'feed', 'url' => 'https://f.example/feed.xml', 'siteUrl' => null, 'title' => null,
                'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'],
            ['kind' => 'subscription', 'feedUrl' => 'https://f.example/feed.xml', 'customTitle' => null,
                'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00',
                'tags' => []],
            self::footer(['tag' => 2, 'feed' => 1, 'subscription' => 1]),
        ]);

        $inventory = new BackupInspector()->inspect($gzip);

        self::assertSame(2, $inventory->tags);
        self::assertSame(1, $inventory->feeds);
        self::assertSame(1, $inventory->subscriptions);
        self::assertSame(0, $inventory->entries);
        self::assertSame('source@example.com', $inventory->header->sourceEmail);
        self::assertSame('de', $inventory->account->locale);
    }

    public function testABrokenFileRefusesInsteadOfCounting(): void
    {
        $this->expectException(InvalidBackupException::class);

        new BackupInspector()->inspect((string) gzencode("{\"kind\":\"header\"}\n"));
    }
}
