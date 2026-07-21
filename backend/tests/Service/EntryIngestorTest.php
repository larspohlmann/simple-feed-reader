<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\EntryIngestor;
use App\Service\EntrySanitizer;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class EntryIngestorTest extends DbTestCase
{
    private EntryIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        $this->ingestor = new EntryIngestor(
            $this->em,
            $entryRepository,
            new EntrySanitizer(),
            new MockClock('2026-07-21 12:00:00', 'UTC'),
        );
    }

    private function parsedEntry(string $guid, string $title, ?string $contentHtml = null): ParsedEntry
    {
        return new ParsedEntry(
            guid: $guid,
            url: 'https://example.com/' . $guid,
            title: $title,
            author: 'Author',
            summary: '<p>A &amp; B summary</p>',
            contentHtml: $contentHtml ?? '<p>Body</p><script>evil()</script>',
            publishedAt: new \DateTimeImmutable('2026-07-20 08:00:00'),
        );
    }

    public function testIngestsNewEntriesSanitizedAndDeduped(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $parsed = new ParsedFeed('Feed Title', 'https://example.com/', 'Desc', [
            $this->parsedEntry('g1', 'One'),
            $this->parsedEntry('g2', 'Two'),
            $this->parsedEntry('g1', 'Duplicate of one'),
        ]);

        $created = $this->ingestor->ingest($feed, $parsed);
        $this->em->flush();

        self::assertSame(2, $created);
        $entries = $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]);
        self::assertCount(2, $entries);

        $first = $entries[0];
        self::assertStringNotContainsString('script', (string) $first->getContentHtml());
        self::assertSame('A & B summary', $first->getSummary());
        self::assertSame('Feed Title', $feed->getTitle());
        self::assertSame('https://example.com/', $feed->getSiteUrl());
    }

    public function testSecondIngestOnlyAddsUnseenGuids(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, [
            $this->parsedEntry('g1', 'One'),
        ]));
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, [
            $this->parsedEntry('g1', 'One again'),
            $this->parsedEntry('g3', 'Three'),
        ]));
        $this->em->flush();

        self::assertSame(1, $created);
        self::assertCount(2, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    public function testSameGuidInDifferentFeedsAreSeparateEntries(): void
    {
        $feedA = new Feed('https://a.example.com/feed');
        $feedB = new Feed('https://b.example.com/feed');
        $this->em->persist($feedA);
        $this->em->persist($feedB);
        $this->em->flush();

        $parsed = new ParsedFeed(null, null, null, [$this->parsedEntry('shared-guid', 'Shared')]);
        self::assertSame(1, $this->ingestor->ingest($feedA, $parsed));
        self::assertSame(1, $this->ingestor->ingest($feedB, $parsed));
        $this->em->flush();

        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feedA]));
        self::assertCount(1, $this->em->getRepository(Entry::class)->findBy(['feed' => $feedB]));
    }

    public function testOverlongFieldsAreTruncatedToColumnLimits(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $parsed = new ParsedFeed(str_repeat('T', 900), null, null, [
            new ParsedEntry(
                guid: 'long',
                url: 'https://example.com/' . str_repeat('u', 3000),
                title: str_repeat('t', 2000),
                author: str_repeat('a', 500),
                summary: str_repeat('s', 2000),
                contentHtml: '<p>ok</p>',
                publishedAt: null,
            ),
        ]);

        $this->ingestor->ingest($feed, $parsed);
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertNotNull($entry);
        self::assertSame(1024, mb_strlen($entry->getTitle()));
        self::assertSame(255, mb_strlen((string) $entry->getAuthor()));
        self::assertSame(2048, mb_strlen((string) $entry->getUrl()));
        self::assertLessThanOrEqual(500, mb_strlen((string) $entry->getSummary()));
        self::assertSame(512, mb_strlen((string) $feed->getTitle()));
    }

    public function testEmptyParsedFeedStillUpdatesMetadata(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed('New Title', null, null, []));

        self::assertSame(0, $created);
        self::assertSame('New Title', $feed->getTitle());
    }

    public function testNullMetadataDoesNotWipeExistingFeedFields(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setTitle('Existing Title');
        $feed->setSiteUrl('https://existing.example.com/');
        $this->em->persist($feed);
        $this->em->flush();

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, []));

        self::assertSame('Existing Title', $feed->getTitle());
        self::assertSame('https://existing.example.com/', $feed->getSiteUrl());
    }
}
