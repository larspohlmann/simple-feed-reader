<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Told that a completion is still streaming, so a process others watch for
 * liveness can say it is alive while it waits (#433).
 *
 * Separate from CompletionStreamObserver, which watches one call's content:
 * this carries no progress, only that the reader still runs. The transport
 * owns the only place that knows a chunk arrived, so the ping starts there;
 * who listens, and how often it may cost a write, is the implementation's.
 */
interface CompletionStreamHeartbeat
{
    public function beat(): void;
}
