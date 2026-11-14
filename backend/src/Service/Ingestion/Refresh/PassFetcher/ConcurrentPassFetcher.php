<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\PassFetcher;

use App\Entity\SocialFeed;
use App\Service\Outbound\Fetch\ConcurrentRequestEngine;
use App\Service\Outbound\Fetch\Support\CapacityGuard;
use App\Service\Ingestion\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Ingestion\Refresh\Pass\PassLane\FeedLane;
use App\Service\Ingestion\Refresh\Pass\PassLane\TimelineLane;
use App\Service\Ingestion\Refresh\Pass\PassTickets;
use App\Service\Ingestion\Refresh\Pass\RefreshPass;
use App\Service\Social\Social\TimelineRounds;

/** Reads a pass's due feeds on one engine run, in due order whatever their kind; a stale session renews inside it. */
final readonly class ConcurrentPassFetcher implements PassFetcherInterface
{
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private TimelineRounds $timelineRounds,
        private int $hostConcurrency,
    ) {
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-host concurrency');
    }

    /**
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     *
     * @return \Generator<int|string, PassOutcomeModelInterface>
     */
    public function fetchAll(RefreshPass $pass, array $socialFeedsByFeedId): \Generator
    {
        $timelineLane = new TimelineLane($this->timelineRounds->start($socialFeedsByFeedId));
        $tickets = new PassTickets($pass, new FeedLane($this->hostConcurrency), $timelineLane);

        foreach ($this->engine->sendAll($tickets->tickets(), $tickets) as $feedId => $outcome) {
            yield from $tickets->drainUnasked();
            yield $feedId => $outcome;
        }

        yield from $tickets->drainUnasked();
    }
}
