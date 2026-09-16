<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RunThrottle;
use PHPUnit\Framework\TestCase;

final class RunThrottleTest extends TestCase
{
    public function testAFreshThrottleNeitherWaitsNorReducesTheCap(): void
    {
        $throttle = new RunThrottle();

        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        self::assertSame(8, $throttle->effectiveCap(8));
    }

    public function testItGatesUntilTheDeferralTimePasses(): void
    {
        $throttle = new RunThrottle();
        $throttle->deferUntil(new \DateTimeImmutable('2026-01-01T00:00:10Z'));

        self::assertTrue($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:05Z')));
        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:10Z')));
        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:11Z')));
    }

    public function testHalvingFloorsAtOneAndNeverClimbs(): void
    {
        $throttle = new RunThrottle();

        $throttle->reduceConcurrency(8);
        self::assertSame(4, $throttle->effectiveCap(8));

        $throttle->reduceConcurrency(8);
        self::assertSame(2, $throttle->effectiveCap(8));

        $throttle->reduceConcurrency(8);
        self::assertSame(1, $throttle->effectiveCap(8));

        $throttle->reduceConcurrency(8);
        self::assertSame(1, $throttle->effectiveCap(8)); // floor holds
    }

    public function testAnOddCapHalvesDownward(): void
    {
        $throttle = new RunThrottle();
        $throttle->reduceConcurrency(3);

        self::assertSame(1, $throttle->effectiveCap(3));
    }

    public function testClearDeferralLeavesTheReducedCap(): void
    {
        $throttle = new RunThrottle();
        $throttle->reduceConcurrency(8);
        $throttle->deferUntil(new \DateTimeImmutable('2026-01-01T00:00:10Z'));

        $throttle->clearDeferral();

        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:05Z')));
        self::assertSame(4, $throttle->effectiveCap(8));
    }

    public function testResetRestoresFullConcurrencyAndClearsTheGate(): void
    {
        $throttle = new RunThrottle();
        $throttle->reduceConcurrency(8);
        $throttle->deferUntil(new \DateTimeImmutable('2026-01-01T00:00:10Z'));

        $throttle->reset();

        self::assertSame(8, $throttle->effectiveCap(8));
        self::assertFalse($throttle->mustWait(new \DateTimeImmutable('2026-01-01T00:00:05Z')));
    }
}
