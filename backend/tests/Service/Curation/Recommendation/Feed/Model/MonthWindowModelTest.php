<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Feed\Model;

use App\Service\Clock\Model\ViewerTimeZoneModel;
use App\Service\Recommendation\Exception\UnknownHistoryMonthException;
use App\Service\Recommendation\Feed\Model\MonthWindowModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonthWindowModel::class)]
final class MonthWindowModelTest extends TestCase
{
    public function testSpansTheMonthInUtcWhenTheViewerIsInUtc(): void
    {
        $window = MonthWindowModel::of('2026-08', ViewerTimeZoneModel::of('UTC'));

        self::assertSame('2026-08', $window->month);
        self::assertSame('2026-08-01 00:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-01 00:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /**
     * Stored values are naive UTC, so a Berlin viewer's August starts and ends two hours before UTC midnight: the
     * boundary cannot be the literal month string.
     */
    public function testShiftsTheBoundariesIntoUtcForAViewerAheadOfIt(): void
    {
        $window = MonthWindowModel::of('2026-08', ViewerTimeZoneModel::of('Europe/Berlin'));

        self::assertSame('2026-07-31 22:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-31 22:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /**
     * A viewer behind UTC (New York, on EDT at both ends of August 2026) starts August after UTC midnight: without
     * this case an implementation that took the offset's absolute value would pass.
     */
    public function testShiftsTheBoundariesTheOtherWayForAViewerBehindUtc(): void
    {
        $window = MonthWindowModel::of('2026-08', ViewerTimeZoneModel::of('America/New_York'));

        self::assertSame('2026-08-01 04:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-01 04:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /** A month whose start and end sit on different sides of a DST change keeps
     *  local midnight at both ends rather than drifting an hour. */
    public function testKeepsLocalMidnightAcrossADaylightSavingChange(): void
    {
        $window = MonthWindowModel::of('2026-10', ViewerTimeZoneModel::of('Europe/Berlin'));

        self::assertSame('2026-09-30 22:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-31 23:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    public function testSpansDecemberIntoTheFollowingJanuary(): void
    {
        $window = MonthWindowModel::of('2026-12', ViewerTimeZoneModel::of('UTC'));

        self::assertSame('2027-01-01 00:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    public function testBothBoundariesAreExpressedInUtc(): void
    {
        $window = MonthWindowModel::of('2026-08', ViewerTimeZoneModel::of('Europe/Berlin'));

        self::assertSame('UTC', $window->startUtc->getTimezone()->getName());
        self::assertSame('UTC', $window->endUtc->getTimezone()->getName());
    }

    public function testRefusesAMonthNumberNoYearHas(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindowModel::of('2026-13', ViewerTimeZoneModel::of('UTC'));
    }

    public function testRefusesAMonthNumberOfZero(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindowModel::of('2026-00', ViewerTimeZoneModel::of('UTC'));
    }

    public function testRefusesSomethingThatIsNotAMonthAtAll(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindowModel::of('August', ViewerTimeZoneModel::of('UTC'));
    }
}
