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
        return [...EntryPage::of($result->rows, $limit), 'matchedWords' => $result->matchedWords];
    }
}
