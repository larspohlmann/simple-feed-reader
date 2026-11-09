<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListSort;
use PHPUnit\Framework\TestCase;

final class EntryListSortTest extends TestCase
{
    public function testTheViewedSortReadsTheViewedInstant(): void
    {
        $viewedAt = new \DateTimeImmutable('2026-11-09T10:00:00Z');

        self::assertSame($viewedAt, EntryListSort::ViewedAt->instantOf($this->row(viewedAt: $viewedAt)));
    }

    public function testTheSharedSortReadsTheSharedInstant(): void
    {
        $sharedAt = new \DateTimeImmutable('2026-11-09T11:00:00Z');

        self::assertSame($sharedAt, EntryListSort::SharedAt->instantOf($this->row(sharedAt: $sharedAt)));
    }

    public function testTheViewedSortRejectsARowThatWasNeverViewed(): void
    {
        $this->expectException(\LogicException::class);

        EntryListSort::ViewedAt->instantOf($this->row());
    }

    public function testTheSharedSortRejectsARowThatWasNeverShared(): void
    {
        $this->expectException(\LogicException::class);

        EntryListSort::SharedAt->instantOf($this->row());
    }

    private function row(?\DateTimeImmutable $viewedAt = null, ?\DateTimeImmutable $sharedAt = null): EntryListRow
    {
        $publishedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');

        return new EntryListRow(
            entry: new Entry(
                new Feed('https://example.com/feed.xml'),
                'guid',
                'https://example.com/a',
                'A',
                $publishedAt,
                $publishedAt,
            ),
            subscription: new EntryListRowSubscription(1, 'Example'),
            marks: new EntryListRowMarks(
                isHidden: false,
                isFavorite: false,
                isKept: false,
                viewedAt: $viewedAt,
                sharedAt: $sharedAt,
            ),
            markedReadUntil: null,
        );
    }
}
