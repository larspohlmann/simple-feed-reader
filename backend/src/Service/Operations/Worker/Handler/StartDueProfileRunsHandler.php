<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Worker\Message\StartDueProfileRuns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Only starts due profile runs; WorkerRunSweep ticks them. */
#[AsMessageHandler]
final readonly class StartDueProfileRunsHandler
{
    public function __construct(private ProfileRunSweep $profileRuns)
    {
    }

    public function __invoke(StartDueProfileRuns $message): void
    {
        $this->profileRuns->startDueRuns();
    }
}
