<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupReader;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\TestCase;

final class BackupReaderTest extends TestCase
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

    public function testReadsAMinimalValidFile(): void
    {
        $gzip = self::gzipOf([self::header(), self::account(), self::footer()]);

        $objects = iterator_to_array(new BackupReader()->read($gzip), false);

        self::assertCount(2, $objects);
        self::assertInstanceOf(BackupHeader::class, $objects[0]);
        self::assertSame(1, $objects[0]->schemaVersion);
        self::assertSame('source@example.com', $objects[0]->sourceEmail);
        self::assertInstanceOf(AccountLine::class, $objects[1]);
        self::assertSame('de', $objects[1]->locale);
        self::assertTrue($objects[1]->scrapeFallbackEnabled);
        self::assertNull($objects[1]->recommendationSettings);
    }

    public function testReadsEveryKindInOrderAndNormalisesDatesToUtc(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'tag', 'name' => 'Tech', 'color' => '#aabbcc', 'icon' => 'bolt', 'position' => 2],
            ['kind' => 'feed', 'url' => 'https://f.example/feed.xml', 'siteUrl' => null, 'title' => 'F',
                'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'],
            ['kind' => 'subscription', 'feedUrl' => 'https://f.example/feed.xml', 'customTitle' => null,
                'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T02:00:00+02:00',
                'tags' => [['name' => 'Tech', 'position' => 1]]],
            ['kind' => 'entry', 'feedUrl' => 'https://f.example/feed.xml', 'guid' => 'g1',
                'guidHash' => hash('sha256', 'g1'), 'url' => null, 'title' => 'One', 'author' => null,
                'summary' => null, 'contentHtml' => '<p>x</p>', 'imageUrl' => null, 'imageWidth' => null,
                'imageHeight' => null, 'publishedAt' => null, 'createdAt' => '2026-08-01T00:00:00+00:00',
                'effectiveDate' => '2026-08-01T00:00:00+00:00'],
            ['kind' => 'entryState', 'feedUrl' => 'https://f.example/feed.xml',
                'guidHash' => hash('sha256', 'g1'), 'isRead' => true, 'isFavorite' => false,
                'isKept' => false, 'readAt' => '2026-08-02T00:00:00+00:00', 'isViewed' => false,
                'viewedAt' => null],
            self::footer(['tag' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 1, 'entryState' => 1]),
        ]);

        $objects = iterator_to_array(new BackupReader()->read($gzip), false);

        self::assertCount(7, $objects);
        self::assertInstanceOf(TagLine::class, $objects[2]);
        self::assertInstanceOf(FeedLine::class, $objects[3]);
        $subscription = $objects[4];
        self::assertInstanceOf(SubscriptionLine::class, $subscription);
        // +02:00 wall clock normalised to naive-UTC storage time.
        self::assertSame('2026-07-01 00:00:00', $subscription->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('Tech', $subscription->tags[0]->name);
        self::assertInstanceOf(EntryLine::class, $objects[5]);
        self::assertInstanceOf(EntryStateLine::class, $objects[6]);
    }

    public function testRefusesANewerSchemaVersion(): void
    {
        $gzip = self::gzipOf([self::header(schemaVersion: 2), self::account(), self::footer()]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/schema version/i');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesWhenTheFirstLineIsNotAHeader(): void
    {
        $gzip = self::gzipOf([self::account(), self::header(), self::footer()]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesKindsOutOfOrder(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'subscription', 'feedUrl' => 'https://f.example/feed.xml', 'customTitle' => null,
                'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00',
                'tags' => []],
            ['kind' => 'feed', 'url' => 'https://f.example/feed.xml', 'siteUrl' => null, 'title' => null,
                'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'],
            self::footer(['subscription' => 1, 'feed' => 1]),
        ]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAFileWithoutAFooter(): void
    {
        // Simulates a truncation that happens to cut exactly at a line boundary.
        $gzip = self::gzipOf([self::header(), self::account()]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/truncated/i');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAFooterWhoseCountsDisagree(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'tag', 'name' => 'Tech', 'color' => null, 'icon' => null, 'position' => 0],
            self::footer(['tag' => 2]),
        ]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesLinesAfterTheFooter(): void
    {
        $gzip = self::gzipOf([
            self::header(), self::account(), self::footer(),
            ['kind' => 'tag', 'name' => 'Late', 'color' => null, 'icon' => null, 'position' => 0],
        ]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesBrokenJsonWithTheLineNumber(): void
    {
        $ndjson = json_encode(self::header(), \JSON_THROW_ON_ERROR) . "\n{broken\n";
        $gzip = (string) gzencode($ndjson);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/line 2/');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAMistypedFieldNamingTheKey(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'tag', 'name' => 42, 'color' => null, 'icon' => null, 'position' => 0],
        ]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/name/');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAFileMissingItsAccountLine(): void
    {
        $gzip = self::gzipOf([self::header(), self::footer()]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/account/i');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAnEmptyDateStringInsteadOfDefaultingToNow(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'feed', 'url' => 'https://f.example/feed.xml', 'siteUrl' => null, 'title' => null,
                'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'],
            ['kind' => 'subscription', 'feedUrl' => 'https://f.example/feed.xml', 'customTitle' => null,
                'position' => 0, 'markedReadUntil' => null, 'createdAt' => '', 'tags' => []],
        ]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/createdAt/');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }
}
