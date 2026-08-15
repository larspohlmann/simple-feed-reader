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

    public function testAFullPageOffersACursor(): void
    {
        $effectiveDate = new \DateTimeImmutable('2026-07-12T00:00:00Z');
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid',
            'https://example.com/entry',
            'Angular ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $effectiveDate,
        );
        // Entry has no id setter: the id only exists once Doctrine assigns it,
        // and this test builds the row by hand without booting the kernel.
        $reflection = new \ReflectionProperty(Entry::class, 'id');
        $reflection->setValue($entry, 7);

        $row = new EntryListRow(
            entry: $entry,
            subscriptionId: 1,
            subscriptionTitle: 'Example',
            isRead: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            markedReadUntil: null,
        );

        $page = EntryPage::of([$row], 1);

        self::assertNotNull($page['nextCursor']);
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertNotNull($cursor);
        $expectedEffectiveDate = $effectiveDate->format(\DateTimeInterface::ATOM);
        self::assertSame($expectedEffectiveDate, $cursor->effectiveDate->format(\DateTimeInterface::ATOM));
        self::assertSame(7, $cursor->id);
    }
}
