<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListRow;
use App\Repository\EntryListSort;

/**
 * The `{entries, nextCursor, savedSearchIds}` shape the combined saved-search
 * list returns. The cursor rule belongs to EntryPage and must exist exactly
 * once; this adds only what the combined list has beyond a plain entry list.
 */
final readonly class SavedSearchPage
{
    private function __construct()
    {
    }

    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $savedSearchIds
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null, savedSearchIds: \stdClass}
     */
    public static function of(array $rows, int $limit, array $savedSearchIds): array
    {
        return [
            ...EntryPage::of($rows, $limit, EntryListSort::PublishedDate),
            // Cast, not a bare array: an empty map must still encode as `{}`,
            // not `[]` — a client decoding {entryId: searchId} cannot read a
            // JSON array.
            'savedSearchIds' => (object) $savedSearchIds,
        ];
    }
}
