<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\Search\Exception\SearchEngineUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * What services.yaml hands every caller of SavedSearchUnreadMatchSource: prefer
 * the engine, fall back to the database. Same rule as EntrySearchWithFallback.
 * On an unavailable engine the partial engine walk is discarded and the whole
 * set is recomputed from the database, so mark-read never marks a mixed set.
 */
final readonly class SavedSearchUnreadMatchesWithFallback implements SavedSearchUnreadMatchSource
{
    public function __construct(
        private IndexedSavedSearchUnreadMatches $engine,
        private DatabaseSavedSearchUnreadMatches $database,
        private LoggerInterface $logger,
        private SearchEngineCapability $capability,
    ) {
    }

    public function unreadMatchIdsUpTo(int $userId, array $savedSearches, \DateTimeImmutable $until): array
    {
        if (!$this->capability->isConfigured()) {
            return $this->database->unreadMatchIdsUpTo($userId, $savedSearches, $until);
        }

        try {
            return $this->engine->unreadMatchIdsUpTo($userId, $savedSearches, $until);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; falling back to database saved-search mark-read.', [
                'exception' => $e,
            ]);

            return $this->database->unreadMatchIdsUpTo($userId, $savedSearches, $until);
        }
    }
}
