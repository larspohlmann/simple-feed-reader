<?php

declare(strict_types=1);

namespace App\Service\Ai\RateLimitedOutcome;

/** One provider call's result as the rate-limit loop sees it: whether the provider asked it to wait, and how long. */
interface RateLimitedOutcomeInterface
{
    public function isRetryable(): bool;

    public function retryAfterSeconds(): ?int;
}
