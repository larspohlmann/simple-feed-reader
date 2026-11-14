<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Settings;

use App\Entity\AiProviderSettings;
use App\Entity\ModelDescriptor;
use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Enum\ScoringProtocol;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Settings\Model\RecommendationPackingSettingsModel;
use App\Service\Recommendation\Settings\RecommendationSettingsResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationSettingsResolverTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('recommendation-settings@example.test');
    }

    public function testAllDefaultsWhenNoRowAndNoProviderWindow(): void
    {
        $effective = $this->resolver()->forUser($this->user);

        self::assertNull($effective->guidancePrompt);
        self::assertSame(40, $effective->historyCaps->favorites);
        self::assertSame(40, $effective->historyCaps->kept);
        self::assertSame(80, $effective->historyCaps->viewed);
        self::assertSame(500, $effective->poolLimits->candidatePoolSize);
        self::assertSame(50, $effective->poolLimits->picksLimit);
        self::assertSame(32768, $effective->packing->contextWindow);
        self::assertSame('fallback', $effective->packing->contextWindowSource);
        self::assertSame(RecommendationBatchSize::Medium, $effective->packing->batchSize);
        self::assertFalse($effective->debugEnabled);
    }

    public function testProviderReportedWindowBeatsTheFallback(): void
    {
        $this->seedAiSettingsWithModel($this->user, contextWindow: 200000);

        $effective = $this->resolver()->forUser($this->user);

        self::assertSame(200000, $effective->packing->contextWindow);
        self::assertSame('provider', $effective->packing->contextWindowSource);
    }

    public function testUserOverrideBeatsTheProviderWindow(): void
    {
        $this->seedAiSettingsWithModel($this->user, contextWindow: 200000);
        $row = new RecommendationSettings($this->user);
        $row->update(new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            favoritesCap: 10,
            poolLimits: new RecommendationPoolLimits(400, RecommendationSettings::DEFAULT_LOOKBACK_DAYS, 50),
            contextWindow: 65536,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: true,
        ));
        $row->updateProfileSettings(new ProfileSettingsValues(null, null, 20, 30));
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        $effective = $this->resolver()->forUser($this->user);

        self::assertSame('Only cats.', $effective->guidancePrompt);
        self::assertSame(10, $effective->historyCaps->favorites);
        self::assertSame(20, $effective->historyCaps->kept);
        self::assertSame(30, $effective->historyCaps->viewed);
        self::assertSame(400, $effective->poolLimits->candidatePoolSize);
        self::assertSame(50, $effective->poolLimits->picksLimit);
        self::assertSame(65536, $effective->packing->contextWindow);
        self::assertSame('user', $effective->packing->contextWindowSource);
        self::assertSame(RecommendationBatchSize::Large, $effective->packing->batchSize);
        self::assertTrue($effective->debugEnabled);
    }

    /**
     * A connection with no cap set makes no claim about batch size, so the
     * shared default stands.
     */
    public function testAConnectionWithNoCapKeepsTheDefaultBatchCeiling(): void
    {
        $this->seedAiSettingsWithModel($this->user, contextWindow: 200000);

        self::assertSame(
            RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            $this->resolver()->forUser($this->user)->packing->maximumBatchSize,
        );
    }

    /**
     * The batch ceiling follows the connection's own cap: how long a list a model holds in order is a property of the
     * endpoint, not of the account's taste.
     */
    public function testAConnectionWithACapPacksToThatCap(): void
    {
        $this->seedAiSettingsWithModel($this->user, contextWindow: 200000, maxBatchSize: 30);

        self::assertSame(
            30,
            $this->resolver()->forUser($this->user)->packing->maximumBatchSize,
        );
    }

    /**
     * With no configuration at all there is no connection to read a ceiling
     * from, and the default is what the packer gets.
     */
    public function testTheBatchCeilingFallsBackWithNoConfiguration(): void
    {
        self::assertSame(
            RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            $this->resolver()->forUser($this->user)->packing->maximumBatchSize,
        );
    }

    /** `slow_model` governs timeouts alone: a slow connection with no cap of its own keeps the default ceiling. */
    public function testAConnectionMarkedSlowWithNoCapKeepsTheDefaultBatchCeiling(): void
    {
        $this->seedAiSettingsWithModel($this->user, contextWindow: 200000);
        $provider = $this->user->getActiveAiProviderSettings();
        self::assertNotNull($provider);
        $provider->setSlowModel(true);
        $this->entityManager->flush();

        self::assertSame(
            RecommendationPackingSettingsModel::DEFAULT_MAXIMUM_BATCH_SIZE,
            $this->resolver()->forUser($this->user)->packing->maximumBatchSize,
        );
    }

    /** A profile run sizes its history by the profile connection, not the active one. */
    public function testForAConnectionTheWindowAndTheCeilingAreThatConnections(): void
    {
        $active = AiProviderSettingsFactory::build($this->user);
        $active->chooseModel(
            new ModelDescriptor('jev-latest', 32_000, ScoringProtocol::SystemOne),
            new \DateTimeImmutable('2026-10-02 09:00:00'),
        );
        $profile = AiProviderSettingsFactory::build($this->user, 'Profile', 'https://profile.example.test/v1');
        $profile->chooseModel(new ModelDescriptor('gpt-4o', 128_000), new \DateTimeImmutable('2026-10-02 09:00:00'));
        $profile->setMaxBatchSize(30);
        $this->entityManager->persist($active);
        $this->entityManager->persist($profile);
        $this->user->setActiveAiProviderSettings($active);
        $this->entityManager->flush();

        $forProfile = $this->resolver()->forAccount($this->user)->forConnection($profile);

        self::assertSame(128_000, $forProfile->packing->contextWindow);
        self::assertSame(30, $forProfile->packing->maximumBatchSize);
        self::assertSame(32_000, $this->resolver()->forUser($this->user)->packing->contextWindow);
    }

    public function testShowScoreAndReasonsDefaultsToFalseWhenNoRowExists(): void
    {
        $settings = $this->resolver()->forUser($this->userWithoutSettingsRow());

        self::assertFalse($settings->showScoreAndReasons);
    }

    public function testShowScoreAndReasonsIsReadFromTheSettingsRow(): void
    {
        $this->settingsRowFor($this->user, showScoreAndReasons: true);

        $settings = $this->resolver()->forUser($this->user);

        self::assertTrue($settings->showScoreAndReasons);
    }

    private function userWithoutSettingsRow(): User
    {
        return $this->user;
    }

    private function settingsRowFor(
        User $user,
        bool $showScoreAndReasons = false,
    ): RecommendationSettings {
        $row = new RecommendationSettings($user);
        $row->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: RecommendationSettings::DEFAULT_FAVORITES_CAP,
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
            showScoreAndReasons: $showScoreAndReasons,
        ));
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }

    private function seedAiSettingsWithModel(User $user, int $contextWindow, ?int $maxBatchSize = null): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $sealed = $cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, null, 'https://api.example.test/v1', $sealed, '1234', $now);
        $this->entityManager->persist($settings);
        $settings->chooseModel(new ModelDescriptor('m', $contextWindow), $now);
        $settings->setMaxBatchSize($maxBatchSize);
        $user->setActiveAiProviderSettings($settings);
        $this->entityManager->flush();
    }

    private function resolver(): RecommendationSettingsResolver
    {
        /** @var RecommendationSettingsResolver $resolver */
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);

        return $resolver;
    }
}
