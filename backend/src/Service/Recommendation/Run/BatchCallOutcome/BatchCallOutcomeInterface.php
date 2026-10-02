<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\BatchCallOutcome;

use App\Service\Ai\RateLimitedOutcome\RateLimitedOutcomeInterface;

/** One batch call's result in a wave, as the atomic-wave rule reads it. */
interface BatchCallOutcomeInterface extends RateLimitedOutcomeInterface
{
    /** Whether the endpoint failed, the only failure that aborts the call's siblings. */
    public function isFailure(): bool;

    /** Whether anything went wrong at all, an endpoint failure or a spoiled reply. */
    public function hasCause(): bool;

    public function cause(): \Throwable;
}
