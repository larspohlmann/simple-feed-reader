<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\SearchPage;
use App\Repository\EntryListRow;
use App\Service\Search\EntrySearchResult;
use PHPUnit\Framework\TestCase;

/**
 * The paging rules themselves belong to EntryPage and are tested there; this
 * covers only what search adds on top of them.
 */
final class SearchPageTest extends TestCase
{
    public function testAnEmptyResultCarriesNoEntriesAndNoMatchedWords(): void
    {
        $page = SearchPage::of(EntrySearchResult::rowsOnly([]), 50);

        self::assertSame([], $page['entries']);
        self::assertNull($page['nextCursor']);
        self::assertSame([], $page['matchedWords']);
    }

    public function testTheMatchedWordsReachThePage(): void
    {
        $result = new EntrySearchResult([$this->row(7)], ['Angular', 'signals']);

        $page = SearchPage::of($result, 50);

        self::assertSame(['Angular', 'signals'], $page['matchedWords']);
        self::assertCount(1, $page['entries']);
    }

    /**
     * An engine-less implementation reports no matched words, and the client
     * falls back to marking the literal terms it already holds. The key is
     * present either way so one client path reads it.
     */
    public function testAResultWithoutMatchedWordsStillCarriesTheKey(): void
    {
        $page = SearchPage::of(EntrySearchResult::rowsOnly([$this->row(7)]), 50);

        self::assertArrayHasKey('matchedWords', $page);
        self::assertSame([], $page['matchedWords']);
    }

    /**
     * The bug this branch fixes: IndexedEntrySearch can report a matchCount
     * higher than count($rows) when hydration drops an id (a ghost left by a
     * failed async index delete, for one). SearchPage must still offer a
     * cursor in that case, or the client believes it has reached the end of
     * the results.
     */
    public function testAFullEngineMatchStillOffersACursorWhenARowWasDropped(): void
    {
        $result = new EntrySearchResult([$this->row(7)], ['angular'], matchCount: 2);

        $page = SearchPage::of($result, 2);

        self::assertNotNull(
            $page['nextCursor'],
            'A row the caller cannot see must not truncate a full page of engine matches.',
        );
    }

    /**
     * A genuinely final page — the engine itself matched fewer ids than the
     * limit — must still end pagination once the truncation bug is fixed.
     */
    public function testAShortEngineMatchOffersNoNextCursor(): void
    {
        $result = new EntrySearchResult([$this->row(7)], ['angular'], matchCount: 1);

        $page = SearchPage::of($result, 2);

        self::assertNull($page['nextCursor']);
    }

    /**
     * The database path: LikeEntrySearch's row count IS its match count
     * (nothing removes rows after the query runs), so rowsOnly() must default
     * matchCount to count($rows) and the resulting page must be unchanged
     * from before this fix.
     */
    public function testTheDatabasePathOffersACursorFromRowCountAlone(): void
    {
        $result = EntrySearchResult::rowsOnly([$this->row(7)]);

        $page = SearchPage::of($result, 1);

        self::assertNotNull($page['nextCursor']);
    }

    private function row(int $id): EntryListRow
    {
        $entry = new Entry(
            new Feed('https://example.com/feed.xml'),
            'guid',
            'https://example.com/entry',
            'Angular ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        // Entry has no id setter: the id only exists once Doctrine assigns it,
        // and this test builds the row by hand without booting the kernel.
        new \ReflectionProperty(Entry::class, 'id')->setValue($entry, $id);

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
