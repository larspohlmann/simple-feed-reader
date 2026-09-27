<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationSettingsValues;
use App\Enum\RecommendationBatchSize;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsValuesTest extends TestCase
{
    public function testShowReasonsDefaultsToFalse(): void
    {
        $values = new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: 10,
            keptCap: 20,
            viewedCap: 30,
            candidatePoolSize: 400,
            lookbackDays: 3,
            picksLimit: 25,
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
        );

        self::assertFalse($values->showReasons);
    }
}
