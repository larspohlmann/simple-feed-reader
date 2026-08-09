<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

/**
 * User <-> AiProviderSettings is unidirectional (#334 review): User carries
 * only the pointer to whichever configuration is active, not an inverse
 * Collection of every configuration it owns — nothing needed the whole set
 * through User, and AiProviderSettingsRepository already answers that
 * (findAllForUser()/countForUser()).
 */
final class UserAiConfigurationsTest extends TestCase
{
    private function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-08-09 09:00:00'));
    }

    private function configuration(User $user, string $name): AiProviderSettings
    {
        return AiProviderSettingsFactory::build($user, $name);
    }

    public function testANewAccountHasNoActiveConfiguration(): void
    {
        self::assertNull($this->user()->getActiveAiProviderSettings());
    }

    public function testSettingTheActiveConfigurationRoundTrips(): void
    {
        $user = $this->user();
        $configuration = $this->configuration($user, 'Work OpenAI');

        $user->setActiveAiProviderSettings($configuration);

        self::assertSame($configuration, $user->getActiveAiProviderSettings());
    }

    public function testClearingTheActiveConfigurationRoundTrips(): void
    {
        $user = $this->user();
        $configuration = $this->configuration($user, 'Work OpenAI');
        $user->setActiveAiProviderSettings($configuration);

        $user->setActiveAiProviderSettings(null);

        self::assertNull($user->getActiveAiProviderSettings());
    }
}
