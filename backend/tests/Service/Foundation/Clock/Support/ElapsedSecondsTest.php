<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Clock\Support;

use App\Service\Foundation\Clock\Support\ElapsedSeconds;
use PHPUnit\Framework\TestCase;

final class ElapsedSecondsTest extends TestCase
{
    public function testCountsWholeAndFractionalSeconds(): void
    {
        self::assertEqualsWithDelta(
            2.25,
            ElapsedSeconds::between(
                new \DateTimeImmutable('2026-11-15 12:00:00.500000'),
                new \DateTimeImmutable('2026-11-15 12:00:02.750000'),
            ),
            1e-6,
        );
    }

    public function testAnEarlierEndIsNegative(): void
    {
        self::assertEqualsWithDelta(
            -1.0,
            ElapsedSeconds::between(
                new \DateTimeImmutable('2026-11-15 12:00:01'),
                new \DateTimeImmutable('2026-11-15 12:00:00'),
            ),
            1e-6,
        );
    }
}
