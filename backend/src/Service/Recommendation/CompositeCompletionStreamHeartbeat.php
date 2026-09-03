<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One beat, several listeners. The transport pings a single heartbeat per
 * chunk and should not learn how many things care (#444): a tick's lock
 * keepalive and a worker's liveness marker both want every beat, neither
 * knowing the other exists.
 *
 * The two members are named, not iterated, because their order is an invariant.
 * Nothing is transactional, so a member that throws skips the rest for that
 * chunk, and the lock keepalive must run first: a missed lock refresh risks a
 * stolen lock and a double-banked run, a missed liveness mark only delays a UI
 * hint by one beat. As a list, re-sorting two YAML lines would silently break
 * that.
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
