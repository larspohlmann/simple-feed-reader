<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

/** The hops one followed request may take and the seconds it may still spend across all of them. */
final class RedirectBudget
{
    public function __construct(
        public readonly int $maxRedirects,
        private float $remainingSeconds = \INF,
    ) {
    }

    public function remainingSeconds(): float
    {
        return $this->remainingSeconds;
    }

    public function isSpent(): bool
    {
        return $this->remainingSeconds <= 0.0;
    }

    public function spend(float $seconds): void
    {
        $this->remainingSeconds -= $seconds;
    }
}
