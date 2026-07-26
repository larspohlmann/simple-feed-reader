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
        return [] !== $this->continuations || $this->tickets->valid();
    }

    public function next(): FetchAttempt
    {
        $continuation = array_shift($this->continuations);
        if (null !== $continuation) {
            return $continuation;
        }

        if (!$this->tickets->valid()) {
            throw new \LogicException('next() called on an exhausted queue; guard with hasMore().');
        }

        $attempt = FetchAttempt::start($this->tickets->key(), $this->tickets->current());
        $this->tickets->next();

        return $attempt;
    }
}
