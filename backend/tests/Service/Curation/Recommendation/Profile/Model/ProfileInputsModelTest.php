<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile\Model;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use App\Service\Recommendation\Profile\Model\ProfileInputsModel;
use PHPUnit\Framework\TestCase;

final class ProfileInputsModelTest extends TestCase
{
    public function testWithoutHistoryAndWithoutSavedSearchesItIsEmpty(): void
    {
        self::assertTrue((new ProfileInputsModel(new RecommendationHistoryModel([], [], []), []))->isEmpty());
    }

    public function testASavedSearchAloneIsSomethingToBuildFrom(): void
    {
        self::assertFalse((new ProfileInputsModel(new RecommendationHistoryModel([], [], []), ['rust']))->isEmpty());
    }

    public function testHistoryAloneIsSomethingToBuildFrom(): void
    {
        $history = new RecommendationHistoryModel(
            [],
            [],
            [new ArticleLineModel(7, 'Viewed', 'Feed', '2026-10-01', null)],
        );

        self::assertFalse((new ProfileInputsModel($history, []))->isEmpty());
    }
}
