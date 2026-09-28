<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Completion\Model;

use App\Service\Ai\Completion\Model\RetryPlanModel;
use PHPUnit\Framework\TestCase;

final class RetryPlanModelTest extends TestCase
{
    public function testTheBlockingPlanBlocks(): void
    {
        self::assertTrue(RetryPlanModel::blocking()->blocks());
    }

    public function testTheDeferringPlanDefers(): void
    {
        self::assertFalse(RetryPlanModel::deferring()->blocks());
    }

    public function testTheBackoffScheduleIsOneTwoFour(): void
    {
        $plan = RetryPlanModel::blocking();

        self::assertSame(1.0, $plan->waitSecondsFor(0, null));
        self::assertSame(2.0, $plan->waitSecondsFor(1, null));
        self::assertSame(4.0, $plan->waitSecondsFor(2, null));
    }

    public function testRetryAfterOverridesTheBackoffStep(): void
    {
        self::assertSame(30.0, RetryPlanModel::blocking()->waitSecondsFor(0, 30));
    }

    public function testItAllowsThreeRetriesWithinATwoMinuteBudget(): void
    {
        $plan = RetryPlanModel::blocking();

        self::assertSame(3, $plan->maxRetries());
        self::assertSame(120.0, $plan->budgetSeconds());
    }
}
