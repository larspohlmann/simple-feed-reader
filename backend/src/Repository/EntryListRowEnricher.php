<?php

declare(strict_types=1);

namespace App\Repository;

/** The two batch loads every entry list runs over its page: category labels, then the owner's saved-search pills. */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryLoader $categories,
        private SavedSearchMembershipLoader $savedSearches,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function enrich(array $rows, int $userId): array
    {
        return $this->savedSearches->loadInto($this->categories->loadInto($rows), $userId);
    }
}
