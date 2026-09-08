<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\RecommendationBatchSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecommendationBatchSizeTest extends TestCase
{
    public function testMediumReproducesTheAutomaticCeilingUnchanged(): void
    {
        self::assertSame(100, RecommendationBatchSize::Medium->batchItemCap(100));
        self::assertSame(45, RecommendationBatchSize::Medium->batchItemCap(45));
    }

    #[DataProvider('capCases')]
    public function testSizeScalesTheAutomaticCeiling(
        RecommendationBatchSize $size,
        int $automaticCeiling,
        int $expectedCap,
    ): void {
        self::assertSame($expectedCap, $size->batchItemCap($automaticCeiling));
    }

    /**
     * @return iterable<string, array{RecommendationBatchSize, int, int}>
     */
    public static function capCases(): iterable
    {
        yield 'small halves' => [RecommendationBatchSize::Small, 100, 50];
        yield 'large doubles' => [RecommendationBatchSize::Large, 100, 200];
        yield 'small of an odd ceiling floors' => [RecommendationBatchSize::Small, 45, 22];
        yield 'large of an odd ceiling' => [RecommendationBatchSize::Large, 45, 90];
    }

    public function testCapNeverFallsBelowOne(): void
    {
        self::assertSame(1, RecommendationBatchSize::Small->batchItemCap(1));
    }
}
