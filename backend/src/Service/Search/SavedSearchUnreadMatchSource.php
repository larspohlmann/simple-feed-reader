<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * The set of unread entry ids the combined saved-search mark-read flips: every
 * unread entry any saved search matches, no newer than $until. Behind an
 * interface so the engine path and the LIKE path answer the same question and
 * services.yaml can swap them (mirrors SavedSearchEntriesInterface, #973).
 */
interface SavedSearchUnreadMatchSource
{
    /**
     * @param list<SavedSearchTerm> $savedSearches
     *
     * @return list<int>
     */
    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array;
}
