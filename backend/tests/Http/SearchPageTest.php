<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\SearchPage;
use App\Pagination\EntryCursor;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Service\Search\Model\EntrySearchResultModel;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

/**
 * The paging rules themselves belong to EntryPage and are tested there; this
 * covers only what search adds on top of them.
 */
final class SearchPageTest extends TestCase
{
    use AssignsEntityIds;

    public function testAnEmptyResultCarriesNoEntriesAndNoMatchedWords(): void
    {
        $page = SearchPage::of(EntrySearchResultModel::rowsOnly([]), 50);

        self::assertSame([], $page['entries']);
        self::assertNull($page['nextCursor']);
        self::assertSame([], $page['matchedWords']);
    }

    public function testTheMatchedWordsReachThePage(): void
    {
        $result = new EntrySearchResultModel([$this->row(7)], ['Angular', 'signals']);

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
        $page = SearchPage::of(EntrySearchResultModel::rowsOnly([$this->row(7)]), 50);

        self::assertArrayHasKey('matchedWords', $page);
        self::assertSame([], $page['matchedWords']);
    }

    /**
     * Indexed search can match more ids than hydrate (a ghost left by a failed async index delete); SearchPage must
     * still offer a cursor then, or the client thinks the results ended.
     */
    public function testAFullEngineMatchStillOffersACursorWhenARowWasDropped(): void
    {
        $result = new EntrySearchResultModel([$this->row(7)], ['angular'], matchCount: 2);

        $page = SearchPage::of($result, 2);

        self::assertNotNull(
            $page['nextCursor'],
            'A row the caller cannot see must not truncate a full page of engine matches.',
        );
    }

    /** A genuinely final page, where the engine matched fewer ids than the limit, still ends pagination. */
    public function testAShortEngineMatchOffersNoNextCursor(): void
    {
        $result = new EntrySearchResultModel([$this->row(7)], ['angular'], matchCount: 1);

        $page = SearchPage::of($result, 2);

        self::assertNull($page['nextCursor']);
    }

    /**
     * LikeEntrySearch's row count is its match count, so rowsOnly() defaults matchCount to count($rows) and a full
     * page keeps its cursor.
     */
    public function testTheDatabasePathOffersACursorFromRowCountAlone(): void
    {
        $result = EntrySearchResultModel::rowsOnly([$this->row(7)]);

        $page = SearchPage::of($result, 1);

        self::assertNotNull($page['nextCursor']);
    }

    /**
     * SearchPage forwards the continuation row, so the cursor resumes past the last hydrated candidate, not the last
     * shown row; otherwise a page whose tail was read re-reads it or, when empty, stops.
     */
    public function testTheContinuationRowDecidesTheCursor(): void
    {
        $result = new EntrySearchResultModel([], ['angular'], matchCount: 1, continuationRow: $this->row(4));

        $page = SearchPage::of($result, 1);

        self::assertSame([], $page['entries']);
        self::assertNotNull($page['nextCursor'], 'A fully-read page must still advance the cursor.');
        $cursor = EntryCursor::decode($page['nextCursor']);
        self::assertSame(4, $cursor->id);
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
        self::assignId($entry, $id);

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
