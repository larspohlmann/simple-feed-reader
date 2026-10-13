<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\TimelineRounds;

/**
 * Reads a pass's due feeds on one engine run, in due order whatever their kind. A stale Bluesky session sends its
 * feeds again in a second, small run once the account has a fresh session.
 */
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

        yield from $this->run($tickets->tickets(), $tickets);
        yield from $this->run($round->startRenewalWave(), $tickets);
    }

    /**
     * @param iterable<int|string, RequestTicketModel> $requests
     *
     * @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface>
     */
    private function run(iterable $requests, PassTickets $tickets): \Generator
    {
        foreach ($this->engine->sendAll($requests, $tickets) as $feedId => $settled) {
            yield from $tickets->drainUnasked();
            if (null !== $settled) {
                yield $feedId => $settled;
            }
        }

        yield from $tickets->drainUnasked();
    }
}
