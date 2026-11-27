<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermRefreshResult;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermSweepReportModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Psr\Log\LoggerInterface;

/** Refreshes the interest terms of every account whose stored profile is newer than them. */
final readonly class InterestTermSweep
{
    public function __construct(
        private DueInterestTermFinder $finder,
        private InterestTermRefresh $refresh,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function refreshDue(): InterestTermSweepReportModel
    {
        return $this->refreshDueUntil(null);
    }

    /** Starts an account only before the deadline. */
    public function refreshDueBefore(\DateTimeImmutable $deadline): InterestTermSweepReportModel
    {
        return $this->refreshDueUntil($deadline);
    }

    private function refreshDueUntil(?\DateTimeImmutable $deadline): InterestTermSweepReportModel
    {
        $due = $this->finder->findDue();
        $stored = 0;
        $failed = 0;
        foreach ($due as $accountDue) {
            if (null !== $deadline && $this->clock->now() >= $deadline) {
                break;
            }
            $result = $this->refreshOne($accountDue);
            $stored += InterestTermRefreshResult::Stored === $result ? 1 : 0;
            $failed += InterestTermRefreshResult::Failed === $result ? 1 : 0;
        }

        return new InterestTermSweepReportModel(\count($due), $stored, $failed, 0);
    }

    /** One account's failure must not cost the others their refresh. */
    private function refreshOne(DueInterestTermsModel $due): ?InterestTermRefreshResult
    {
        try {
            return $this->refresh->refresh($due);
        } catch (\Throwable $exception) {
            $this->logger->error('Interest-term sweep: refreshing an account failed.', [
                'userId' => $due->user->getId(),
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
