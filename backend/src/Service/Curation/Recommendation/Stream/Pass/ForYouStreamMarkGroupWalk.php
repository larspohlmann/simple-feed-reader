<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Entity\ForYouStreamTuning;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\ForYouStreamChunkMatcher;
use App\Service\Curation\Recommendation\Stream\ForYouStreamMembershipWriter\ForYouStreamMembershipWriterInterface;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamMatchModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Pass\MarkGroupWalk\MarkGroupWalkInterface;
use Psr\Log\LoggerInterface;

/**
 * One For you stream walk over the accounts' term sets, the members being user ids.
 *
 * @implements MarkGroupWalkInterface<int, list<ForYouStreamMatchModel>>
 */
final readonly class ForYouStreamMarkGroupWalk implements MarkGroupWalkInterface
{
    public function __construct(
        private StreamSweepPass $pass,
        private InterestTermRepository $storedTerms,
        private InterestTermSetRepository $sets,
        private ForYouStreamChunkMatcher $matcher,
        private ForYouStreamMembershipWriterInterface $memberships,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function joinGroup(array $group): void
    {
        $tunings = $this->sets->findStreamTunings($group);
        $streaming = array_keys(
            array_filter($tunings, static fn (ForYouStreamTuning $tuning): bool => $tuning->isEnabled()),
        );
        $this->pass->join($group, $this->storedTerms->findTermsForUsers($streaming), $tunings);
    }

    public function matchChunk(array $chunk): array
    {
        return $this->matcher->matchChunk($this->pass, $chunk);
    }

    public function insertMatches(mixed $matches): int
    {
        return $this->memberships->insertMissing($matches, $this->clock->now());
    }

    public function advanceMarks(int $entryId): void
    {
        $this->sets->advanceMarks($this->pass->collectUserIds(), $entryId);
    }

    public function logChunkFailure(\Throwable $exception): void
    {
        $this->logger->error('The For you stream sweep failed on a chunk; it stops here and retries next run.', [
            'exception' => $exception,
        ]);
    }
}
