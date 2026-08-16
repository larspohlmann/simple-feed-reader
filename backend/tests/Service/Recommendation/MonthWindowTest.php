<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\Exception\UnknownHistoryMonthException;
use App\Service\Recommendation\MonthWindow;
use App\Service\Recommendation\ViewerTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonthWindow::class)]
final class MonthWindowTest extends TestCase
{
    public function testSpansTheMonthInUtcWhenTheViewerIsInUtc(): void
    {
        $window = MonthWindow::of('2026-08', ViewerTimeZone::of('UTC'));

        self::assertSame('2026-08', $window->month);
        self::assertSame('2026-08-01 00:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-01 00:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /**
     * The stored value is naive UTC, so a Berlin viewer's August starts two
     * hours before UTC midnight on 1 August and ends two hours before it on
     * 1 September — which is exactly why the boundary cannot be the literal
     * month string.
     */
    public function testShiftsTheBoundariesIntoUtcForAViewerAheadOfIt(): void
    {
        $window = MonthWindow::of('2026-08', ViewerTimeZone::of('Europe/Berlin'));

        self::assertSame('2026-07-31 22:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-31 22:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /**
     * The mirror image of the Berlin case: a viewer behind UTC starts August
     * *after* UTC midnight on 1 August. Both zones above sit at or ahead of
     * UTC, so without this only one sign of the shift is pinned and an
     * implementation that took the offset's absolute value would still pass.
     * New York is on EDT (UTC-4) at both ends of August 2026.
     */
    public function testShiftsTheBoundariesTheOtherWayForAViewerBehindUtc(): void
    {
        $window = MonthWindow::of('2026-08', ViewerTimeZone::of('America/New_York'));

        self::assertSame('2026-08-01 04:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-01 04:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    /** A month whose start and end sit on different sides of a DST change keeps
     *  local midnight at both ends rather than drifting an hour. */
    public function testKeepsLocalMidnightAcrossADaylightSavingChange(): void
    {
        $window = MonthWindow::of('2026-10', ViewerTimeZone::of('Europe/Berlin'));

        self::assertSame('2026-09-30 22:00:00', $window->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-31 23:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    public function testSpansDecemberIntoTheFollowingJanuary(): void
    {
        $window = MonthWindow::of('2026-12', ViewerTimeZone::of('UTC'));

        self::assertSame('2027-01-01 00:00:00', $window->endUtc->format('Y-m-d H:i:s'));
    }

    public function testBothBoundariesAreExpressedInUtc(): void
    {
        $window = MonthWindow::of('2026-08', ViewerTimeZone::of('Europe/Berlin'));

        self::assertSame('UTC', $window->startUtc->getTimezone()->getName());
        self::assertSame('UTC', $window->endUtc->getTimezone()->getName());
    }

    public function testRefusesAMonthNumberNoYearHas(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindow::of('2026-13', ViewerTimeZone::of('UTC'));
    }

    public function testRefusesAMonthNumberOfZero(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindow::of('2026-00', ViewerTimeZone::of('UTC'));
    }

    public function testRefusesSomethingThatIsNotAMonthAtAll(): void
    {
        $this->expectException(UnknownHistoryMonthException::class);

        MonthWindow::of('August', ViewerTimeZone::of('UTC'));
    }
}
