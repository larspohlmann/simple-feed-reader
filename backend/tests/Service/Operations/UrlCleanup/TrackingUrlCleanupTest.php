<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\UrlCleanup;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Repository\SharedLinkRepository;
use App\Service\Foundation\Url\EntryUrlHash;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Operations\UrlCleanup\Model\TrackingUrlCleanupReportModel;
use App\Service\Operations\UrlCleanup\TrackingUrlCleanup;
use App\Tests\DbTestCase;

final class TrackingUrlCleanupTest extends DbTestCase
{
    private const string BLUESKY_GUID = 'at://did:plc:abc/app.bsky.feed.post/3k1';

    private EntryUrlHash $urlHash;

    /** @var array<string, int> entry id by name */
    private array $entryIds = [];

    /** @var array<string, int> shared link id by name */
    private array $linkIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $urlHash = self::getContainer()->get(EntryUrlHash::class);
        self::assertInstanceOf(EntryUrlHash::class, $urlHash);
        $this->urlHash = $urlHash;
        $this->store();
    }

    public function testTheDryRunCountsAndWritesNothing(): void
    {
        $before = $this->rows();

        $report = $this->cleanup()->count();

        self::assertEquals(new TrackingUrlCleanupReportModel(3, 2, 1), $report);
        self::assertSame($before, $this->rows());
    }

    public function testCleaningRewritesOnlyRowsThatChange(): void
    {
        $clean = $this->row('clean');

        $report = $this->cleanup()->clean();

        self::assertEquals(new TrackingUrlCleanupReportModel(3, 2, 1), $report);
        self::assertSame(
            ['url' => 'https://example.com/a', 'url_hash' => self::hashOf('https://example.com/a')],
            $this->row('tracked'),
        );
        self::assertSame(self::hashOf('https://example.com/null'), $this->row('unhashed')['url_hash']);
        self::assertSame(
            ['url' => 'https://bsky.app/x', 'url_hash' => $this->blueskyHash()],
            $this->row('bluesky'),
        );
        self::assertSame(self::hashOf('https://example.com/b'), $this->row('misEscaped')['url_hash']);
        self::assertSame($clean, $this->row('clean'));
    }

    public function testASecondRunFindsNothing(): void
    {
        $this->cleanup()->clean();

        self::assertEquals(new TrackingUrlCleanupReportModel(0, 0, 0), $this->cleanup()->clean());
    }

    public function testTheWalkCrossesBatchBoundaries(): void
    {
        self::assertEquals(new TrackingUrlCleanupReportModel(3, 2, 1), $this->cleanup(1)->clean());
        self::assertEquals(new TrackingUrlCleanupReportModel(0, 0, 0), $this->cleanup(3)->clean());
    }

    public function testSharedLinkUrlsAreCleaned(): void
    {
        $this->cleanup()->clean();

        self::assertSame(
            ['url' => 'https://youtu.be/abc', 'url_hash' => self::hashOf('https://youtu.be/abc?si=y')],
            $this->linkRow('tracked'),
        );
        self::assertSame('https://example.com/card', $this->linkRow('clean')['url']);
    }

    private function cleanup(int $batchSize = 2): TrackingUrlCleanup
    {
        $entries = self::getContainer()->get(EntryRepository::class);
        $sharedLinks = self::getContainer()->get(SharedLinkRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entries);
        self::assertInstanceOf(SharedLinkRepository::class, $sharedLinks);

        return new TrackingUrlCleanup($entries, $sharedLinks, $this->urlHash, $this->entityManager, $batchSize);
    }

    private function store(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->entityManager->persist($feed);
        $tracked = 'https://example.com/a?utm_source=rss';
        $misEscaped = 'https://example.com/b?amp;utm_medium=rss';
        $entries = [
            'tracked' => $this->entry($feed, 'g-tracked', $tracked, self::hashOf('https://example.com/a')),
            'clean' => $this->entry($feed, 'g-clean', 'https://example.com/c', self::hashOf('https://example.com/c')),
            'unhashed' => $this->entry($feed, 'g-null', 'https://example.com/null', null),
            'bluesky' => $this->entry(
                $feed,
                self::BLUESKY_GUID,
                'https://bsky.app/x?utm_source=share',
                $this->blueskyHash(),
            ),
            'misEscaped' => $this->entry($feed, 'g-amp', $misEscaped, hash('sha256', $misEscaped)),
        ];
        $this->entityManager->flush();
        $this->storeLinks();
        foreach ($entries as $name => $entry) {
            $this->entryIds[$name] = $entry->requireId();
        }
        $this->entityManager->clear();
    }

    private function storeLinks(): void
    {
        $connection = $this->entityManager->getConnection();
        $urls = ['clean' => 'https://example.com/card', 'tracked' => 'https://youtu.be/abc?si=y'];
        foreach ($urls as $name => $url) {
            $connection->insert('shared_link', [
                'url' => $url,
                'url_hash' => self::hashOf($url),
                'title' => 'Card',
                'site_name' => 'example.com',
                'created_at' => '2026-11-15 12:00:00',
            ]);
            $this->linkIds[$name] = (int) $connection->lastInsertId();
        }
    }

    private function entry(Feed $feed, string $guid, string $url, ?string $urlHash): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-11-15 12:00:00');
        $entry = new Entry($feed, $guid, $url, 'Title', $createdAt, $createdAt, $urlHash);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /** @return array{url: mixed, url_hash: mixed} */
    private function row(string $name): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT url, url_hash FROM entry WHERE id = ?',
            [$this->entryIds[$name]],
        );
        self::assertIsArray($row);

        return ['url' => $row['url'], 'url_hash' => $row['url_hash']];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            ...$connection->fetchAllAssociative('SELECT id, url, url_hash FROM entry ORDER BY id'),
            ...$connection->fetchAllAssociative('SELECT id, url, url_hash FROM shared_link ORDER BY id'),
        ];
    }

    /** @return array{url: mixed, url_hash: mixed} */
    private function linkRow(string $name): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT url, url_hash FROM shared_link WHERE id = ?',
            [$this->linkIds[$name]],
        );
        self::assertIsArray($row);

        return ['url' => $row['url'], 'url_hash' => $row['url_hash']];
    }

    private function blueskyHash(): string
    {
        $hash = $this->urlHash->of(self::BLUESKY_GUID, null);
        self::assertNotNull($hash);
        self::assertNotSame(self::hashOf('https://bsky.app/x'), $hash);

        return $hash;
    }

    private static function hashOf(string $url): string
    {
        return (string) (new UrlNormalizer())->hash($url);
    }
}
