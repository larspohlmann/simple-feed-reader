<?php

declare(strict_types=1);

namespace App\Service\Social\Model\TimelineOutcome;

final readonly class FailedTimelineModel implements TimelineOutcomeModelInterface
{
    public function __construct(public \RuntimeException $failure)
    {
    }
}
