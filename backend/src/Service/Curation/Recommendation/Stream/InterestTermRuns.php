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

/** Opens the term run an extraction records on; a run still going for this very profile is carried on. */
final readonly class InterestTermRuns
{
    public function __construct(
        private InterestTermRunRepository $runs,
        private RecommendationRunLogRepository $logs,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function openFor(DueInterestTermsModel $due): InterestTermRun
    {
        $latest = $this->runs->findLatestForUser($due->user);
        if (RunStatus::Running === $latest?->getStatus() && $latest->isForProfile($due->profileGeneratedAt)) {
            return $latest;
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
