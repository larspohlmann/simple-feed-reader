<?php

declare(strict_types=1);

namespace App\Entity;

/** A running run's throttle, narrowed to the rate-limit transitions so no holder can reset it mid-run. */
final readonly class RunningThrottle
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private RunThrottle $throttle)
    {
    }

    public function deferUntil(\DateTimeImmutable $when): void
    {
        $this->throttle->deferUntil($when);
    }

    public function reduceConcurrency(int $configuredCap): void
    {
        $this->throttle->reduceConcurrency($configuredCap);
    }
}
