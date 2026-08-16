<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * One beat, several listeners. The transport pings a single heartbeat on
 * every chunk and should not learn how many things care (#444): a streaming
 * tick's lock keepalive and a sweeping worker's liveness marker both want
 * every beat, and neither should know the other exists.
 */
final readonly class CompositeCompletionStreamHeartbeat implements CompletionStreamHeartbeat
{
    /**
     * @param iterable<CompletionStreamHeartbeat> $heartbeats
     */
    public function __construct(
        private iterable $heartbeats,
    ) {
    }

    public function beat(): void
    {
        foreach ($this->heartbeats as $heartbeat) {
            $heartbeat->beat();
        }
    }
}
