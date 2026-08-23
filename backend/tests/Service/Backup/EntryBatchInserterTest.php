<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\EntryBatchInserter;
use App\Tests\DbTestCase;

final class EntryBatchInserterTest extends DbTestCase
{
    /**
     * Sentinel default for entryLine()'s $url: distinguishes "not given"
     * (generate one from the guid) from an explicit `null`, which `??`
     * cannot do.
     */
    private const string GENERATED_URL = "\0generated";

    private function createFeed(string $url): int
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $this->em->flush();

        return (int) $feed->getId();
    }

    private function entryLine(string $guid, string $title = 'Entry', ?string $url = self::GENERATED_URL): EntryLine
    {
        return new EntryLine(
            feedUrl: 'https://batch.example/feed.xml',
            guid: $guid,
            guidHash: hash('sha256', $guid),
            url: $url === self::GENERATED_URL ? 'https://batch.example/' . $guid : $url,
            title: $title,
            author: 'Ann Author',
            summary: 'sum',
            contentHtml: '<p>body</p>',
            imageUrl: null,
            imageWidth: 640,
            imageHeight: null,
            publishedAt: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            createdAt: new \DateTimeImmutable('2026-08-02T00:00:00+00:00'),
            effectiveDate: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
    }

    private function inserter(): EntryBatchInserter
    {
        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);

        return $inserter;
    }

    public function testInsertsMoreRowsThanOneStatementHolds(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');
        $lines = [];
        for ($i = 0; $i < 501; ++$i) {
            $lines[] = $this->entryLine('guid-' . $i, 'Entry ' . $i);
        }

        $this->inserter()->insert($feedId, $lines);

        $this->em->clear();
        $rows = $this->em->getRepository(Entry::class)->findBy(['feed' => $feedId]);
        self::assertCount(501, $rows);
    }

    public function testARowRoundTripsFieldForFieldThroughTheOrm(): void
    {
        $feedId = $this->createFeed('https://batch.example/feed.xml');

        $this->inserter()->insert($feedId, [$this->entryLine('one-guid', 'One')]);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guidHash' => hash('sha256', 'one-guid')]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('one-guid', $entry->getGuid());
        self::assertSame('https://batch.example/one-guid', $entry->getUrl());
        self::assertSame('One', $entry->getTitle());
        self::assertSame('Ann Author', $entry->getAuthor());
        self::assertSame('sum', $entry->getSummary());
        self::assertSame('<p>body</p>', $entry->getContentHtml());
        self::assertNull($entry->getImageUrl());
        self::assertSame(640, $entry->getImageWidth());
        self::assertNull($entry->getImageHeight());
        self::assertSame('2026-08-01 10:00:00', $entry->getPublishedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-02 00:00:00', $entry->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01 10:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testAnEmptyListDoesNothing(): void
    {
        $this->inserter()->insert(999, []);

        $this->addToAssertionCount(1);
    }

    public function testRecomputesTheStableUrlHashForEveryInsertedRow(): void
    {
        $feedId = $this->createFeed('https://hash.example/feed.xml');
        $this->inserter()->insert($feedId, [
            $this->entryLine(guid: 'a', url: 'https://hash.example/one?utm_source=rss'),
            $this->entryLine(guid: 'b', url: null),
        ]);

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT guid, url_hash FROM entry WHERE feed_id = ? ORDER BY guid',
            [$feedId],
        );

        self::assertSame(
            hash('sha256', 'https://hash.example/one'),
            $rows[0]['url_hash'],
            'A decorated URL must hash to its normalised form, exactly as ingest hashes it.',
        );
        self::assertNull($rows[1]['url_hash'], 'A url-less entry dedupes on guid alone.');
    }
}
