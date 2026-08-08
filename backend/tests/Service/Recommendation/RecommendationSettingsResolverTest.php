<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Entity\RecommendationSettings;
use App\Service\Ai\Crypto\ApiKeyCipher;
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

    private function seedAiSettingsWithModel(User $user, int $contextWindow): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $sealed = $cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, 'https://api.example.test/v1', $sealed, '1234', $now);
        $this->em->persist($settings);
        $settings->chooseModel('m', $now, $contextWindow);
        $this->em->flush();
    }

    private function resolver(): RecommendationSettingsResolver
    {
        /** @var RecommendationSettingsResolver $resolver */
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);

        return $resolver;
    }
}
