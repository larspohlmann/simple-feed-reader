<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\UrlCleanup;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Repository\SharedLinkRepository;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Foundation\Url\EntryUrlHash;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Operations\UrlCleanup\Model\TrackingUrlCleanupReportModel;
use App\Service\Operations\UrlCleanup\StoredUrlWriter\DryRunStoredUrlWriter;
use App\Service\Operations\UrlCleanup\StoredUrlWriter\StoredUrlWriter;
use App\Service\Operations\UrlCleanup\TrackingUrlCleanup;
use App\Tests\DbTestCase;

final class TrackingUrlCleanupTest extends DbTestCase
{
    private const string BLUESKY_GUID = 'at://did:plc:abc/app.bsky.feed.post/3k1';
    private const string HELD_URL = 'https://www.zdfheute.de/politik/a-100.html?at_specific=ZDFheute';
    private const string HELD_LANDING = 'https://www.zdfheute.de/politik/a-100.html';

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

        self::assertEquals(self::fixtureReport(), $report);
        self::assertSame($before, $this->rows());
    }

    public function testCleaningRewritesOnlyRowsThatChange(): void
    {
        $clean = $this->row('clean');

        $report = $this->cleanup()->clean();

        self::assertEquals(self::fixtureReport(), $report);
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

        self::assertEquals(new TrackingUrlCleanupReportModel(), $this->cleanup()->clean());
    }

    public function testTheWalkCrossesBatchBoundaries(): void
    {
        self::assertEquals(self::fixtureReport(), $this->cleanup(1)->clean());
        self::assertEquals(new TrackingUrlCleanupReportModel(), $this->cleanup(3)->clean());
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

    public function testAStaleLinkHashIsRecomputedAndItsUrlKept(): void
    {
        $this->cleanup()->clean();

        $stale = $this->linkRow('stale');
        self::assertSame(
            [
                'url' => 'https://www.aljazeera.com/news/1?traffic_source=rss',
                'url_hash' => self::hashOf('https://www.aljazeera.com/news/1?traffic_source=rss'),
            ],
            $stale,
        );
        self::assertNotSame(self::hashOf('https://www.aljazeera.com/news/1'), $stale['url_hash']);
    }

    public function testALinkWhoseUrlAndHashBothChangeGetsBoth(): void
    {
        $this->cleanup()->clean();

        $url = 'https://www.aljazeera.com/news/2?traffic_source=rss';
        self::assertSame(['url' => $url, 'url_hash' => self::hashOf($url)], $this->linkRow('staleAndTracked'));
    }

    public function testALinkWithoutAnAbsoluteUrlIsLeftAsItIs(): void
    {
        $this->cleanup()->clean();

        self::assertSame(
            ['url' => '/news/3?utm_source=x', 'url_hash' => self::hashOf('https://example.com/news/3')],
            $this->linkRow('relative'),
        );
    }

    public function testAStaleLinkWhoseNewHashIsHeldIsMergedIntoTheHolder(): void
    {
        $held = $this->storeHeldPair();
        $this->storeResolution($held['stale'], 'https://landing.example/stale');

        $report = $this->cleanup()->clean();

        self::assertEquals(self::fixtureReport(cardsMerged: 1), $report);
        self::assertSame(
            [['id' => $held['holder'], 'url_hash' => self::hashOf(self::HELD_URL)]],
            $this->linksOn(self::HELD_URL),
        );
        self::assertSame(
            ['card' => $held['holder'], 'quote' => $held['holder'], 'trending' => $held['holder']],
            $this->referencesFrom($held['entry']),
        );
        self::assertSame([[$held['holder'], 'https://landing.example/stale']], $this->resolutions());
        self::assertNull($this->entityManager->getConnection()->fetchOne(
            'SELECT unreferenced_since FROM shared_link WHERE id = ?',
            [$held['holder']],
        ));
        self::assertEquals(new TrackingUrlCleanupReportModel(), $this->cleanup()->clean());
    }

    public function testTheHoldersResolutionWinsOverTheMergedOne(): void
    {
        $held = $this->storeHeldPair();
        $this->storeResolution($held['stale'], 'https://landing.example/stale');
        $this->storeResolution($held['holder'], 'https://landing.example/holder');

        $this->cleanup()->clean();

        self::assertSame([[$held['holder'], 'https://landing.example/holder']], $this->resolutions());
    }

    public function testTheDryRunReportsTheMergeAndWritesNothing(): void
    {
        $held = $this->storeHeldPair();
        $this->storeResolution($held['stale'], 'https://landing.example/stale');
        $before = [$this->rows(), $this->referencesFrom($held['entry']), $this->resolutions()];

        $report = $this->cleanup()->count();

        self::assertEquals(self::fixtureReport(cardsMerged: 1), $report);
        self::assertSame($before, [$this->rows(), $this->referencesFrom($held['entry']), $this->resolutions()]);
    }

    public function testTwoLinksOfOneBatchMovingOntoOneHashBecomeOne(): void
    {
        $first = $this->storeLink(self::HELD_URL, hash('sha256', 'first'));
        $this->storeLink(self::HELD_URL, hash('sha256', 'second'));
        $expected = new TrackingUrlCleanupReportModel(
            entriesCleaned: 3,
            hashesChanged: 2,
            cardsCleaned: 2,
            cardHashesChanged: 3,
            cardsMerged: 1,
        );

        self::assertEquals($expected, $this->cleanup(10)->count());
        self::assertEquals($expected, $this->cleanup(10)->clean());
        self::assertSame(
            [['id' => $first, 'url_hash' => self::hashOf(self::HELD_URL)]],
            $this->linksOn(self::HELD_URL),
        );
    }

    public function testAHashALinkOfTheBatchGaveUpIsFreeForTheNext(): void
    {
        $leaving = $this->storeLink('https://example.com/leaving?utm_source=x', self::hashOf(self::HELD_URL));
        $arriving = $this->storeLink(self::HELD_URL, hash('sha256', 'arriving'));

        $report = $this->cleanup(10)->clean();

        self::assertSame(0, $report->cardsMerged);
        self::assertSame(
            [['id' => $arriving, 'url_hash' => self::hashOf(self::HELD_URL)]],
            $this->linksOn(self::HELD_URL),
        );
        self::assertSame(self::hashOf('https://example.com/leaving'), $this->linkHashOf($leaving));
    }

    /**
     * A link stored under the old lists' hash beside one a writer stored after deploy under the new hash (marked
     * unreferenced by the GC), and an entry whose card, quote and trending row point at the stale one.
     *
     * @return array{stale: int, holder: int, entry: int}
     */
    private function storeHeldPair(): array
    {
        $connection = $this->entityManager->getConnection();
        $stale = $this->storeLink(self::HELD_URL, self::hashOf(self::HELD_LANDING));
        $holder = $this->storeLink(self::HELD_URL, self::hashOf(self::HELD_URL));
        $connection->update('shared_link', ['unreferenced_since' => '2026-11-15 12:00:00'], ['id' => $holder]);
        $feed = new Feed('https://example.com/quotes');
        $this->entityManager->persist($feed);
        $entry = $this->entry($feed, 'g-quote', 'https://example.com/q', self::hashOf('https://example.com/q'));
        $this->entityManager->flush();
        $entryId = $entry->requireId();
        $this->entityManager->clear();
        $connection->insert('link_card', ['entry_id' => $entryId, 'position' => 0, 'shared_link_id' => $stale]);
        $connection->update('entry', ['quoted_shared_link_id' => $stale], ['id' => $entryId]);
        $connection->insert('trending_story', [
            'time_window' => 'day',
            'first_seen' => '2026-11-15 12:00:00',
            'last_seen' => '2026-11-15 12:00:00',
        ]);
        $connection->insert('trending_story_entry', [
            'story_id' => (int) $connection->lastInsertId(),
            'entry_id' => $entryId,
            'feed_id' => $feed->requireId(),
            'shared_link_id' => $stale,
        ]);

        return ['stale' => $stale, 'holder' => $holder, 'entry' => $entryId];
    }

    private function storeLink(string $url, string $urlHash): int
    {
        $connection = $this->entityManager->getConnection();
        $connection->insert('shared_link', [
            'url' => $url,
            'url_hash' => $urlHash,
            'title' => 'Card',
            'site_name' => 'example.com',
            'created_at' => '2026-11-15 12:00:00',
        ]);

        return (int) $connection->lastInsertId();
    }

    private function storeResolution(int $linkId, string $resolvedUrl): void
    {
        $this->entityManager->getConnection()->insert('shared_link_resolution', [
            'shared_link_id' => $linkId,
            'status' => 'resolved',
            'resolved_url' => $resolvedUrl,
            'resolved_url_hash' => self::hashOf($resolvedUrl),
            'attempts' => 1,
        ]);
    }

    /** @return list<array{id: int, url_hash: string}> */
    private function linksOn(string $url): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, url_hash FROM shared_link WHERE url = ? ORDER BY id',
            [$url],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => self::idOf($row['id']),
                'url_hash' => self::textOf($row['url_hash']),
            ],
            $rows,
        );
    }

    /** @return array{card: int, quote: int, trending: int} */
    private function referencesFrom(int $entryId): array
    {
        return [
            'card' => $this->idFrom('SELECT shared_link_id FROM link_card WHERE entry_id = ?', $entryId),
            'quote' => $this->idFrom('SELECT quoted_shared_link_id FROM entry WHERE id = ?', $entryId),
            'trending' => $this->idFrom('SELECT shared_link_id FROM trending_story_entry WHERE entry_id = ?', $entryId),
        ];
    }

    private function linkHashOf(int $linkId): string
    {
        return self::textOf(
            $this->entityManager->getConnection()->fetchOne('SELECT url_hash FROM shared_link WHERE id = ?', [$linkId]),
        );
    }

    private function idFrom(string $query, int $id): int
    {
        return self::idOf($this->entityManager->getConnection()->fetchOne($query, [$id]));
    }

    /** @return list<array{int, string}> shared link id and resolved URL */
    private function resolutions(): array
    {
        return array_map(
            static fn (array $row): array => [self::idOf($row['shared_link_id']), self::textOf($row['resolved_url'])],
            $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT shared_link_id, resolved_url FROM shared_link_resolution ORDER BY shared_link_id',
            ),
        );
    }

    private function cleanup(int $batchSize = 2): TrackingUrlCleanup
    {
        $entries = self::getContainer()->get(EntryRepository::class);
        $sharedLinks = self::getContainer()->get(SharedLinkRepository::class);
        self::assertInstanceOf(EntryRepository::class, $entries);
        self::assertInstanceOf(SharedLinkRepository::class, $sharedLinks);

        $resolutions = self::getContainer()->get(SharedLinkResolutionRepository::class);
        self::assertInstanceOf(SharedLinkResolutionRepository::class, $resolutions);

        return new TrackingUrlCleanup(
            $entries,
            $sharedLinks,
            $this->urlHash,
            new UrlNormalizer(),
            $this->entityManager,
            new StoredUrlWriter($sharedLinks, $resolutions),
            new DryRunStoredUrlWriter(),
            $batchSize,
        );
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
        $links = [
            'clean' => ['https://example.com/card', self::hashOf('https://example.com/card')],
            'tracked' => ['https://youtu.be/abc?si=y', self::hashOf('https://youtu.be/abc?si=y')],
            'stale' => [
                'https://www.aljazeera.com/news/1?traffic_source=rss',
                self::hashOf('https://www.aljazeera.com/news/1'),
            ],
            'staleAndTracked' => [
                'https://www.aljazeera.com/news/2?traffic_source=rss&utm_source=x',
                self::hashOf('https://www.aljazeera.com/news/2'),
            ],
            'relative' => ['/news/3?utm_source=x', self::hashOf('https://example.com/news/3')],
        ];
        foreach ($links as $name => [$url, $urlHash]) {
            $this->linkIds[$name] = $this->storeLink($url, $urlHash);
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

    /** What one run over the fixture of store() reports. */
    private static function fixtureReport(int $cardsMerged = 0): TrackingUrlCleanupReportModel
    {
        return new TrackingUrlCleanupReportModel(
            entriesCleaned: 3,
            hashesChanged: 2,
            cardsCleaned: 2,
            cardHashesChanged: 2,
            cardsMerged: $cardsMerged,
        );
    }

    private static function idOf(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private static function textOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function hashOf(string $url): string
    {
        return (string) (new UrlNormalizer())->hash($url);
    }
}
