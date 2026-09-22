<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListSort;
use App\Service\Search\SavedSearchEntriesResult;

/**
 * The `{entries, nextCursor}` shape the combined saved-search list returns.
 * The cursor rule belongs to EntryPage and must exist exactly once.
 */
final readonly class SavedSearchPage
{
    private function __construct()
    {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function of(SavedSearchEntriesResult $result, int $limit): array
    {
        return EntryPage::of($result->rows, $limit, EntryListSort::PublishedDate);
    }
}
