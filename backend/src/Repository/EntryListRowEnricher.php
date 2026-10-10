<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The batch loads every entry list runs over its page: category labels, link cards, whether each post is still being
 * completed, then the owner's saved-search pills.
 */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryLoader $categories,
        private LinkCardLoader $linkCards,
        private EntryPendingLoader $pending,
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
        $rows = $this->pending->loadInto($this->linkCards->loadInto($this->categories->loadInto($rows)));

        return $this->savedSearches->loadInto($rows, $userId);
    }
}
