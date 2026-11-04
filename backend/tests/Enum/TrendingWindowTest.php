<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\TrendingWindow;
use App\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrendingWindowTest extends TestCase
{
    public function testTheDayWindowSpansADayRebuildsEveryTenMinutesAndLinksWithinThirtySixHours(): void
    {
        self::assertSame(86_400, TrendingWindow::Day->spanSeconds());
        self::assertSame(600, TrendingWindow::Day->rebuildIntervalSeconds());
        self::assertSame(129_600, TrendingWindow::Day->maxLinkGapSeconds());
        self::assertSame(3, TrendingWindow::Day->minSubscribedFeeds());
    }

    public function testTheWeekWindowSpansSevenDaysRebuildsHourlyAndLinksWithinFortyEightHours(): void
    {
        self::assertSame(604_800, TrendingWindow::Week->spanSeconds());
        self::assertSame(3_600, TrendingWindow::Week->rebuildIntervalSeconds());
        self::assertSame(172_800, TrendingWindow::Week->maxLinkGapSeconds());
        self::assertSame(5, TrendingWindow::Week->minSubscribedFeeds());
    }

    #[DataProvider('outletMinimums')]
    public function testTheOutletMinimumIsTheFloorOrTheWindowsShareOfTheActiveFeedsRoundedUp(
        TrendingWindow $window,
        int $activeFeeds,
        int $minimum,
    ): void {
        self::assertSame($minimum, $window->minimumOutletsFor($activeFeeds));
    }

    /** @return iterable<string, array{TrendingWindow, int, int}> */
    public static function outletMinimums(): iterable
    {
        yield 'a day without active feeds keeps the floor' => [TrendingWindow::Day, 0, 3];
        yield 'three percent of 100 is exactly the day floor' => [TrendingWindow::Day, 100, 3];
        yield 'three percent of 101 rounds up past the day floor' => [TrendingWindow::Day, 101, 4];
        yield 'three percent of 129' => [TrendingWindow::Day, 129, 4];
        yield 'a week without active feeds keeps the floor' => [TrendingWindow::Week, 0, 5];
        yield '3.5 percent of 142 rounds up to the week floor' => [TrendingWindow::Week, 142, 5];
        yield '3.5 percent of 143 rounds up past the week floor' => [TrendingWindow::Week, 143, 6];
        yield '3.5 percent of 190' => [TrendingWindow::Week, 190, 7];
    }

    public function testAMissingOrEmptyWindowMeansTheDay(): void
    {
        self::assertSame(TrendingWindow::Day, TrendingWindow::fromRequestValue(null));
        self::assertSame(TrendingWindow::Day, TrendingWindow::fromRequestValue(''));
    }

    public function testBothWindowsParseByName(): void
    {
        self::assertSame(TrendingWindow::Week, TrendingWindow::fromRequestValue('week'));
        self::assertSame(TrendingWindow::Day, TrendingWindow::fromRequestValue('day'));
    }

    public function testAnUnknownWindowIsRefusedNamingTheField(): void
    {
        try {
            TrendingWindow::fromRequestValue('month');
            self::fail('An unknown window must be refused.');
        } catch (ValidationException $exception) {
            self::assertSame(['window'], array_keys($exception->errors));
        }
    }
}
