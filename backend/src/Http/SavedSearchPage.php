<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListSort;
use App\Service\Search\SavedSearchEntriesResult;

/**
 * The `{entries, nextCursor, savedSearchIds}` shape the combined saved-search
 * list returns. The cursor rule belongs to EntryPage and must exist exactly
 * once; this adds only the badge map the combined list has beyond a plain list.
 */
final readonly class SavedSearchPage
{
    private function __construct()
    {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null, savedSearchIds: \stdClass}
     */
    public static function of(SavedSearchEntriesResult $result, int $limit): array
    {
        return [
            ...EntryPage::withMatchCount(
                $result->rows,
                $limit,
                $result->matchCount,
                EntryListSort::PublishedDate,
                $result->continuationRow,
            ),
            // Cast, not a bare array: an empty map must encode as `{}`, not `[]`.
            'savedSearchIds' => (object) $result->savedSearchIds,
        ];
    }
}
