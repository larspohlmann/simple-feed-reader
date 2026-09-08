<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Service\Recommendation\RecommendationBatchSize;
use App\Service\Recommendation\RecommendationSettingsValues;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User('reader@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
    }

    public function testUpdateAndValuesRoundTripShowReasons(): void
    {
        $settings = new RecommendationSettings($this->user);

        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: 'stay on topic',
            favoritesCap: 40,
            keptCap: 40,
            viewedCap: 80,
            candidatePoolSize: 500,
            lookbackDays: 2,
            picksLimit: 50,
            contextWindow: 32768,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: false,
            autoGenerateIntervalHours: 12,
            profileText: 'reads about databases',
            showReasons: true,
        ));

        self::assertTrue($settings->values()->showReasons);
        self::assertSame(RecommendationBatchSize::Large, $settings->values()->batchSize);
    }

    public function testANewRowDoesNotShowReasonsByDefault(): void
    {
        $settings = new RecommendationSettings($this->user);

        self::assertFalse($settings->values()->showReasons);
    }

    public function testANewRowUsesTheMediumBatchSizeByDefault(): void
    {
        $settings = new RecommendationSettings($this->user);

        self::assertSame(RecommendationBatchSize::Medium, $settings->values()->batchSize);
    }
}
