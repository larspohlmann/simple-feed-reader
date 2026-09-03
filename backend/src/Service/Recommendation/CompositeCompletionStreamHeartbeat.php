<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One beat, several listeners. The transport pings a single heartbeat per
 * chunk and should not learn how many things care (#444): a tick's lock
 * keepalive and a worker's liveness marker both want every beat, neither
 * knowing the other exists.
 *
 * The two members are named, not iterated, since their order is an
 * invariant: nothing is transactional, so a throwing member skips the rest
 * for that chunk, and the lock keepalive must run first — a missed refresh
 * risks a stolen lock and a double-banked run, while a missed liveness mark
 * only delays a UI hint by one beat. As a list, re-sorting two YAML lines
 * would silently break that.
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
