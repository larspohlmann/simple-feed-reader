<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;

/**
 * The unread matching entry ids behind each saved search's sidebar badge. The
 * client counts them, and drops one the moment the user reads it, so the badge
 * falls without another scan. Matched through the search's own term matching,
 * so the set is exactly what opening the search would list.
 */
final readonly class SavedSearchMatchIds
{
    public function __construct(private EntryListRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, list<int>> saved-search id => unread matching entry ids
     */
    public function forAll(array $savedSearches, int $userId): array
    {
        $idsBySearch = [];
        foreach ($savedSearches as $savedSearch) {
            $idsBySearch[(int) $savedSearch->getId()] = $this->forOne($savedSearch, $userId);
        }

        return $idsBySearch;
    }

    /**
     * @return list<int>
     */
    public function forOne(SavedSearch $savedSearch, int $userId): array
    {
        return $this->entries->unreadMatchIdsForUser(
            new EntrySearchQuery($userId, SavedSearchTerms::of($savedSearch)),
        );
    }
}
