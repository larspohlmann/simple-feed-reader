<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\EntryCursor;
use App\Http\EntryPage;
use App\Repository\EntryListRow;
use PHPUnit\Framework\TestCase;

final class EntryPageTest extends TestCase
{
    public function testAShortPageOffersNoNextCursor(): void
    {
        $page = EntryPage::of([], 50);

        self::assertSame([], $page['entries']);
        self::assertNull($page['nextCursor']);
    }

    public function testAFullPageOffersACursorFromTheLastRow(): void
    {
        $newerDate = new \DateTimeImmutable('2026-07-12T00:00:00Z');
        $olderDate = new \DateTimeImmutable('2026-07-10T00:00:00Z');
        // Rows arrive newest-first, the order EntryRepository::listForUser
        // returns them in; the cursor must key off the OLDEST (last) row so a
        // client asking for "what comes after this page" gets older entries.
        $newer = $this->rowForEntry(3, $newerDate);
        $older = $this->rowForEntry(1, $olderDate);

        $page = EntryPage::of([$newer, $older], 2);

        self::assertNotNull($page['nextCursor']);
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertNotNull($cursor);
        $expectedEffectiveDate = $olderDate->format(\DateTimeInterface::ATOM);
        self::assertSame($expectedEffectiveDate, $cursor->effectiveDate->format(\DateTimeInterface::ATOM));
        self::assertSame(1, $cursor->id);
        // Pins the cursor to the LAST row specifically: a regression that swaps
        // array_key_last() for array_key_first() in EntryPage would produce the
        // newer row's id (3) here instead, and this assertion names that failure.
        self::assertNotSame($newer->entry->getId(), $cursor->id);
    }

    private function rowForEntry(int $id, \DateTimeImmutable $effectiveDate): EntryListRow
    {
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid-' . $id,
            'https://example.com/entry-' . $id,
            'Angular ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $effectiveDate,
        );
        // Entry has no id setter: the id only exists once Doctrine assigns it,
        // and this test builds the row by hand without booting the kernel.
        $reflection = new \ReflectionProperty(Entry::class, 'id');
        $reflection->setValue($entry, $id);

        return new EntryListRow(
            entry: $entry,
            subscriptionId: 1,
            subscriptionTitle: 'Example',
            isRead: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            markedReadUntil: null,
        );
    }
}
