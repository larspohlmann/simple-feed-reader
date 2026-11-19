<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListSort;
use App\Service\Library\Search\Model\SavedSearchEntriesResultModel;

/**
 * The `{entries, nextCursor}` shape the combined saved-search list returns.
 * The cursor rule belongs to EntryPage and must exist exactly once.
 */
final readonly class SavedSearchPage
{
    public function __construct(
        private EntryPage $entryPage,
    ) {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function from(SavedSearchEntriesResultModel $result, int $limit): array
    {
        return $this->entryPage->from($result->rows, $limit, EntryListSort::PublishedDate);
    }
}
