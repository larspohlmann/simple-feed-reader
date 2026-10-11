<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

/** The redirects one fetch may still follow and the seconds it may still spend, across every hop it takes. */
final class RedirectBudget
{
    private int $redirectsLeft;

    public function __construct(
        public readonly int $maxRedirects,
        private float $remainingSeconds = \INF,
    ) {
        $this->redirectsLeft = $maxRedirects;
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

    public function hasRedirectsLeft(): bool
    {
        return $this->redirectsLeft > 0;
    }

    public function spendRedirect(): void
    {
        $this->redirectsLeft--;
    }

    public function redirectsTaken(): int
    {
        return $this->maxRedirects - $this->redirectsLeft;
    }
}
