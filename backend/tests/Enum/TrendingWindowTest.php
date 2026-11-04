<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\TrendingWindow;
use App\Exception\ValidationException;
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
