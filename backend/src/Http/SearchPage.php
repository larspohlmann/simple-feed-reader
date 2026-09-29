<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListSort;
use App\Service\Search\Model\EntrySearchResultModel;

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
    public static function of(EntrySearchResultModel $result, int $limit): array
    {
        $page = EntryPage::withMatchCount(
            $result->rows,
            $limit,
            $result->matchCount,
            EntryListSort::PublishedDate,
            $result->continuationRow,
        );

        return [...$page, 'matchedWords' => $result->matchedWords];
    }
}
