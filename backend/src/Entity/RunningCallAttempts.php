<?php

declare(strict_types=1);

namespace App\Entity;

/** A running run's call attempts, narrowed to recording so no holder can reset the MAX_TRANSPORT_FAILURES count. */
final readonly class RunningCallAttempts
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RunCallAttempts $callAttempts)
    {
    }

    public function recordInvalidReply(string $reply): void
    {
        $this->callAttempts->recordInvalidReply($reply);
    }

    public function recordTransportFailure(): void
    {
        $this->callAttempts->recordTransportFailure();
    }
}
