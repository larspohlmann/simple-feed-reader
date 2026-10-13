<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\TimelineRounds;

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

    /** @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface> */
    public function fetchAll(PassFeeds $feeds): \Generator
    {
        $round = $this->timelineRounds->start($feeds->socialFeedsByFeedId);
        $tickets = new PassTickets($feeds, $round, $this->hostConcurrency);

        foreach ($this->engine->sendAll($tickets->tickets(), $tickets) as $feedId => $outcome) {
            yield from $tickets->drainUnasked();
            yield $feedId => $outcome;
        }

        yield from $tickets->drainUnasked();
    }
}
