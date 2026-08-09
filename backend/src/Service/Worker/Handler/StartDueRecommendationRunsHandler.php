<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Recommendation\ForYouSweep;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts the due runs every five minutes (#333). Advancing them to completion
 * stays the ten-second AdvanceRecommendationRuns sweep's job, so this handler
 * only starts — the two concerns never merge into one message.
 */
#[AsMessageHandler]
final readonly class StartDueRecommendationRunsHandler
{
    public function __construct(
        private ForYouSweep $sweep,
    ) {
    }

    public function __invoke(StartDueRecommendationRuns $message): void
    {
        $this->sweep->startDueRuns();
    }
}
