<?php

declare(strict_types=1);

namespace App\Service\Ai\Completion\CompletionStreamHeartbeat;

/**
 * Told on every streamed chunk that a completion still runs, so a process others watch for liveness stays alive
 * while it waits. No content: CompletionStreamObserverInterface watches that.
 */
interface CompletionStreamHeartbeatInterface
{
    public function beat(): void;
}
