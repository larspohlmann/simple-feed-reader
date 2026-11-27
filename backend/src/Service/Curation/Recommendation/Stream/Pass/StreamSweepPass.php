<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;

/** One sweep run: the accounts walking now with their terms, the one stemming they share, and the counters. */
final class StreamSweepPass
{
    public int $entriesScanned = 0;
    public int $matchesInserted = 0;

    private int $usersSwept = 0;

    /** @var array<int, InterestTermsModel> */
    private array $termsByUserId = [];

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
    }

    /** @return list<int> */
    public function collectUserIds(): array
    {
        return array_keys($this->termsByUserId);
    }

    public function requireTerms(int $userId): InterestTermsModel
    {
        return $this->termsByUserId[$userId]
            ?? throw new \LogicException(\sprintf('User %d is not walking in this pass.', $userId));
    }

    public function caughtUp(): ForYouStreamSweepReportModel
    {
        return $this->report(true);
    }

    public function stoppedShort(): ForYouStreamSweepReportModel
    {
        return $this->report(false);
    }

    private function report(bool $caughtUp): ForYouStreamSweepReportModel
    {
        return new ForYouStreamSweepReportModel(
            $this->usersSwept,
            $this->entriesScanned,
            $this->matchesInserted,
            $caughtUp,
        );
    }
}
