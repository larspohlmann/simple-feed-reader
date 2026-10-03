<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Worker\Message\AdvanceProfileRuns;
use App\Service\Worker\WorkerProfileRunSweep;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdvanceProfileRunsHandler
{
    public function __construct(private WorkerProfileRunSweep $sweep)
    {
    }

    public function __invoke(AdvanceProfileRuns $message): void
    {
        $this->sweep->sweep(RecommendationDriverKind::PersistentWorker);
    }
}
