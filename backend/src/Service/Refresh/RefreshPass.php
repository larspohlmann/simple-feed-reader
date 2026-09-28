<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Service\Fetch\FetchTicket;
use Symfony\Component\Clock\ClockInterface;

/** One run's due feeds, its budget queue and its tally, and the report they add up to. */
final readonly class RefreshPass
{
    public RefreshTally $tally;

    private BudgetedFeedQueue $queue;

    /** @var array<int, Feed> */
    private array $feedsById;

    /**
     * @param list<Feed> $feeds
     */
    public function __construct(
        private array $feeds,
        ClockInterface $clock,
        int $deadline,
    ) {
        $this->queue = new BudgetedFeedQueue($feeds, $clock, $deadline);
        $feedsById = [];
        foreach ($feeds as $feed) {
            $feedsById[$feed->requireId()] = $feed;
        }
        $this->feedsById = $feedsById;
        $this->tally = new RefreshTally();
    }

    /** @return \Generator<int, FetchTicket, mixed, void> */
    public function tickets(): \Generator
    {
        return $this->queue->tickets();
    }

    public function feed(int|string $feedId): Feed
    {
        return $this->feedsById[$feedId];
    }

    /** @return list<int> */
    public function startedFeedIds(): array
    {
        return $this->queue->startedFeedIds();
    }

    /** Persistence failed while outcomes were stored: every feed not yet processed, the failing one too, is due. */
    public function abortedDuringOutcomes(): RefreshReport
    {
        return $this->aborted(\count($this->feeds) - $this->tally->processed());
    }

    public function abortedAfterOutcomes(): RefreshReport
    {
        return $this->aborted($this->queue->skippedCount());
    }

    public function finished(int $remaining, int $pruned): RefreshReport
    {
        return RefreshReport::finished(
            \count($this->feeds),
            $this->tally->fetched(),
            $this->tally->notModified(),
            $this->tally->failed(),
            $this->tally->throttled(),
            $this->queue->skippedCount(),
            $remaining,
            $pruned,
        );
    }

    private function aborted(int $remaining): RefreshReport
    {
        return RefreshReport::aborted(
            \count($this->feeds),
            $this->tally->fetched(),
            $this->tally->notModified(),
            $this->tally->failed(),
            $this->tally->throttled(),
            $remaining,
        );
    }
}
