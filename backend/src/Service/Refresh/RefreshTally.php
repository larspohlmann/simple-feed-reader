<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;

/** Running counts for one refresh pass. Mutable: it is a tally. */
final class RefreshTally
{
    public int $fetched = 0;
    public int $notModified = 0;
    public int $failed = 0;
    public int $processed = 0;
    public bool $aborted = false;

    /**
     * Feeds whose fetch actually started this pass, in the order their outcome
     * landed. Phase two (favicons) scopes itself to this list rather than the
     * full due-feed list, so a feed the budget deferred is not also given a
     * homepage fetch it never earned this pass.
     *
     * @var list<Feed>
     */
    public array $processedFeeds = [];

    public function record(FeedOutcome $outcome, Feed $feed): void
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
        $this->processedFeeds[] = $feed;

        match ($outcome) {
            FeedOutcome::Fetched => $this->fetched++,
            FeedOutcome::NotModified => $this->notModified++,
            FeedOutcome::Failed => $this->failed++,
        };
    }
}
