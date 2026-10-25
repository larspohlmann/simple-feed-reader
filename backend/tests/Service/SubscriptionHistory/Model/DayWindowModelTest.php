<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Model\DayWindowModel;
use PHPUnit\Framework\TestCase;

final class DayWindowModelTest extends TestCase
{
    public function testListsTheThirtyDaysEndingTodayOldestFirst(): void
    {
        $window = DayWindowModel::lastDays('2026-10-02');

        self::assertCount(30, $window->dates);
        self::assertSame('2026-09-03', $window->dates[0]);
        self::assertSame('2026-09-04', $window->dates[1]);
        self::assertSame('2026-09-03', $window->oldest());
        self::assertSame('2026-10-02', $window->today());
        self::assertCount(29, $window->completedDates());
        self::assertSame('2026-10-01', $window->completedDates()[28]);
    }
}
