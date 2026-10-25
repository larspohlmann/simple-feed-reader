<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Model\DayWindowModel;
use PHPUnit\Framework\TestCase;

final class DayWindowModelTest extends TestCase
{
    public function testListsTheDaysEndingTodayOldestFirst(): void
    {
        $window = DayWindowModel::endingOn('2026-10-02', 4);

        self::assertSame(['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'], $window->dates);
        self::assertSame('2026-09-29', $window->oldest());
        self::assertSame('2026-10-02', $window->today());
        self::assertSame(['2026-09-29', '2026-09-30', '2026-10-01'], $window->completedDates());
        self::assertSame(['2026-09-30', '2026-10-01'], $window->lastCompleted(2));
    }
}
