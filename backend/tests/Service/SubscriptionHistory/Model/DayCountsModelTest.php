<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use PHPUnit\Framework\TestCase;

final class DayCountsModelTest extends TestCase
{
    public function testOfReturnsTheStoredCounts(): void
    {
        $counts = new DayCountsModel([7 => ['2026-10-10' => new DayCountModel(4, 2)]]);

        self::assertTrue($counts->has(7, '2026-10-10'));
        self::assertSame(4, $counts->of(7, '2026-10-10')->arrived);
        self::assertSame(2, $counts->of(7, '2026-10-10')->read);
    }

    public function testOfIsZeroForAMissingDayOrSubscription(): void
    {
        $counts = new DayCountsModel([7 => ['2026-10-10' => new DayCountModel(4, 2)]]);

        self::assertFalse($counts->has(7, '2026-10-11'));
        self::assertSame(0, $counts->of(7, '2026-10-11')->arrived);
        self::assertSame(0, $counts->of(8, '2026-10-10')->read);
    }
}
