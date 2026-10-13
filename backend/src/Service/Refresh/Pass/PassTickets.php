<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Service\Fetch\DispatchGate\DispatchGateInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Pass\TimelineRound;

/**
 * One pass's engine tickets in due order: an RSS or Atom feed by its URL, a private feed through its round. Only a
 * held account's private tickets are withheld. A private feed that settles without a request waits until drained.
 */
final class PassTickets implements DispatchGateInterface
{
    /** @var array<int, TimelineOutcomeModelInterface> */
    private array $orphaned = [];

    public function __construct(
        private readonly PassFeeds $feeds,
        private readonly TimelineRound $round,
        private readonly int $hostCapacity,
    ) {
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds->started() as $feedId => $feed) {
            if ($this->feeds->isPrivate($feedId)) {
                yield from $this->privateTicketOf($feedId);
                continue;
            }

            yield $feedId => RequestTicketModel::feed(
                new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified()),
                $this->hostCapacity,
            );
        }
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function renewalTickets(): \Generator
    {
        return $this->round->startRenewalWave();
    }

    public function admits(int|string $key): bool
    {
        return !$this->feeds->isPrivate($key) || $this->round->admits($key);
    }

    /** The feed's outcome, or null while a private feed waits for the renewal wave. */
    public function settle(
        int|string $feedId,
        FetchOutcomeModel $outcome,
    ): FetchOutcomeModel|TimelineOutcomeModelInterface|null {
        return $this->feeds->isPrivate($feedId) ? $this->round->settle($feedId, $outcome) : $outcome;
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        $orphaned = $this->orphaned;
        $this->orphaned = [];

        yield from $orphaned;
        yield from $this->round->drainUnasked();
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    private function privateTicketOf(int $feedId): \Generator
    {
        try {
            $this->feeds->socialFeedOf($feedId);
        } catch (PrivateFeedOrphanedException $orphaned) {
            $this->orphaned[$feedId] = new FailedTimelineModel($orphaned);

            return;
        }

        yield from $this->round->ticketFor($feedId);
    }
}
