<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RetryPlan;
use PHPUnit\Framework\TestCase;

final class RetryPlanTest extends TestCase
{
    public function testTheBlockingPlanBlocks(): void
    {
        self::assertTrue(RetryPlan::blocking()->blocks());
    }

    public function testTheDeferringPlanDefers(): void
    {
        self::assertFalse(RetryPlan::deferring()->blocks());
    }

    public function testTheBackoffScheduleIsOneTwoFour(): void
    {
        $plan = RetryPlan::blocking();

        self::assertSame(1.0, $plan->waitSecondsFor(0, null));
        self::assertSame(2.0, $plan->waitSecondsFor(1, null));
        self::assertSame(4.0, $plan->waitSecondsFor(2, null));
    }

    public function testRetryAfterOverridesTheBackoffStep(): void
    {
        self::assertSame(30.0, RetryPlan::blocking()->waitSecondsFor(0, 30));
    }

    public function testItAllowsThreeRetriesWithinATwoMinuteBudget(): void
    {
        $plan = RetryPlan::blocking();

        self::assertSame(3, $plan->maxRetries());
        self::assertSame(120.0, $plan->budgetSeconds());
    }
}
