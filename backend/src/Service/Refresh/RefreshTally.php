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
     * Feeds phase two may fetch a homepage for, in the order their outcome
     * landed. Deliberately narrower than "processed": a feed the budget
     * deferred never started a fetch (so chasing its favicon here would undo
     * the budget just enforced), and a feed whose fetch FAILED has no new
     * content to show an icon beside — worse, retrying its homepage on every
     * sweep would add a permanent guarded HTTP round trip for a feed that may
     * never recover (a 404 feed whose site also 403s the crawler, say), which
     * is a real cost on a time-boxed FastCGI budget. "Counted in the report"
     * and "eligible for a favicon" are different sets on purpose; give them
     * separate names so a future change can't conflate them again.
     *
     * @var list<Feed>
     */
    public array $faviconEligibleFeeds = [];

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

        match ($outcome) {
            FeedOutcome::Fetched => $this->fetched++,
            FeedOutcome::NotModified => $this->notModified++,
            FeedOutcome::Failed => $this->failed++,
        };

        if (FeedOutcome::Failed !== $outcome) {
            $this->faviconEligibleFeeds[] = $feed;
        }
    }
}
