<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RetryPlan;
use App\Service\Recommendation\TickDriver;
use PHPUnit\Framework\TestCase;

final class RetryPlanTest extends TestCase
{
    public function testTheWorkerPlanBlocks(): void
    {
        self::assertTrue(RetryPlan::forDriver(TickDriver::Worker)->blocks());
    }

    public function testThePollAndSweepPlansDefer(): void
    {
        self::assertFalse(RetryPlan::forDriver(TickDriver::Poll)->blocks());
        self::assertFalse(RetryPlan::forDriver(TickDriver::Sweep)->blocks());
    }

    public function testTheBackoffScheduleIsOneTwoFour(): void
    {
        $plan = RetryPlan::forDriver(TickDriver::Worker);

        self::assertSame(1.0, $plan->waitSecondsFor(0, null));
        self::assertSame(2.0, $plan->waitSecondsFor(1, null));
        self::assertSame(4.0, $plan->waitSecondsFor(2, null));
    }

    public function testRetryAfterOverridesTheBackoffStep(): void
    {
        self::assertSame(30.0, RetryPlan::forDriver(TickDriver::Worker)->waitSecondsFor(0, 30));
    }

    public function testItAllowsThreeRetriesWithinATwoMinuteBudget(): void
    {
        $plan = RetryPlan::forDriver(TickDriver::Worker);

        self::assertSame(3, $plan->maxRetries());
        self::assertSame(120.0, $plan->budgetSeconds());
    }
}
