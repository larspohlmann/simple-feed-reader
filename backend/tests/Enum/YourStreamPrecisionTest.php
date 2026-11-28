<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\YourStreamPrecision;
use PHPUnit\Framework\TestCase;

final class YourStreamPrecisionTest extends TestCase
{
    public function testTheStepsNarrowToWideCarryTheirMinimumScores(): void
    {
        self::assertSame(
            [4.0, 3.0, 2.0, 1.5, 1.0],
            array_map(
                static fn (YourStreamPrecision $precision): float => $precision->minimumScore(),
                YourStreamPrecision::cases(),
            ),
        );
        self::assertSame(YourStreamPrecision::Wide, YourStreamPrecision::DEFAULT);
    }

    public function testTheStepsAreStoredUnderTheirNames(): void
    {
        self::assertSame(
            ['narrowest', 'narrow', 'balanced', 'wide', 'widest'],
            array_map(
                static fn (YourStreamPrecision $precision): string => $precision->value,
                YourStreamPrecision::cases(),
            ),
        );
    }
}
