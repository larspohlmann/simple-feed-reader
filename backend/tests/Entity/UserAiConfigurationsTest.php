<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\SealedApiKey;
use PHPUnit\Framework\TestCase;

final class UserAiConfigurationsTest extends TestCase
{
    private function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-08-09 09:00:00'));
    }

    private function configuration(User $user, string $name): AiProviderSettings
    {
        return new AiProviderSettings(
            $user,
            $name,
            'https://api.example.com/v1',
            new SealedApiKey('c', 'n', 's', 1),
            'ab12',
            new \DateTimeImmutable('2026-08-09T09:00:00Z'),
        );
    }

    public function testANewAccountHasNoConfigurations(): void
    {
        self::assertCount(0, $this->user()->getAiProviderSettings());
    }

    public function testAddingAConfigurationMakesItAppearInTheCollection(): void
    {
        $user = $this->user();
        $configuration = $this->configuration($user, 'Work OpenAI');

        $user->addAiProviderSettings($configuration);

        self::assertCount(1, $user->getAiProviderSettings());
        self::assertTrue($user->getAiProviderSettings()->contains($configuration));
    }

    public function testAddingTheSameConfigurationTwiceDoesNotDuplicateIt(): void
    {
        $user = $this->user();
        $configuration = $this->configuration($user, 'Work OpenAI');

        $user->addAiProviderSettings($configuration);
        $user->addAiProviderSettings($configuration);

        self::assertCount(1, $user->getAiProviderSettings());
    }

    public function testRemovingAConfigurationDropsItFromTheCollection(): void
    {
        $user = $this->user();
        $configuration = $this->configuration($user, 'Work OpenAI');
        $user->addAiProviderSettings($configuration);

        $user->removeAiProviderSettings($configuration);

        self::assertCount(0, $user->getAiProviderSettings());
    }

    public function testANewAccountHasNoActiveConfiguration(): void
    {
        self::assertNull($this->user()->getActiveAiProviderSettings());
    }

    public function testSettingTheActiveConfigurationRoundTrips(): void
    {
        $user = $this->user();
        $configuration = $this->configuration($user, 'Work OpenAI');
        $user->addAiProviderSettings($configuration);

        $user->setActiveAiProviderSettings($configuration);

        self::assertSame($configuration, $user->getActiveAiProviderSettings());
    }

    public function testClearingTheActiveConfigurationRoundTrips(): void
    {
        $user = $this->user();
        $configuration = $this->configuration($user, 'Work OpenAI');
        $user->addAiProviderSettings($configuration);
        $user->setActiveAiProviderSettings($configuration);

        $user->setActiveAiProviderSettings(null);

        self::assertNull($user->getActiveAiProviderSettings());
    }
}
