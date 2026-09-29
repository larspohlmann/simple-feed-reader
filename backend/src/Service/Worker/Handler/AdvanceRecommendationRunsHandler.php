<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\WorkerRunSweep;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one WorkerRunSweep per ten-second firing. The only place the persistent worker's liveness key is claimed: the
 * settings card reads that key to tell whether an install still needs a cron.
 */
#[AsMessageHandler]
final readonly class AdvanceRecommendationRunsHandler
{
    public function __construct(private WorkerRunSweep $sweep)
    {
    }

    public function __invoke(AdvanceRecommendationRuns $message): void
    {
        $this->sweep->sweep(RecommendationDriverKind::PersistentWorker);
    }
}
