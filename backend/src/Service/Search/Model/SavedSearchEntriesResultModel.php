<?php

declare(strict_types=1);

namespace App\Service\Search\Model;

use App\Repository\EntryListRow;

/**
 * What one combined saved-search read answers with: the page of rows.
 */
final readonly class SavedSearchEntriesResultModel
{
    /** @param list<EntryListRow> $rows */
    public function __construct(
        public array $rows,
    ) {
    }

    /** @param list<EntryListRow> $rows */
    public function withRows(array $rows): self
    {
        return new self($rows);
    }
}
