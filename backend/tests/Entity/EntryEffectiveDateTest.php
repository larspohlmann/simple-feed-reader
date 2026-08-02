<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Entry;
use App\Entity\Feed;
use PHPUnit\Framework\TestCase;

final class EntryEffectiveDateTest extends TestCase
{
    public function testConstructionFallsBackToCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-01 10:00:00');

        $entry = $this->makeEntry($createdAt);

        self::assertEquals($createdAt, $entry->getEffectiveDate());
    }

    public function testSetPublishedAtMovesTheEffectiveDate(): void
    {
        $publishedAt = new \DateTimeImmutable('2026-07-15 08:30:00');
        $entry = $this->makeEntry(new \DateTimeImmutable('2026-08-01 10:00:00'));

        $entry->setPublishedAt($publishedAt);

        self::assertEquals($publishedAt, $entry->getEffectiveDate());
    }

    public function testClearingPublishedAtFallsBackToCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-01 10:00:00');
        $entry = $this->makeEntry($createdAt);
        $entry->setPublishedAt(new \DateTimeImmutable('2026-07-15 08:30:00'));

        $entry->setPublishedAt(null);

        self::assertEquals($createdAt, $entry->getEffectiveDate());
    }

    private function makeEntry(\DateTimeImmutable $createdAt): Entry
    {
        return new Entry(
            feed: new Feed('https://example.com/feed.xml'),
            guid: 'urn:uuid:effective-date',
            url: 'https://example.com/post/1',
            title: 'A post',
            createdAt: $createdAt,
        );
    }
}
