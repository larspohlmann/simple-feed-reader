<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;

/** An account with a stored profile and a connection that can read it, as the interest-term refresh tests need. */
trait SeedsProfiledAccounts
{
    private function fixtures(): RecommendationRunFixtures
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);

        return new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    private function profileGeneratedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-03 06:00:00');
    }

    private function profiledAccount(string $email): User
    {
        $user = $this->user($email);
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');
        $this->fixtures()->storeProfile($user, 'Likes solar power.');

        return $user;
    }

    private function requireConnection(User $user): AiProviderSettings
    {
        $connection = $this->entityManager->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(AiProviderSettings::class, $connection);

        return $connection;
    }
}
