<?php

declare(strict_types=1);

namespace App\Service\Library\Search\EntrySearch;

use App\Repository\EntrySearchQuery;
use App\Service\Library\Search\Exception\SearchEngineUnavailableException;
use App\Service\Library\Search\Model\EntrySearchResultModel;
use App\Service\Library\Search\SearchEngineCapability;
use Psr\Log\LoggerInterface;

/**
 * EntrySearchInterface's alias: the engine first, the database as fallback. No engine configured is a permanent
 * setup and logs nothing; a configured engine throwing SearchEngineUnavailableException logs one warning. Any other
 * exception propagates rather than hide a bug behind a silently worse LIKE result.
 */
final readonly class EntrySearchWithFallback implements EntrySearchInterface
{
    public function __construct(
        private IndexedEntrySearch $engine,
        private LikeEntrySearch $database,
        private LoggerInterface $logger,
        private SearchEngineCapability $capability,
    ) {
    }

    public function search(EntrySearchQuery $query): EntrySearchResultModel
    {
        if (!$this->capability->isConfigured()) {
            return $this->database->search($query);
        }

        try {
            return $this->engine->search($query);
        } catch (SearchEngineUnavailableException $exception) {
            $this->logger->warning('Search engine unavailable; falling back to database search.', [
                'exception' => $exception,
            ]);

            return $this->database->search($query);
        }
    }
}
