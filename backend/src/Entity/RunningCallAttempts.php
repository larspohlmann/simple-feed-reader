<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\RunStatus;

/** A running run's call attempts, narrowed to recording so no holder can reset the MAX_TRANSPORT_FAILURES count. */
final readonly class RunningCallAttempts
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RecommendationRun $run, private RunCallAttempts $callAttempts)
    {
    }

    public function recordInvalidReply(string $reply): void
    {
        $this->guardRunning('record an invalid reply on');
        $this->callAttempts->recordInvalidReply($reply);
    }

    public function recordTransportFailure(): void
    {
        $this->guardRunning('record a transport failure on');
        $this->callAttempts->recordTransportFailure();
    }

    private function guardRunning(string $write): void
    {
        $status = $this->run->getStatus();
        if (RunStatus::Running !== $status) {
            throw new InvalidRunStatusException($write, $status);
        }
    }
}
