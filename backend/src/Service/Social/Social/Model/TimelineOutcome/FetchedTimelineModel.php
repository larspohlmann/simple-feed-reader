<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model\TimelineOutcome;

use App\Service\Social\Social\Model\TimelinePageModel;

final readonly class FetchedTimelineModel implements TimelineOutcomeModelInterface
{
    public function __construct(public TimelinePageModel $page)
    {
    }
}
