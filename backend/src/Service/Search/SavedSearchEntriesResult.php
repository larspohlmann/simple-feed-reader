<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntryListRow;

/**
 * What one combined saved-search read answers with: the page of rows and, per
 * shown entry, the first saved search (in sidebar order) it belongs to — the
 * card's badge.
 */
final readonly class SavedSearchEntriesResult
{
    /**
     * @param list<EntryListRow> $rows
     * @param array<int, int>    $savedSearchIds entryId => first matching saved search id
     */
    public function __construct(
        public array $rows,
        public array $savedSearchIds,
    ) {
    }

    /** @param list<EntryListRow> $rows */
    public function withRows(array $rows): self
    {
        return new self($rows, $this->savedSearchIds);
    }
}
