<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * The engine's work list: redirect continuations first, then tickets not yet
 * started. Continuations jump the queue because they already hold an open
 * redirect chain — deferring them behind fresh work would let a chain sit
 * half-finished while the concurrency slots fill with new feeds.
 *
 * Mutable by design; it is the one piece of the fetch loop that has to be.
 */
final class FetchQueue
{
    /** @var list<FetchAttempt> */
    private array $continuations = [];

    private bool $currentConsumed = false;

    /** @param \Iterator<int|string, FetchTicket> $tickets */
    public function __construct(private readonly \Iterator $tickets)
    {
    }

    public function requeue(FetchAttempt $attempt): void
    {
        $this->continuations[] = $attempt;
    }

    public function hasMore(): bool
    {
        if ([] !== $this->continuations) {
            return true;
        }
        $this->retireConsumed();

        return $this->tickets->valid();
    }

    public function next(): FetchAttempt
    {
        $continuation = array_shift($this->continuations);
        if (null !== $continuation) {
            return $continuation;
        }

        $this->retireConsumed();
        if (!$this->tickets->valid()) {
            throw new \LogicException('next() called on an exhausted queue; guard with hasMore().');
        }

        $attempt = FetchAttempt::start($this->tickets->key(), $this->tickets->current());
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
