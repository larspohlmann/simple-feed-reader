<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationPackingSettings;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repositories and entity manager, not mocks: the resolver's
 * job is to combine two rows that may or may not exist, and a mock would have
 * to encode that combination logic itself instead of proving it.
 */
final class RecommendationSettingsResolverTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('recommendation-settings@example.test');
    }

    public function testAllDefaultsWhenNoRowAndNoProviderWindow(): void
    {
        $effective = $this->resolver()->forUser($this->user);

        self::assertNull($effective->guidancePrompt);
        self::assertSame(40, $effective->favoritesCap);
        self::assertSame(40, $effective->keptCap);
        self::assertSame(80, $effective->viewedCap);
        self::assertSame(500, $effective->candidatePoolSize);
        self::assertSame(50, $effective->picksLimit);
        self::assertSame(32768, $effective->packing->contextWindow);
        self::assertSame('fallback', $effective->packing->contextWindowSource);
        self::assertNull($effective->packing->batchCount);
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
            keptCap: 20,
            viewedCap: 30,
            candidatePoolSize: 500,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: 50,
            contextWindow: 65536,
            batchCount: 12,
            debugEnabled: true,
        ));
        $this->em->persist($row);
        $this->em->flush();

        $effective = $this->resolver()->forUser($this->user);

        self::assertSame('Only cats.', $effective->guidancePrompt);
        self::assertSame(10, $effective->favoritesCap);
        self::assertSame(20, $effective->keptCap);
        self::assertSame(30, $effective->viewedCap);
        self::assertSame(500, $effective->candidatePoolSize);
        self::assertSame(50, $effective->picksLimit);
        self::assertSame(65536, $effective->packing->contextWindow);
        self::assertSame('user', $effective->packing->contextWindowSource);
        self::assertSame(12, $effective->packing->batchCount);
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
            RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
            $this->resolver()->forUser($this->user)->packing->maximumBatchSize,
        );
    }

    /**
     * The batch ceiling follows the connection's own cap, because how long a
     * list a model holds in order is a property of the endpoint, not of the
     * account's taste (#437).
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
            RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
            $this->resolver()->forUser($this->user)->packing->maximumBatchSize,
        );
    }

    /**
     * The regression test for #445: `slow_model` used to double as the batch
     * ceiling's switch. Now it governs timeouts alone, so a connection marked
     * slow with no cap of its own still gets the default ceiling.
     */
    public function testAConnectionMarkedSlowWithNoCapKeepsTheDefaultBatchCeiling(): void
    {
        $this->seedAiSettingsWithModel($this->user, contextWindow: 200000);
        $provider = $this->user->getActiveAiProviderSettings();
        self::assertNotNull($provider);
        $provider->setSlowModel(true);
        $this->em->flush();

        self::assertSame(
            RecommendationPackingSettings::DEFAULT_MAXIMUM_BATCH_SIZE,
            $this->resolver()->forUser($this->user)->packing->maximumBatchSize,
        );
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
        $this->em->persist($settings);
        $settings->chooseModel('m', $now, $contextWindow);
        $settings->setMaxBatchSize($maxBatchSize);
        $user->setActiveAiProviderSettings($settings);
        $this->em->flush();
    }

    private function resolver(): RecommendationSettingsResolver
    {
        /** @var RecommendationSettingsResolver $resolver */
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);

        return $resolver;
    }
}
