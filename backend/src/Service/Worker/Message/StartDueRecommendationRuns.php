<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Start a fresh recommendation run for every account whose chosen cadence has
 * elapsed (#333). Property-less like its siblings: a copy stuck in the failure
 * transport can never go stale, because the work is "whatever is due now".
 */
final readonly class StartDueRecommendationRuns
{
}
