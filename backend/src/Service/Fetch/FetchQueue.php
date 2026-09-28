<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\ProxyConfigModel;

/**
 * The engine's work list: redirect continuations first, then tickets not yet
 * started. Continuations jump the queue because they already hold an open
 * redirect chain — deferring them behind fresh work would let a chain sit
 * half-finished while the concurrency slots fill with new feeds.
 *
 * It also paces per host. An attempt whose host already runs at capacity is
 * parked rather than started, and served later once a same-host slot frees — so
 * a run over many feeds on one host never bursts. To keep a busy host from
 * starving the free global slots, the queue looks a bounded number of tickets
 * past a full host to find one that can run; beyond that it waits rather than
 * draining — and committing — the whole budgeted ticket source up front.
 *
 * Mutable by design; it is the one piece of the fetch loop that has to be.
 */
final class FetchQueue
{
    /** @var list<FetchAttemptModel> */
    private array $continuations = [];

    /** @var list<FetchAttemptModel> */
    private array $parked = [];

    private bool $currentConsumed = false;

    /**
     * The batch proxy is a field here rather than a value copied onto every
     * ticket: it is resolved once per run and is the same for every feed, so
     * this is the one place that has to know it.
     *
     * @param \Iterator<int|string, FetchTicketModel> $tickets
     */
    public function __construct(
        private readonly \Iterator $tickets,
        private readonly HostSlots $hostSlots,
        private readonly int $lookAhead,
        private readonly ?ProxyConfigModel $batchProxy = null,
    ) {
    }

    public function requeue(FetchAttemptModel $attempt): void
    {
        $this->continuations[] = $attempt;
    }

    /** Records that an attempt went on the wire, occupying a slot on its host. */
    public function onSent(FetchAttemptModel $attempt): void
    {
        $this->hostSlots->acquire($attempt);
    }

    /** Records that an attempt's response retired, freeing its host slot. */
    public function onRetired(FetchAttemptModel $attempt): void
    {
        $this->hostSlots->release($attempt);
    }

    /**
     * The next attempt that may go on the wire now, or null when nothing can:
     * either the work is done, or every candidate's host is full and the caller
     * must wait for an in-flight response to free a slot.
     */
    public function takeRunnable(): ?FetchAttemptModel
    {
        $freed = $this->takeFreedFromPark();
        if (null !== $freed) {
            return $freed;
        }

        while (\count($this->parked) < $this->lookAhead && $this->hasFresh()) {
            $attempt = $this->takeFresh();
            if ($this->hostSlots->hasCapacityFor($attempt)) {
                return $attempt;
            }

            $this->parked[] = $attempt;
        }

        return null;
    }

    private function takeFreedFromPark(): ?FetchAttemptModel
    {
        foreach ($this->parked as $index => $attempt) {
            if ($this->hostSlots->hasCapacityFor($attempt)) {
                array_splice($this->parked, $index, 1);

                return $attempt;
            }
        }

        return null;
    }

    private function hasFresh(): bool
    {
        if ([] !== $this->continuations) {
            return true;
        }
        $this->retireConsumed();

        return $this->tickets->valid();
    }

    private function takeFresh(): FetchAttemptModel
    {
        $continuation = array_shift($this->continuations);
        if (null !== $continuation) {
            return $continuation;
        }

        $this->retireConsumed();

        $attempt = FetchAttemptModel::start($this->tickets->key(), $this->tickets->current(), $this->batchProxy);
        $this->currentConsumed = true;

        return $attempt;
    }

    /**
     * Advancing is deferred until the next item is wanted: the ticket source is
     * a budget-gated generator, and resuming it early would run its deadline
     * check — and its "started" tally — for a feed no slot has opened for yet.
     */
    private function retireConsumed(): void
    {
        if ($this->currentConsumed) {
            $this->tickets->next();
            $this->currentConsumed = false;
        }
    }
}
