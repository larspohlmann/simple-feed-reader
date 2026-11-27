<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermSet;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermRefreshResult;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermSweepReportModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Refreshes the interest terms of every account whose stored profile is newer than them. */
final readonly class InterestTermSweep
{
    public const int UNHURRIED_BUDGET_SECONDS = 3600;

    public function __construct(
        private DueInterestTermFinder $finder,
        private InterestTermRefresh $refresh,
        private InterestTermSetRepository $termSets,
        private InterestTermRarity $rarity,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function sweep(SweepBudgetModel $budget): InterestTermSweepReportModel
    {
        $deadline = $budget->deadlineFrom($this->clock->now());
        $due = $this->finder->findDue();
        $stored = 0;
        $failed = 0;
        foreach ($due as $accountDue) {
            if ($this->clock->now() >= $deadline) {
                break;
            }
            $result = $this->refreshOne($accountDue);
            if (null === $result && !$this->entityManager->isOpen()) {
                return new InterestTermSweepReportModel(\count($due), $stored, $failed, 0);
            }
            $stored += InterestTermRefreshResult::Stored === $result ? 1 : 0;
            $failed += InterestTermRefreshResult::Failed === $result ? 1 : 0;
        }

        return new InterestTermSweepReportModel(
            \count($due),
            $stored,
            $failed,
            $this->refreshStaleRarities($deadline),
        );
    }

    /** The first stale set always finishes, so a walk longer than a tick still makes progress. */
    private function refreshStaleRarities(\DateTimeImmutable $deadline): int
    {
        $now = $this->clock->now();
        $refreshed = 0;
        $cutoff = $now->modify(\sprintf('-%d hours', InterestTermRarity::REFRESH_HOURS));
        foreach ($this->termSets->findWithStaleRarities($cutoff, $now) as $index => $set) {
            $setDeadline = 0 === $index ? null : $deadline;
            if (null !== $setDeadline && $this->clock->now() >= $setDeadline) {
                break;
            }
            $refreshed += $this->refreshSetRarities($set, $setDeadline) ? 1 : 0;
        }

        return $refreshed;
    }

    /** One account's failure must not cost the others their refresh. */
    private function refreshSetRarities(InterestTermSet $set, ?\DateTimeImmutable $deadline): bool
    {
        try {
            return $this->rarity->refreshRarities($set, $deadline);
        } catch (\Throwable $exception) {
            $this->logger->error('Interest-term sweep: refreshing an account\'s rarities failed.', [
                'userId' => $set->getUser()->getId(),
                'exception' => $exception,
            ]);

            return false;
        }
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
