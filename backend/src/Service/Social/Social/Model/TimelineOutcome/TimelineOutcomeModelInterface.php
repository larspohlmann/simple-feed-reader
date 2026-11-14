<?php

declare(strict_types=1);

namespace App\Service\Social\Model\TimelineOutcome;

/**
 * What one private feed's read came to, for the refresh to store.
 *
 * @phpstan-sealed FetchedTimelineModel|ThrottledTimelineModel|FailedTimelineModel
 *     |AccountThrottledTimelineModel|AccountPausedTimelineModel
 */
interface TimelineOutcomeModelInterface
{
}
