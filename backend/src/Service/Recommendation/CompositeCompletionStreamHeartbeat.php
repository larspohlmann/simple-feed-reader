<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One beat, several listeners. The transport pings a single heartbeat on
 * every chunk and should not learn how many things care (#444): a streaming
 * tick's lock keepalive and a sweeping worker's liveness marker both want
 * every beat, and neither should know the other exists.
 *
 * The two members are named rather than iterated because their order is an
 * invariant, not an arrangement. Nothing here is transactional, so a member
 * that throws skips the rest for that chunk, and the lock keepalive has to
 * survive that: a missed lock refresh risks a stolen lock and a double-banked
 * run, while a missed liveness mark only delays a UI hint by one beat. As a
 * list, that rule could only be written in a comment beside the wiring, where
 * re-sorting two YAML lines would silently break it.
 */
final readonly class CompositeCompletionStreamHeartbeat implements CompletionStreamHeartbeat
{
    public function __construct(
        private CompletionStreamHeartbeat $lockKeepalive,
        private CompletionStreamHeartbeat $workerLiveness,
    ) {
    }

    public function beat(): void
    {
        $this->lockKeepalive->beat();
        $this->workerLiveness->beat();
    }
}
