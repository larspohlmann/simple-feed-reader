<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidRunStatusException;
use App\Enum\RunStatus;

/** A running run's throttle, narrowed to the rate-limit transitions so no holder can reset it mid-run. */
final readonly class RunningThrottle
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RecommendationRun $run, private RunThrottle $throttle)
    {
    }

    public function deferUntil(\DateTimeImmutable $when): void
    {
        $this->guardRunning('defer');
        $this->throttle->deferUntil($when);
    }

    public function reduceConcurrency(int $configuredCap): void
    {
        $this->guardRunning('narrow the wave of');
        $this->throttle->reduceConcurrency($configuredCap);
    }

    private function guardRunning(string $write): void
    {
        $status = $this->run->getStatus();
        if (RunStatus::Running !== $status) {
            throw new InvalidRunStatusException($write, $status);
        }
    }
}
