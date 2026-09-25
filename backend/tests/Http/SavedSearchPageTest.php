<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\EntryCursor;
use App\Http\SavedSearchPage;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Service\Search\SavedSearchEntriesResult;
use PHPUnit\Framework\TestCase;

final class SavedSearchPageTest extends TestCase
{
    public function testAFullPageAdvancesTheCursorPastItsLastRow(): void
    {
        $result = new SavedSearchEntriesResult([$this->row(4)]);

        $page = SavedSearchPage::of($result, 1);

        self::assertNotNull($page['nextCursor']);
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertSame(4, $cursor->id);
    }

    public function testAShortPageEndsTheList(): void
    {
        $result = new SavedSearchEntriesResult([$this->row(4)]);

        self::assertNull(SavedSearchPage::of($result, 50)['nextCursor']);
    }

    public function testEachEntryCarriesItsOwnSavedSearchMembership(): void
    {
        $row = $this->row(7)->withSavedSearches([['id' => 3, 'slug' => 'climate', 'term' => 'climate']]);
        $result = new SavedSearchEntriesResult([$row]);

        $page = SavedSearchPage::of($result, 50);

        self::assertSame([['id' => 3, 'slug' => 'climate', 'term' => 'climate']], $page['entries'][0]['savedSearches']);
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
            viewState: new EntryListRowViewState(isViewed: false, viewedAt: null),
            markedReadUntil: null,
        );
    }
}
