<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Tests\Support\AiProviderSettingsFactory;
use PHPUnit\Framework\TestCase;

/** User holds only the active configuration; AiProviderSettingsRepository lists all of an account's configurations. */
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
