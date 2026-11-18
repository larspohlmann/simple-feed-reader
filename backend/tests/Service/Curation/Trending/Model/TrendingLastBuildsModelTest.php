<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\TrendingLastBuildsModel;
use PHPUnit\Framework\TestCase;

final class TrendingLastBuildsModelTest extends TestCase
{
    public function testTheNextBuildIsDueAtTheEarliestWindowsIntervalAfterItsBuild(): void
    {
        $builds = new TrendingLastBuildsModel(
            ['day' => self::utc('2031-05-14 10:00:00'), 'week' => self::utc('2031-05-14 09:30:00')],
            [],
            [],
        );

        self::assertEquals(self::utc('2031-05-14 10:10:00'), $builds->nextBuildDueAt());
    }

    public function testNoNextBuildTimeWhileAWindowWasNeverBuilt(): void
    {
        $builds = new TrendingLastBuildsModel(['day' => self::utc('2031-05-14 10:00:00')], [], []);

        self::assertNull($builds->nextBuildDueAt());
    }

    public function testAWindowIsDueItsIntervalAfterItsBuild(): void
    {
        $builds = new TrendingLastBuildsModel(['day' => self::utc('2031-05-14 10:00:00')], [], []);

        self::assertEquals(self::utc('2031-05-14 10:10:00'), $builds->dueAt(TrendingWindow::Day));
    }

    public function testAWindowNeverBuiltHasNoDueTime(): void
    {
        $builds = new TrendingLastBuildsModel(['day' => self::utc('2031-05-14 10:00:00')], [], []);

        self::assertNull($builds->dueAt(TrendingWindow::Week));
    }

    private static function utc(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }
}
