<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Pass;

use App\Entity\YourStreamTuning;
use App\Repository\YourStreamStateRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Curation\YourStream\Model\YourStreamMatchModel;
use App\Service\Curation\YourStream\YourStreamChunkMatcher;
use App\Service\Curation\YourStream\YourStreamMembershipWriter\YourStreamMembershipWriterInterface;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Pass\MarkGroupWalk\MarkGroupWalkInterface;
use Psr\Log\LoggerInterface;

/**
 * One Your stream walk over the accounts' state rows, the members being user ids.
 *
 * @implements MarkGroupWalkInterface<int, list<YourStreamMatchModel>>
 */
final readonly class YourStreamMarkGroupWalk implements MarkGroupWalkInterface
{
    public function __construct(
        private StreamSweepPass $pass,
        private YourStreamWordRepository $words,
        private YourStreamStateRepository $states,
        private YourStreamChunkMatcher $matcher,
        private YourStreamMembershipWriterInterface $memberships,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function joinGroup(array $group): void
    {
        $tunings = $this->states->findStreamTunings($group);
        $streaming = array_keys(
            array_filter($tunings, static fn (YourStreamTuning $tuning): bool => $tuning->isEnabled()),
        );
        $this->pass->join($group, $this->words->findStrengthsForUsers($streaming), $tunings);
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
        $this->states->advanceMarks($this->pass->collectUserIds(), $entryId);
    }

    public function logChunkFailure(\Throwable $exception): void
    {
        $this->logger->error('Your stream sweep failed on a chunk; it stops here and retries next run.', [
            'exception' => $exception,
        ]);
    }
}
