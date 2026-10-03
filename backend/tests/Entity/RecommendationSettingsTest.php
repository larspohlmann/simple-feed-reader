<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\SealedSecret;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User('reader@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
    }

    public function testUpdateAndValuesRoundTripTheRecommendationFields(): void
    {
        $settings = new RecommendationSettings($this->user);

        $settings->update($this->values(favoritesCap: 33));

        self::assertTrue($settings->values()->showScoreAndReasons);
        self::assertSame(RecommendationBatchSize::Large, $settings->values()->batchSize);
        self::assertSame(33, $settings->values()->favoritesCap);
    }

    public function testANewRowDoesNotShowScoreAndReasonsByDefault(): void
    {
        self::assertFalse((new RecommendationSettings($this->user))->values()->showScoreAndReasons);
    }

    public function testANewRowUsesTheMediumBatchSizeByDefault(): void
    {
        $settings = new RecommendationSettings($this->user);

        self::assertSame(RecommendationBatchSize::Medium, $settings->values()->batchSize);
    }

    public function testANewRowHasNoProfileAndTheDefaultProfileSettings(): void
    {
        $settings = new RecommendationSettings($this->user);

        self::assertNull($settings->getStoredProfile()->getText());
        self::assertNull($settings->profileSettings()->intervalHours);
        self::assertNull($settings->profileSettings()->connection);
        self::assertSame(40, $settings->profileSettings()->keptCap);
        self::assertSame(80, $settings->profileSettings()->viewedCap);
    }

    public function testUpdatingTheRecommendationFieldsKeepsTheProfileSettings(): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->updateProfileSettings(new ProfileSettingsValues(12, null, 15, 25));

        $settings->update($this->values(favoritesCap: 33));

        self::assertSame(12, $settings->profileSettings()->intervalHours);
        self::assertSame(15, $settings->profileSettings()->keptCap);
        self::assertSame(25, $settings->profileSettings()->viewedCap);
    }

    public function testUpdatingTheProfileSettingsKeepsTheFavoritesCap(): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->update($this->values(favoritesCap: 33));

        $settings->updateProfileSettings(new ProfileSettingsValues(168, null, 15, 25));

        self::assertSame(33, $settings->values()->favoritesCap);
        self::assertSame(168, $settings->profileSettings()->intervalHours);
    }

    public function testStoreProfileReplacesTheWholeProfile(): void
    {
        $settings = new RecommendationSettings($this->user);
        $settings->storeProfile(
            new StoredProfile('old', new \DateTimeImmutable('2026-10-01 06:00:00'), 'a.test', 'm1'),
        );

        $settings->storeProfile(
            new StoredProfile('Likes databases.', new \DateTimeImmutable('2026-10-03 07:15:00'), 'b.test', 'm2'),
        );

        $stored = $settings->getStoredProfile();
        self::assertSame('Likes databases.', $stored->getText());
        self::assertSame('2026-10-03 07:15:00', $stored->getGeneratedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('b.test', $stored->getProviderHost());
        self::assertSame('m2', $stored->getModel());
    }

    public function testForgettingAConnectionClearsTheProfileConnectionOnlyWhenItIsThatOne(): void
    {
        $chosen = $this->connection('Chosen');
        $settings = new RecommendationSettings($this->user);
        $settings->updateProfileSettings(new ProfileSettingsValues(null, $chosen, 40, 80));

        $settings->forgetProfileConnection($this->connection('Other'));
        self::assertSame($chosen, $settings->profileSettings()->connection);

        $settings->forgetProfileConnection($chosen);
        self::assertNull($settings->profileSettings()->connection);
    }

    private function values(int $favoritesCap): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: 'stay on topic',
            favoritesCap: $favoritesCap,
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: 32768,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: false,
            autoGenerateIntervalHours: 12,
            showScoreAndReasons: true,
        );
    }

    private function connection(string $name): AiProviderSettings
    {
        return new AiProviderSettings(
            $this->user,
            $name,
            'https://llm.example.test/v1',
            new SealedSecret('ciphertext', 'nonce', 'salt', 1),
            'ab12',
            new \DateTimeImmutable('2026-10-01 06:00:00'),
        );
    }
}
