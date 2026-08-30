<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * One feed's outcome plus how many entries its fetch created. The count feeds
 * the run-wide total that decides whether the refresh moves the change marker
 * (#720); every non-fetch outcome creates nothing.
 */
final readonly class FeedRefreshResult
{
    private function __construct(
        public FeedOutcome $outcome,
        public int $entriesCreated,
    ) {
    }

    public static function of(FeedOutcome $outcome): self
    {
        return new self($outcome, 0);
    }

    public static function fetched(int $entriesCreated): self
    {
        return new self(FeedOutcome::Fetched, $entriesCreated);
    }
}
