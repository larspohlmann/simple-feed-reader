<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * The unread matching entry ids behind every saved-search sidebar badge.
 * Behind an interface so the engine path and the database path answer the
 * same question and services.yaml can swap them (mirrors
 * SavedSearchEntriesInterface, #973, and SavedSearchUnreadMatchSource).
 */
interface SavedSearchBadgeSource
{
    /**
     * @param list<SavedSearchTerm> $searches
     *
     * @return array<int, list<int>> saved-search id => unread matching entry ids
     */
    public function unreadMatchIdsBySavedSearch(int $userId, array $searches): array;
}
