<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Support\PartWeightCaps;
use PHPUnit\Framework\TestCase;

final class PartWeightCapsTest extends TestCase
{
    private const int POLICE_OPERATION = 1;
    private const int FIRE_OPERATION = 4;

    public function testNoPartsLeaveTheSharedWeightsAlone(): void
    {
        self::assertSame([10 => 2.5], self::addedTo([10 => 2.5], []));
    }

    public function testAPartWithinItsCompoundsWeightCountsWhole(): void
    {
        self::assertSame([10 => 3.0], self::addedTo([10 => 1.0], [10 => [[2.0, [self::POLICE_OPERATION => 1.0]]]]));
    }

    public function testAPartAboveItsCompoundsWeightAddsThatWeight(): void
    {
        self::assertSame([10 => 6.0], self::addedTo([10 => 1.0], [10 => [[7.0, [self::POLICE_OPERATION => 1.0]]]]));
    }

    public function testTheCompoundsWeightIsItsPlaceWeightTimesItsRarity(): void
    {
        self::assertSame([10 => 2.5], self::addedTo([], [10 => [[7.0, [self::POLICE_OPERATION => 0.5]]]]));
    }

    public function testPartsWithinTheCompoundsWeightCountWhole(): void
    {
        $parts = [10 => [[2.0, [self::POLICE_OPERATION => 1.0]], [2.5, [self::POLICE_OPERATION => 1.0]]]];

        self::assertSame([10 => 5.5], self::addedTo([10 => 1.0], $parts));
    }

    public function testPartsAboveTheCompoundsWeightAddExactlyThatWeight(): void
    {
        $parts = [10 => [[4.0, [self::POLICE_OPERATION => 1.0]], [6.0, [self::POLICE_OPERATION => 1.0]]]];

        self::assertEqualsWithDelta(5.0, self::addedTo([], $parts)[10], 1e-9);
    }

    public function testEachPairHasItsOwnCap(): void
    {
        $parts = [
            10 => [[4.0, [self::POLICE_OPERATION => 1.0]], [4.0, [self::POLICE_OPERATION => 1.0]]],
            20 => [[4.0, [self::POLICE_OPERATION => 1.0]]],
        ];

        self::assertSame([10 => 5.0, 20 => 4.0], self::addedTo([], $parts));
    }

    public function testEachCompoundHasItsOwnCap(): void
    {
        $parts = [10 => [[8.0, [self::POLICE_OPERATION => 1.0]], [1.0, [self::FIRE_OPERATION => 1.0]]]];

        self::assertSame([10 => 6.0], self::addedTo([], $parts));
    }

    public function testAPartThroughTwoCompoundsIsHeldToTheTighterCap(): void
    {
        $throughBoth = [self::POLICE_OPERATION => 1.0, self::FIRE_OPERATION => 1.0];

        self::assertSame([10 => 5.0], self::addedTo([], [10 => [[6.0, $throughBoth]]]));
        self::assertEqualsWithDelta(
            8.6,
            self::addedTo([], [10 => [[6.0, $throughBoth], [4.0, [self::FIRE_OPERATION => 1.0]]]])[10],
            1e-9,
            'the first part keeps 5/6 for police operation, the second 9/10 for fire operation',
        );
    }

    /**
     * @param array<int, float> $shared
     * @param array<int, list<array{float, array<int, float>}>> $parts
     *
     * @return array<int, float>
     */
    private static function addedTo(array $shared, array $parts): array
    {
        $rarities = [self::POLICE_OPERATION => 5.0, self::FIRE_OPERATION => 9.0];

        return PartWeightCaps::addedTo($shared, $parts, static fn (int $stemId): float => $rarities[$stemId]);
    }
}
