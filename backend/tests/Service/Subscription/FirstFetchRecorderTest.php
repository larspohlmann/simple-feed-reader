<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Search\EntryIndexer;
use App\Service\Subscription\FirstFetchRecorder;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FeedSchedulers;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class FirstFetchRecorderTest extends DbTestCase
{
    private FirstFetchRecorder $recorder;
    private RecordingSearchIndexWriter $indexWriter;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new MockClock('2026-06-01T00:00:00Z');
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->recorder = new FirstFetchRecorder(
            EntryIngestors::build($this->entityManager, $clock),
            FeedSchedulers::build($clock),
            $this->entityManager,
            $clock,
            new EntryIndexer($this->indexWriter, new NullLogger()),
        );
    }

    public function testAFirstFetchStoresTheArticlesOwnPublicationDates(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('a', new \DateTimeImmutable('2020-03-01 00:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        self::assertSame('2020-03-01 00:00:00', $this->effectiveDateOf($feed, 'a'));
    }

    public function testAFirstFetchStoresTheDiscoveredCacheValidators(): void
    {
        $feed = $this->feed();
        $discovered = new DiscoveredFeedModel(
            $feed->getUrl(),
            new ParsedFeedModel('Discovered', null, null, null, []),
            '"v1"',
            'Mon, 20 Jul 2026 08:30:00 GMT',
        );

        $this->recorder->record($feed, $discovered);

        self::assertSame('"v1"', $feed->getEtag());
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $feed->getLastModified());
    }

    /**
     * EntryIngestor never flushes, so an entry has no id before record()'s flush: the index must get the id the
     * database assigned, which calling index() anywhere inside record() would not ensure.
     */
    public function testIndexesTheFirstFetchEntriesWithTheirRealIdsAfterFlush(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('a', new \DateTimeImmutable('2020-03-01 00:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        $entry = $this->findByGuid($feed, 'a');
        self::assertNotNull($entry);
        self::assertNotNull($entry->getId());

        self::assertSame(['configure', 'upsert'], $this->indexWriter->calls);
        self::assertCount(1, $this->indexWriter->upserts);
        self::assertSame($entry->getId(), $this->indexWriter->upserts[0][0]->id);
    }

    public function testAFirstFetchStoresAtMostTwoHundredEntries(): void
    {
        $feed = $this->feed();

        self::assertSame(200, $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250))));
    }

    public function testTheFirstFetchKeepsTheNewestEntries(): void
    {
        $feed = $this->feed();

        $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250)));

        self::assertNull($this->findByGuid($feed, 'guid-0'));
        self::assertNotNull($this->findByGuid($feed, 'guid-249'));
    }

    /** Far under the cap and still newest first: fails if newest() ever skips the sort for a small feed. */
    public function testASmallFeedIsStillSortedNewestFirst(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('older', new \DateTimeImmutable('2020-01-01 00:00:00')),
            $this->parsedEntry('newer', new \DateTimeImmutable('2021-01-01 00:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        self::assertSame(['newer', 'older'], $this->guidsByInsertionOrder($feed));
    }

    /** Entries sharing a publication date keep the feed's own order, as a batch-publishing source expects. */
    public function testTiedPublicationDatesKeepTheFeedsOwnOrder(): void
    {
        $feed = $this->feed();
        $same = new \DateTimeImmutable('2026-01-01 00:00:00');
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('b', $same),
            $this->parsedEntry('a', $same),
        ]);

        $this->recorder->record($feed, $discovered);

        self::assertSame(['b', 'a'], $this->guidsByInsertionOrder($feed));
    }

    public function testExactlyTwoHundredEntriesAreAllStored(): void
    {
        $feed = $this->feed();

        self::assertSame(200, $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(200))));
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    /** @param list<ParsedEntryModel> $entries */
    private function discovered(Feed $feed, array $entries, ?string $imageUrl = null): DiscoveredFeedModel
    {
        return new DiscoveredFeedModel(
            $feed->getUrl(),
            new ParsedFeedModel('Discovered', null, null, $imageUrl, $entries),
        );
    }

    private function parsedEntry(string $guid, ?\DateTimeImmutable $publishedAt): ParsedEntryModel
    {
        return new ParsedEntryModel(
            guid: $guid,
            url: 'https://example.com/' . $guid,
            title: 'Title ' . $guid,
            author: null,
            summary: null,
            contentHtml: '<p>Body.</p>',
            publishedAt: $publishedAt,
        );
    }

    /** @return list<ParsedEntryModel> */
    private function parsedEntries(int $count): array
    {
        $entries = [];
        for ($index = 0; $index < $count; $index++) {
            $entries[] = $this->parsedEntry(
                'guid-' . $index,
                new \DateTimeImmutable(sprintf('2026-01-01 00:00:00 +%d minutes', $index)),
            );
        }

        return $entries;
    }

    private function effectiveDateOf(Feed $feed, string $guid): string
    {
        $entry = $this->findByGuid($feed, $guid);
        self::assertNotNull($entry);

        return $entry->getEffectiveDate()->format('Y-m-d H:i:s');
    }

    private function findByGuid(Feed $feed, string $guid): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy([
            'feed' => $feed,
            'guidHash' => hash('sha256', $guid),
        ]);

        return $entry;
    }

    /**
     * The order EntryIngestor persisted in, by auto-increment id: the repository's (effectiveDate, id) display order
     * would mask a wrong ingest order.
     *
     * @return list<string>
     */
    private function guidsByInsertionOrder(Feed $feed): array
    {
        /** @var list<Entry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Entry::class, 'e')
            ->where('e.feed = :feed')
            ->setParameter('feed', $feed)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (Entry $entry): string => $entry->getGuid(), $entries);
    }

    public function testWithEntriesCopiesEveryFeedField(): void
    {
        $document = new ParsedFeedModel(
            'Example',
            'https://example.com/',
            'Example feed',
            'https://example.com/logo.png',
            [],
        );

        $capped = $document->withEntries([]);

        self::assertSame('https://example.com/logo.png', $capped->imageUrl);
        self::assertSame('Example', $capped->title);
        self::assertSame('https://example.com/', $capped->siteUrl);
        self::assertSame('Example feed', $capped->description);
    }

    /**
     * Drives the wired recorder's own cap with 250 entries and an image, so a newest() that rebuilds ParsedFeedModel
     * field by field instead of calling withEntries() loses the image on the persisted Feed.
     */
    public function testCappingTheEntryListKeepsTheFeedImage(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, $this->parsedEntries(250), 'https://example.com/logo.png');

        self::assertSame(200, $this->recorder->record($feed, $discovered));

        self::assertSame('https://example.com/logo.png', $feed->getImageUrl());
    }
}
