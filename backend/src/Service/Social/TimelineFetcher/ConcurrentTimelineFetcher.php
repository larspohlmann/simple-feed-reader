<?php

declare(strict_types=1);

namespace App\Service\Social\TimelineFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Pass\TimelineRound;
use App\Service\Social\TimelineRounds;

/**
 * Reads a refresh's private timelines concurrently on the shared request engine, capped per account and per
 * server. A stale Bluesky session sends its feeds again in a second wave, once the account has a fresh session.
 */
final readonly class ConcurrentTimelineFetcher implements TimelineFetcherInterface
{
    public function __construct(private ConcurrentRequestEngine $engine, private TimelineRounds $rounds)
    {
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function fetchAll(iterable $socialFeeds): \Generator
    {
        $round = $this->rounds->start();

        yield from $this->wave($round, $round->tickets($socialFeeds));
        yield from $this->wave($round, $round->startRenewalWave());
    }

    /**
     * @param iterable<int|string, RequestTicketModel> $tickets
     *
     * @return \Generator<int|string, TimelineOutcomeModelInterface>
     */
    private function wave(TimelineRound $round, iterable $tickets): \Generator
    {
        foreach ($this->engine->sendAll($tickets, $round) as $feedId => $outcome) {
            yield from $round->drainUnasked();
            $settled = $round->settle($feedId, $outcome);
            if (null !== $settled) {
                yield $feedId => $settled;
            }
        }

        yield from $round->drainUnasked();
    }
}
