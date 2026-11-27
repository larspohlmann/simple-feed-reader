<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermRun;
use App\Enum\RunStatus;
use App\Repository\InterestTermRunRepository;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Curation\Ai\Support\ProviderHost;
use App\Service\Curation\Recommendation\Run\Support\RunLogRetention;
use App\Service\Curation\Recommendation\Stream\Model\DueInterestTermsModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** Opens the term run an extraction records on; a run still going for this very profile is carried on. */
final readonly class InterestTermRuns
{
    private const string SUPERSEDED = 'A newer profile replaced this run before it finished.';

    public function __construct(
        private InterestTermRunRepository $runs,
        private RecommendationRunLogRepository $logs,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private NaiveUtcClock $clock,
    ) {
    }

    /** The run as last saved, after a failure that may have closed the manager; null when it is no longer running. */
    public function findRunningAfterFailure(int $runId): ?InterestTermRun
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        } else {
            $this->registry->resetManager();
        }
        $saved = $this->entityManager->find(InterestTermRun::class, $runId);

        return RunStatus::Running === $saved?->getStatus() ? $saved : null;
    }

    public function openFor(DueInterestTermsModel $due): InterestTermRun
    {
        $latest = $this->runs->findLatestForUser($due->user);
        if (RunStatus::Running === $latest?->getStatus()) {
            if ($latest->isForProfile($due->profileGeneratedAt)) {
                return $latest;
            }
            $latest->fail(self::SUPERSEDED, $this->clock->now());
        }

        $run = new InterestTermRun(
            $due->user,
            $due->profileGeneratedAt,
            $this->clock->now(),
            ProviderHost::parseHost($due->connection),
            $due->connection->getModel(),
        );
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->logs->deleteForUserOutsideInterestTermRuns(
            $due->user,
            $this->runs->findNewestIdsForUser($due->user, RunLogRetention::RUNS),
        );

        return $run;
    }
}
