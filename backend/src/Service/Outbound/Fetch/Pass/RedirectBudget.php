<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

/** The redirects one fetch may still follow and the seconds it may still spend, across every hop it takes. */
final class RedirectBudget
{
    private int $redirectsTaken = 0;

    public function __construct(
        public readonly int $maxRedirects,
        private float $remainingSeconds = \INF,
    ) {
    }

    public function remainingSeconds(): float
    {
        return $this->remainingSeconds;
    }

    public function isOutOfTime(): bool
    {
        return $this->remainingSeconds <= 0.0;
    }

    public function spendSeconds(float $seconds): void
    {
        $this->remainingSeconds -= $seconds;
    }

    /** Spends one redirect; false, spending nothing, when none is left. */
    public function takeRedirect(): bool
    {
        if ($this->redirectsTaken >= $this->maxRedirects) {
            return false;
        }
        $this->redirectsTaken++;

        return true;
    }

    public function redirectsTaken(): int
    {
        return $this->redirectsTaken;
    }
}
