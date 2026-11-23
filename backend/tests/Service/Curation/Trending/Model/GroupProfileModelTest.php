<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Model\GroupProfileModel;
use PHPUnit\Framework\TestCase;

final class GroupProfileModelTest extends TestCase
{
    public function testIdenticalProfilesScoreOne(): void
    {
        $profile = new GroupProfileModel([1 => 2.0, 2 => 3.0], 0, 0);

        self::assertSame(1.0, $profile->computeSimilarity($profile));
    }

    public function testSimilarityIsSumOfMinimaOverSumOfMaxima(): void
    {
        $left = new GroupProfileModel([1 => 2.0, 2 => 4.0], 0, 0);
        $right = new GroupProfileModel([2 => 2.0, 3 => 2.0], 0, 0);

        self::assertSame(2.0 / 8.0, $left->computeSimilarity($right));
    }

    public function testDisjointOrEmptyProfilesScoreZero(): void
    {
        $left = new GroupProfileModel([1 => 1.0], 0, 0);
        $right = new GroupProfileModel([2 => 1.0], 0, 0);
        $empty = new GroupProfileModel([], 0, 0);

        self::assertSame(0.0, $left->computeSimilarity($right));
        self::assertSame(0.0, $empty->computeSimilarity($empty));
    }

    public function testOverlappingSpansAreWithinAnyGap(): void
    {
        $left = new GroupProfileModel([], 100, 200);
        $right = new GroupProfileModel([], 150, 300);

        self::assertTrue($left->isWithinGap($right, 0));
        self::assertTrue($right->isWithinGap($left, 0));
    }

    public function testSpansFurtherApartThanTheGapAreNot(): void
    {
        $earlier = new GroupProfileModel([], 0, 100);
        $later = new GroupProfileModel([], 200, 300);

        self::assertTrue($earlier->isWithinGap($later, 100));
        self::assertFalse($earlier->isWithinGap($later, 99));
        self::assertTrue($later->isWithinGap($earlier, 100));
        self::assertFalse($later->isWithinGap($earlier, 99));
    }
}
