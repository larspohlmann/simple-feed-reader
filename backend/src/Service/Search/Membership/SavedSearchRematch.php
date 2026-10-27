<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Entity\Entry;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use App\Service\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface;
use App\Service\Search\Support\SavedSearchTerms;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Matches entries whose searchable text grew after the membership sweep passed them against every saved search. */
final readonly class SavedSearchRematch
{
    public function __construct(
        private SavedSearchRepository $searches,
        private SavedSearchMatcherInterface $matcher,
        private SavedSearchMembershipWriterInterface $memberships,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<Entry> $entries flushed entries; a failed match is logged, never thrown */
    public function rematch(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $searches = $this->searches->findAll();
        if ($searches === []) {
            return;
        }

        try {
            $this->memberships->insertMissing(
                $this->matcher->matchingIds(
                    array_map(SavedSearchTerms::termOf(...), $searches),
                    array_map(static fn (Entry $entry): int => $entry->requireId(), $entries),
                ),
                $this->clock->now(),
            );
        } catch (SearchEngineUnavailableException $exception) {
            $this->logger->warning('Search engine unavailable; saved searches are not re-matched.', [
                'exception' => $exception,
            ]);
        }
    }
}
