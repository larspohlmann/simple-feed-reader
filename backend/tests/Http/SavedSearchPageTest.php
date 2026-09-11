<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\EntryCursor;
use App\Http\SavedSearchPage;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Service\Search\SavedSearchEntriesResult;
use PHPUnit\Framework\TestCase;

final class SavedSearchPageTest extends TestCase
{
    public function testTheContinuationRowDecidesTheCursor(): void
    {
        $result = new SavedSearchEntriesResult([], [], matchCount: 1, continuationRow: $this->row(4));

        $page = SavedSearchPage::of($result, 1);

        self::assertSame([], $page['entries']);
        self::assertNotNull($page['nextCursor'], 'A fully-read page must still advance the cursor.');
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertNotNull($cursor);
        self::assertSame(4, $cursor->id);
    }

    public function testAnEmptyBadgeMapEncodesAsAnObject(): void
    {
        $result = new SavedSearchEntriesResult([], [], matchCount: 0);

        $page = SavedSearchPage::of($result, 50);

        self::assertEquals(new \stdClass(), $page['savedSearchIds']);
    }

    public function testTheBadgeMapIsCarriedThrough(): void
    {
        $row = $this->row(7);
        $result = new SavedSearchEntriesResult([$row], [7 => 3], matchCount: 1);

        $page = SavedSearchPage::of($result, 50);

        self::assertEquals((object) [7 => 3], $page['savedSearchIds']);
    }

    private function row(int $id): EntryListRow
    {
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid-' . $id,
            'https://example.com/entry-' . $id,
            'Angular ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-12T00:00:00Z'),
        );
        // Entry has no id setter: the id only exists once Doctrine assigns it,
        // and this test builds the row by hand without booting the kernel.
        $reflection = new \ReflectionProperty(Entry::class, 'id');
        $reflection->setValue($entry, $id);

        return new EntryListRow(
            entry: $entry,
            subscription: new EntryListRowSubscription(1, 'Example'),
            isHidden: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            viewedAt: null,
            markedReadUntil: null,
        );
    }
}
