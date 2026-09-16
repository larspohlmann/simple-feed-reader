<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run's rate-limit throttle (#947): when the next provider call may fire, and
 * the wave concurrency lowered after a 429. Embedded, unprefixed columns, like
 * RunBatchProgress and RunProfile — the two belong to one concern and keep
 * RecommendationRun's field count down.
 */
#[ORM\Embeddable]
class RunThrottle
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retryNotBefore = null;

    #[ORM\Column(nullable: true)]
    private ?int $reducedConcurrency = null;

    public function deferUntil(\DateTimeImmutable $when): void
    {
        $this->retryNotBefore = $when;
    }

    public function mustWait(\DateTimeImmutable $now): bool
    {
        return null !== $this->retryNotBefore && $now < $this->retryNotBefore;
    }

    public function retryNotBefore(): ?\DateTimeImmutable
    {
        return $this->retryNotBefore;
    }

    public function clearDeferral(): void
    {
        $this->retryNotBefore = null;
    }

    public function reduceConcurrency(int $configuredCap): void
    {
        $base = $this->reducedConcurrency ?? $configuredCap;
        $this->reducedConcurrency = max(1, intdiv($base, 2));
    }

    public function effectiveCap(int $configuredCap): int
    {
        return min($configuredCap, $this->reducedConcurrency ?? $configuredCap);
    }

    public function reset(): void
    {
        $this->retryNotBefore = null;
        $this->reducedConcurrency = null;
    }
}
