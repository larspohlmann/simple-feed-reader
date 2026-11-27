<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;

/** One sweep run: the accounts walking now with their terms, the one stemming they share, and the counters. */
final class StreamSweepPass
{
    private int $usersSwept = 0;
    private int $entriesScanned = 0;
    private int $matchesInserted = 0;

    /** @var array<int, InterestTermsModel> */
    private array $termsByUserId = [];

    /** @var list<int> */
    private array $userIds = [];

    public function __construct(
        public readonly EntryStemming $stemming,
        public readonly \DateTimeImmutable $publishedSince,
        public readonly \DateTimeImmutable $deadline,
    ) {
    }

    /** @param array<int, InterestTermsModel> $termsByUserId */
    public function join(array $termsByUserId): void
    {
        $this->termsByUserId = $this->termsByUserId + $termsByUserId;
        $this->usersSwept += \count($termsByUserId);
        $this->userIds = array_keys($this->termsByUserId);
    }

    public function recordChunk(int $scanned, int $inserted): void
    {
        $this->entriesScanned += $scanned;
        $this->matchesInserted += $inserted;
    }

    /** @return list<int> */
    public function collectUserIds(): array
    {
        return $this->userIds;
    }

    public function requireTerms(int $userId): InterestTermsModel
    {
        return $this->termsByUserId[$userId]
            ?? throw new \LogicException(\sprintf('User %d is not walking in this pass.', $userId));
    }

    public function caughtUp(): ForYouStreamSweepReportModel
    {
        return $this->stoppedShort()->withCaughtUp();
    }

    public function stoppedShort(): ForYouStreamSweepReportModel
    {
        return new ForYouStreamSweepReportModel(
            $this->usersSwept,
            $this->entriesScanned,
            $this->matchesInserted,
            false,
        );
    }
}
