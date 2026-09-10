<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\SavedSearchEntryQuery;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * What services.yaml hands every caller of SavedSearchEntriesInterface: prefer
 * the engine, fall back to the database. Same rule as EntrySearchWithFallback —
 * an unconfigured engine is the silent Strato case, an unavailable engine is one
 * warning, any other exception propagates rather than hide behind a worse result.
 */
final readonly class SavedSearchEntriesWithFallback implements SavedSearchEntriesInterface
{
    public function __construct(
        private IndexedSavedSearchEntries $engine,
        private DatabaseSavedSearchEntries $database,
        private LoggerInterface $logger,
        private SearchEngineCapability $capability,
    ) {
    }

    public function list(SavedSearchEntryQuery $query): SavedSearchEntriesResult
    {
        if (!$this->capability->isConfigured()) {
            return $this->database->list($query);
        }

        try {
            return $this->engine->list($query);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; falling back to database saved-search list.', [
                'exception' => $e,
            ]);

            return $this->database->list($query);
        }
    }
}
