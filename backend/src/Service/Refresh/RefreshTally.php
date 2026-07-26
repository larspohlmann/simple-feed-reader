<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/** Running counts for one refresh pass. Mutable: it is a tally. */
final class RefreshTally
{
    public int $fetched = 0;
    public int $notModified = 0;
    public int $failed = 0;
    public int $processed = 0;
    public bool $aborted = false;

    public function record(FeedOutcome $outcome): void
    {
        // An aborted feed is deliberately NOT counted as processed: its flush
        // rolled back, so it is still due and must appear in `remaining`.
        // Counting it here would under-report by one and let a polling client
        // believe a feed was handled when nothing was persisted.
        if (FeedOutcome::Aborted === $outcome) {
            $this->failed++;
            $this->aborted = true;

            return;
        }

        $this->processed++;

        match ($outcome) {
            FeedOutcome::Fetched => $this->fetched++,
            FeedOutcome::NotModified => $this->notModified++,
            FeedOutcome::Failed => $this->failed++,
        };
    }
}
