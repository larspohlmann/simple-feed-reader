<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Discovery\DiscoveredFeed;
use App\Service\EntryIngestor;
use App\Service\EntrySanitizer;
use App\Service\FeedScheduler;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Subscription\FirstFetchRecorder;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class FirstFetchRecorderTest extends DbTestCase
{
    private FirstFetchRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new MockClock('2026-06-01T00:00:00Z');
        $this->recorder = new FirstFetchRecorder(
            new EntryIngestor(
                $this->em,
                $this->em->getRepository(Entry::class),
                new EntrySanitizer(),
            ),
            new FeedScheduler($clock),
            $this->em,
            $clock,
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

    /**
     * Even a feed well under the cap must come out newest-first: EntryIngestor
     * persists in array order, so a feed serving its entries oldest-first
     * would otherwise store them oldest-first too. Only 2 entries here — far
     * under FIRST_FETCH_MAX_ENTRIES — so this fails if newest() ever special-
     * cases "small feed, skip the sort" instead of always sorting.
     */
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

    /**
     * Two entries sharing a publication date are not sorted apart: the
     * feed's own relative order survives the tie, so a source that batch-
     * publishes (a daily digest, a static-site generator, a feed truncating
     * to whole minutes) does not have its entries reordered arbitrarily.
     */
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

    /** A document sitting exactly at the cap is not truncated. */
    public function testExactlyTwoHundredEntriesAreAllStored(): void
    {
        $feed = $this->feed();

        self::assertSame(200, $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(200))));
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    /** @param list<ParsedEntry> $entries */
    private function discovered(Feed $feed, array $entries): DiscoveredFeed
    {
        return new DiscoveredFeed(
            $feed->getUrl(),
            new ParsedFeed('Discovered', null, null, $entries),
        );
    }

    private function parsedEntry(string $guid, ?\DateTimeImmutable $publishedAt): ParsedEntry
    {
        return new ParsedEntry(
            guid: $guid,
            url: 'https://example.com/' . $guid,
            title: 'Title ' . $guid,
            author: null,
            summary: null,
            contentHtml: '<p>Body.</p>',
            publishedAt: $publishedAt,
            image: null,
        );
    }

    /** @return list<ParsedEntry> */
    private function parsedEntries(int $count): array
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entries[] = $this->parsedEntry(
                'guid-' . $i,
                new \DateTimeImmutable(sprintf('2026-01-01 00:00:00 +%d minutes', $i)),
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
        $entry = $this->em->getRepository(Entry::class)->findOneBy([
            'feed' => $feed,
            'guidHash' => hash('sha256', $guid),
        ]);

        return $entry;
    }

    /**
     * The order EntryIngestor persisted the entries in, read back through the
     * auto-increment id rather than any query-time ORDER BY — the repository
     * sorts a feed's list by (effectiveDate, id) for the reader, which would
     * mask a wrong ingest order behind an incidentally-correct display order.
     *
     * @return list<string>
     */
    private function guidsByInsertionOrder(Feed $feed): array
    {
        /** @var list<Entry> $entries */
        $entries = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Entry::class, 'e')
            ->where('e.feed = :feed')
            ->setParameter('feed', $feed)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (Entry $entry): string => $entry->getGuid(), $entries);
    }
}
