<?php

declare(strict_types=1);

namespace App\Http;

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
        // withMatchCount(), not of(): $result->matchCount is the read's own
        // match count, which for the indexed search can be higher than
        // count($result->rows) once hydration has dropped an id the caller
        // may not see. Deciding "is there a next page" from the row count
        // would then read a full page of matches as a short one.
        $page = EntryPage::withMatchCount($result->rows, $limit, $result->matchCount);

        return [...$page, 'matchedWords' => $result->matchedWords];
    }
}
