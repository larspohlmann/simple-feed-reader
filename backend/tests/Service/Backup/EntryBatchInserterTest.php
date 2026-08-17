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
    private static function line(string $guid, string $title): EntryLine
    {
        return new EntryLine(
            feedUrl: 'https://batch.example/feed.xml',
            guid: $guid,
            guidHash: hash('sha256', $guid),
            url: 'https://batch.example/' . $guid,
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

    public function testInsertsMoreRowsThanOneStatementHolds(): void
    {
        $feed = new Feed('https://batch.example/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();
        $feedId = (int) $feed->getId();
        $lines = [];
        for ($i = 0; $i < 501; ++$i) {
            $lines[] = self::line('guid-' . $i, 'Entry ' . $i);
        }

        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);
        $inserter->insert($feedId, $lines);

        $this->em->clear();
        $rows = $this->em->getRepository(Entry::class)->findBy(['feed' => $feedId]);
        self::assertCount(501, $rows);
    }

    public function testARowRoundTripsFieldForFieldThroughTheOrm(): void
    {
        $feed = new Feed('https://batch.example/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();

        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);
        $inserter->insert((int) $feed->getId(), [self::line('one-guid', 'One')]);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guidHash' => hash('sha256', 'one-guid')]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('one-guid', $entry->getGuid());
        self::assertSame('One', $entry->getTitle());
        self::assertSame('Ann Author', $entry->getAuthor());
        self::assertSame('sum', $entry->getSummary());
        self::assertSame('<p>body</p>', $entry->getContentHtml());
        self::assertNull($entry->getImageUrl());
        self::assertSame(640, $entry->getImageWidth());
        self::assertSame('2026-08-01 10:00:00', $entry->getPublishedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-02 00:00:00', $entry->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01 10:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testAnEmptyListDoesNothing(): void
    {
        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);

        $inserter->insert(999, []);

        $this->addToAssertionCount(1);
    }
}
