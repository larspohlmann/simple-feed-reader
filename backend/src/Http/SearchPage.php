<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListSort;
use App\Service\Search\EntrySearchResult;

/**
 * The `{entries, nextCursor, matchedWords}` shape a search response returns.
 * The cursor rule belongs to EntryPage and must exist exactly once; this adds
 * only what search has beyond a plain entry list.
 */
final readonly class SearchPage
{
    private function __construct()
    {
    }

    /** @return array{entries: list<array<string, mixed>>, nextCursor: string|null, matchedWords: list<string>} */
    public static function of(EntrySearchResult $result, int $limit): array
    {
        // withMatchCount(), not of(): matchCount is the read's own match count,
        // which for indexed search can exceed count($result->rows) once hydration
        // drops an id the caller may not see. Deciding "is there a next page" from
        // the row count would read a full page as a short one. Search always ranks
        // by publish instant, never view time, so its next cursor encodes
        // effectiveDate.
        $page = EntryPage::withMatchCount(
            $result->rows,
            $limit,
            $result->matchCount,
            EntryListSort::PublishedDate,
        );

        return [...$page, 'matchedWords' => $result->matchedWords];
    }
}
