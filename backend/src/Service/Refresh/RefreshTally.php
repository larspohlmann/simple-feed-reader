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
    public int $throttled = 0;
    public int $processed = 0;
    public int $entriesCreated = 0;
    public bool $aborted = false;

    /**
     * Feeds phase two may fetch a homepage for, in outcome order. Narrower than
     * "processed": a budget-deferred feed never fetched, and a FAILED feed has
     * nothing new to show an icon beside — retrying its homepage every sweep would
     * add a permanent round trip for a site that may never recover (a 404 feed
     * behind a 403 crawler block, say). Keep "counted" and "favicon-eligible" as
     * separate sets so a future change can't conflate them.
     *
     * @var list<Feed>
     */
    public array $faviconEligibleFeeds = [];

    public function record(FeedRefreshResult $result, Feed $feed): void
    {
        $outcome = $result->outcome;
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
        $this->entriesCreated += $result->entriesCreated;

        match ($outcome) {
            FeedOutcome::Fetched => $this->fetched++,
            FeedOutcome::NotModified => $this->notModified++,
            FeedOutcome::Failed => $this->failed++,
            FeedOutcome::Throttled => $this->throttled++,
        };

        if ($outcome->broughtContent()) {
            $this->faviconEligibleFeeds[] = $feed;
        }
    }
}
