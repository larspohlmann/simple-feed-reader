<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ForYouStreamPrecision;
use PHPUnit\Framework\TestCase;

final class ForYouStreamPrecisionTest extends TestCase
{
    public function testTheStepsNarrowToWideCarryTheirMinimumScores(): void
    {
        self::assertSame(
            [4.0, 3.0, 2.0, 1.5, 1.0],
            array_map(
                static fn (ForYouStreamPrecision $precision): float => $precision->minimumScore(),
                ForYouStreamPrecision::cases(),
            ),
        );
        self::assertSame(ForYouStreamPrecision::Wide, ForYouStreamPrecision::DEFAULT);
    }

    public function testTheStepsAreStoredUnderTheirNames(): void
    {
        self::assertSame(
            ['narrowest', 'narrow', 'balanced', 'wide', 'widest'],
            array_map(
                static fn (ForYouStreamPrecision $precision): string => $precision->value,
                ForYouStreamPrecision::cases(),
            ),
        );
    }
}
