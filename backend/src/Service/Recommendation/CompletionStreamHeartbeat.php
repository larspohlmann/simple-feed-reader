<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Told that a completion is still streaming, so a process whose liveness
 * others watch can say it is alive while it waits (#433).
 *
 * Separate from CompletionStreamObserver, which watches one call's content on
 * behalf of that call: this one carries no progress at all, because the only
 * thing it reports is that the reader is still running. The transport owns the
 * only place that knows a chunk arrived, so the ping starts there; who cares
 * about it, and how often it may cost a write, is the implementation's
 * business.
 */
interface CompletionStreamHeartbeat
{
    public function beat(): void;
}
