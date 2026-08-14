<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerRunSweep;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The worker side of the driver-agnostic tick (#311): every ten seconds this
 * runs one worker-regime sweep over the active runs. The sweep itself lives
 * in WorkerRunSweep (#371) because the on-demand drain command is the same
 * regime -- this handler only binds it to the messenger firing.
 *
 * This is the one place the persistent worker's own liveness key is claimed:
 * only a process that keeps firing this handler is the worker the settings
 * card means when it says an install needs no cron.
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
