<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * What services.yaml hands every caller of SavedSearchBadgeSource: prefer the
 * engine, fall back to the database. Same rule as SavedSearchEntriesWithFallback
 * and SavedSearchUnreadMatchesWithFallback. On an unavailable engine the
 * partial engine walk is discarded and the whole set is recomputed from the
 * database, so a badge never reports a mixed engine/database count.
 */
final readonly class SavedSearchBadgesWithFallback implements SavedSearchBadgeSource
{
    public function __construct(
        private IndexedSavedSearchBadges $engine,
        private DatabaseSavedSearchBadges $database,
        private LoggerInterface $logger,
        private SearchEngineCapability $capability,
    ) {
    }

    public function unreadMatchIdsBySavedSearch(int $userId, array $searches): array
    {
        if (!$this->capability->isConfigured()) {
            return $this->database->unreadMatchIdsBySavedSearch($userId, $searches);
        }

        try {
            return $this->engine->unreadMatchIdsBySavedSearch($userId, $searches);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; falling back to database saved-search badges.', [
                'exception' => $e,
            ]);

            return $this->database->unreadMatchIdsBySavedSearch($userId, $searches);
        }
    }
}
