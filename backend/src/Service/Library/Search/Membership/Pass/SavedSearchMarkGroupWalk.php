<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership\Pass;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchRepository;
use App\Service\Library\Search\Exception\SearchEngineUnavailableException;
use App\Service\Library\Search\Membership\MarkGroupWalk\MarkGroupWalkInterface;
use App\Service\Library\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use App\Service\Library\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface;
use App\Service\Library\Search\Model\SavedSearchTermModel;
use App\Service\Library\Search\Support\SavedSearchTerms;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * One saved-search membership walk: the searches walking now and their terms.
 *
 * @implements MarkGroupWalkInterface<SavedSearch, array<int, list<int>>>
 */
final class SavedSearchMarkGroupWalk implements MarkGroupWalkInterface
{
    /** @var list<int> */
    private array $savedSearchIds = [];

    /** @var list<SavedSearchTermModel> */
    private array $terms = [];

    public function __construct(
        private readonly SavedSearchRepository $searches,
        private readonly SavedSearchMatcherInterface $matcher,
        private readonly SavedSearchMembershipWriterInterface $memberships,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function joinGroup(array $group): void
    {
        foreach ($group as $search) {
            $this->savedSearchIds[] = $search->requireId();
            $this->terms[] = SavedSearchTerms::buildTermModel($search);
        }
    }

    public function matchChunk(array $chunk): array
    {
        return $this->matcher->matchingIds($this->terms, $chunk);
    }

    public function insertMatches(mixed $matches): int
    {
        return $this->memberships->insertMissing($matches, $this->clock->now());
    }

    public function advanceMarks(int $entryId): void
    {
        $this->searches->advanceMarks($this->savedSearchIds, $entryId);
    }

    public function logChunkFailure(\Throwable $exception): void
    {
        if ($exception instanceof SearchEngineUnavailableException) {
            $this->logger->warning('Search engine unavailable; the membership sweep stops here and retries next run.', [
                'exception' => $exception,
            ]);

            return;
        }
        $this->logger->error('The membership sweep failed on a chunk; it stops here and retries next run.', [
            'exception' => $exception,
        ]);
    }
}
