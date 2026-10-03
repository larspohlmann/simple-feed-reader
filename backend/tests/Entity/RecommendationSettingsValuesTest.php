<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettingsValues;
use App\Enum\RecommendationBatchSize;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsValuesTest extends TestCase
{
    public function testShowScoreAndReasonsDefaultsToFalse(): void
    {
        $values = new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: 10,
            poolLimits: new RecommendationPoolLimits(400, 3, 25),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        );

        self::assertFalse($values->showScoreAndReasons);
    }
}
