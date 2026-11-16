<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Clock\Support;

use App\Service\Foundation\Clock\Support\DeadlineFit;
use PHPUnit\Framework\TestCase;

final class DeadlineFitTest extends TestCase
{
    private const string NOW = '2026-11-16 12:00:00';

    public function testCountsTheWholeUnitsBeforeTheDeadline(): void
    {
        self::assertSame(2, self::wholeUnitsUntil('2026-11-16 12:00:17', 8.0));
    }

    public function testAUnitEndingOnTheDeadlineFits(): void
    {
        self::assertSame(2, self::wholeUnitsUntil('2026-11-16 12:00:16', 8.0));
    }

    public function testLessThanAUnitLeftFitsNone(): void
    {
        self::assertSame(0, self::wholeUnitsUntil('2026-11-16 12:00:07', 8.0));
    }

    public function testAPassedDeadlineFitsNone(): void
    {
        self::assertSame(0, self::wholeUnitsUntil('2026-11-16 11:59:00', 8.0));
    }

    public function testFractionsOfASecondAreNotCounted(): void
    {
        self::assertSame(0, self::wholeUnitsUntil('2026-11-16 12:00:00.900000', 0.5));
    }

    private static function wholeUnitsUntil(string $deadline, float $unitSeconds): int
    {
        return DeadlineFit::wholeUnits(
            new \DateTimeImmutable($deadline),
            new \DateTimeImmutable(self::NOW),
            $unitSeconds,
        );
    }
}
