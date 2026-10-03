<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\WorkerPresence;
use Doctrine\ORM\EntityManagerInterface;

/** One worker-regime pass over every active profile run, for the worker's firing and the drain command. */
final readonly class WorkerProfileRunSweep
{
    public function __construct(
        private ProfileRunSweep $profileRuns,
        private WorkerPresence $presence,
        private SweepStreamHeartbeat $heartbeat,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function sweep(RecommendationDriverKind $kind): int
    {
        $this->heartbeat->sweepStarted($kind);

        try {
            $this->presence->mark($kind);

            return $this->profileRuns->advanceEveryActiveRun(TickDriver::Worker);
        } finally {
            $this->entityManager->clear();
            $this->heartbeat->sweepEnded();
        }
    }
}
