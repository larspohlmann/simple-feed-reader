<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;

/**
 * The live unread-match count behind each saved search's sidebar badge. Counts
 * through the search's own term matching, so the badge tracks exactly what
 * opening the search would list.
 */
final readonly class SavedSearchMatchCounter
{
    public function __construct(private EntryListRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, int> saved-search id => unread match count
     */
    public function countsFor(array $savedSearches, int $userId): array
    {
        $counts = [];
        foreach ($savedSearches as $savedSearch) {
            $counts[(int) $savedSearch->getId()] = $this->countFor($savedSearch, $userId);
        }

        return $counts;
    }

    public function countFor(SavedSearch $savedSearch, int $userId): int
    {
        $terms = SearchTerms::fromTermAndMode($savedSearch->getTerm(), $savedSearch->isWholeWord());

        return $this->entries->countUnreadMatchesForUser(new EntrySearchQuery($userId, $terms));
    }
}
