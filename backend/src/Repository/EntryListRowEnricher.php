<?php

declare(strict_types=1);

namespace App\Repository;

/** The batch loads every entry list runs over its page: category labels, link cards, then the owner's saved-search pills. */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryLoader $categories,
        private LinkCardLoader $linkCards,
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
        return $this->savedSearches->loadInto($this->linkCards->loadInto($this->categories->loadInto($rows)), $userId);
    }
}
