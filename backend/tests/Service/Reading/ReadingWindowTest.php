<?php

declare(strict_types=1);

namespace App\Tests\Service\Reading;

use App\Service\Reading\ReadingWindow;
use App\Service\Recommendation\ViewerTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The window the reading chart covers (#896): the last N calendar days cut in
 * the viewer's own timezone, with the lower query bound expressed in UTC.
 */
final class ReadingWindowTest extends TestCase
{
    public function testCoversTheLastNDaysInclusiveOfTodayOldestFirst(): void
    {
        $window = ReadingWindow::lastDays(
            30,
            ViewerTimeZone::of('UTC'),
            new \DateTimeImmutable('2026-09-07 12:00:00', new \DateTimeZone('UTC')),
        );

        self::assertCount(30, $window->localDates);
        self::assertSame('2026-08-09', $window->localDates[0]);
        self::assertSame('2026-09-07', $window->localDates[29]);
    }

    public function testSinceBoundIsLocalMidnightOfTheOldestDayExpressedInUtc(): void
    {
        // New York is UTC-4 on this September date, so a 02:00 UTC "now" is still
        // the previous evening locally: the newest local day is 2026-09-06, the
        // oldest is 2026-08-08, and its local midnight is 04:00 UTC.
        $window = ReadingWindow::lastDays(
            30,
            ViewerTimeZone::of('America/New_York'),
            new \DateTimeImmutable('2026-09-07 02:00:00', new \DateTimeZone('UTC')),
        );

        self::assertSame('2026-08-08', $window->localDates[0]);
        self::assertSame('2026-09-06', $window->localDates[29]);
        self::assertSame('2026-08-08 04:00:00', $window->sinceUtc->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $window->sinceUtc->getTimezone()->getName());
    }
}
