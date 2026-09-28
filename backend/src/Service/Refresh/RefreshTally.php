<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;

/** Running counts for one refresh pass; only record() moves them. */
final class RefreshTally
{
    private int $fetched = 0;
    private int $notModified = 0;
    private int $failed = 0;
    private int $throttled = 0;
    private int $processed = 0;
    private int $entriesCreated = 0;
    private bool $aborted = false;

    /** @var list<Feed> not "processed": a failed or throttled feed has nothing new to show an icon beside */
    private array $faviconEligibleFeeds = [];

    public function record(FeedRefreshResult $result, Feed $feed): void
    {
        $outcome = $result->outcome;
        // An aborted feed's flush rolled back, so it is still due: it counts as failed, never as processed.
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

    public function fetched(): int
    {
        return $this->fetched;
    }

    public function notModified(): int
    {
        return $this->notModified;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    public function throttled(): int
    {
        return $this->throttled;
    }

    public function processed(): int
    {
        return $this->processed;
    }

    public function entriesCreated(): int
    {
        return $this->entriesCreated;
    }

    public function isAborted(): bool
    {
        return $this->aborted;
    }

    /** @return list<Feed> */
    public function faviconEligibleFeeds(): array
    {
        return $this->faviconEligibleFeeds;
    }
}
