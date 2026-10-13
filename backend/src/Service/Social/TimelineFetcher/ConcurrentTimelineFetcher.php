<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Pass\TimelineAccounts;
use App\Service\Social\Pass\TimelineRound;
use App\Service\Social\TimelineSources;

/**
 * Reads a refresh's private timelines concurrently on the shared request engine, `accountConcurrency` at most per
 * account. A stale Bluesky session sends its feeds again in a second wave, once the account has a fresh session.
 */
final readonly class ConcurrentTimelineFetcher implements TimelineFetcherInterface
{
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private TimelineSources $sources,
        private int $accountConcurrency,
    ) {
        CapacityGuard::atLeastOne($accountConcurrency, 'Per-account concurrency');
    }

    /** @return \Generator<int, TimelineOutcomeModelInterface> */
    public function fetchAll(iterable $socialFeeds): \Generator
    {
        $round = new TimelineRound($this->sources, new TimelineAccounts());

        yield from $this->wave($round, $round->tickets($socialFeeds));
        yield from $this->wave($round, $round->startRenewalWave());
    }

    /**
     * @param iterable<int, RequestTicketModel> $tickets
     *
     * @return \Generator<int, TimelineOutcomeModelInterface>
     */
    private function wave(TimelineRound $round, iterable $tickets): \Generator
    {
        foreach ($this->engine->sendAll($tickets, $this->accountConcurrency, $round) as $feedId => $outcome) {
            yield from $round->drainUnasked();
            \assert(\is_int($feedId));
            $settled = $round->settle($feedId, $outcome);
            if (null !== $settled) {
                yield $feedId => $settled;
            }
        }

        yield from $round->drainUnasked();
    }
}
