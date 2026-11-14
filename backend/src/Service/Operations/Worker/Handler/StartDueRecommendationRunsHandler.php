<?php

declare(strict_types=1);

namespace App\Service\Operations\Worker\Handler;

use App\Service\Curation\Recommendation\Run\ForYouSweep;
use App\Service\Operations\Worker\Message\StartDueRecommendationRuns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Only starts due runs; advancing them stays AdvanceRecommendationRuns' job, so the two never share a message. */
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
