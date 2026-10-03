<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Support;

/** Whether a schedule's interval has passed since its anchor; no anchor means nothing ran yet, so it has. */
final readonly class IntervalElapsed
{
    public static function since(?\DateTimeImmutable $anchor, int $hours, \DateTimeImmutable $now): bool
    {
        return null === $anchor || $now >= $anchor->modify(\sprintf('+%d hours', $hours));
    }

    private function __construct()
    {
    }
}
