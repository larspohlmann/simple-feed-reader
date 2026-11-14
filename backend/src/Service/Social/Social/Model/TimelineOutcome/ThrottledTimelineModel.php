<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model\TimelineOutcome;

final readonly class ThrottledTimelineModel implements TimelineOutcomeModelInterface
{
    public function __construct(public ?int $retryAfterSeconds)
    {
    }
}
