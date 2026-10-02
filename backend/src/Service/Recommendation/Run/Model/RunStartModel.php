<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

/** When a run began and whether its first batch call has; the status payload and the ETA read both. */
final readonly class RunStartModel
{
    public function __construct(
        public ?\DateTimeImmutable $startedAt = null,
        public bool $firstBatchStarted = false,
    ) {
    }

    /** Null before the run has a start (the none and busy reports). */
    public function elapsedSecondsAt(\DateTimeImmutable $now): ?int
    {
        if (null === $this->startedAt) {
            return null;
        }

        return max(0, $now->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
