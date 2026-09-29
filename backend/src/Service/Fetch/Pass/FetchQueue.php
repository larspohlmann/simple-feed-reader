<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\ProxyConfigModel;

/**
 * The engine's work list. Redirect continuations jump the queue so an open chain never sits half-finished. An attempt
 * whose host is full is parked, and the queue looks at most `$lookAhead` tickets past it instead of draining, and so
 * committing, the budget-gated ticket source up front.
 */
final class FetchQueue
{
    /** @var list<FetchAttemptModel> */
    private array $continuations = [];

    /** @var list<FetchAttemptModel> */
    private array $parked = [];

    private bool $currentConsumed = false;

    /** @param \Iterator<int|string, FetchTicketModel> $tickets */
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

    public function onSent(FetchAttemptModel $attempt): void
    {
        $this->hostSlots->acquire($attempt);
    }

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
