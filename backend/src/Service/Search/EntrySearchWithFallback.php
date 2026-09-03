<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\EntrySearchQuery;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * The class services.yaml hands every caller of EntrySearchInterface: prefer the
 * engine, fall back to the database. This is what makes Meilisearch optional
 * rather than a hard dependency for #432 — delete this class and re-alias to
 * LikeEntrySearch, and the rest of the application does not notice.
 *
 * Named collaborators, not two EntrySearchInterface arguments: this class's whole
 * job is combining these two specific strategies one particular way, and the
 * container cannot tell two same-typed arguments apart — nor could a reader,
 * without the concrete names, tell which is primary and which the fallback.
 *
 * The two failure modes below look alike from the caller's side but are not the
 * same story for an operator, so they are handled differently:
 *
 * - No engine configured (SearchEngineCapability::isConfigured() false) is the
 *   Strato deployment — permanent and correct. Nothing is broken, so nothing is
 *   logged: a line per search is the noise that buries a real incident later.
 * - An engine that IS configured but does not answer
 *   (SearchEngineUnavailableException) is a degraded state worth attention:
 *   exactly one warning, carrying the exception for its stack trace.
 *
 * Any other exception propagates. Catching more broadly would hide a real bug in
 * the engine path behind a silently worse (LIKE) result — the hardest failure to
 * notice.
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

    public function search(EntrySearchQuery $query): EntrySearchResult
    {
        if (!$this->capability->isConfigured()) {
            return $this->database->search($query);
        }

        try {
            return $this->engine->search($query);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; falling back to database search.', [
                'exception' => $e,
            ]);

            return $this->database->search($query);
        }
    }
}
