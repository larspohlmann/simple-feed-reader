<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\SeedsUsers;

final class AiConfigurationForUserTest extends DbTestCase
{
    use SeedsUsers;

    private function persistConfiguration(User $user): AiProviderSettings
    {
        $configuration = AiProviderSettingsFactory::build($user);
        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return $configuration;
    }

    private function resolver(): AiConfigurationForUser
    {
        /** @var AiConfigurationForUser $resolver */
        $resolver = self::getContainer()->get(AiConfigurationForUser::class);

        return $resolver;
    }

    public function testRequireReturnsARowTheAccountOwns(): void
    {
        $owner = $this->user('resolver-owner@example.test');
        $configuration = $this->persistConfiguration($owner);
        $ownedId = $configuration->getId();
        self::assertNotNull($ownedId);

        $found = $this->resolver()->require($owner, $ownedId);

        self::assertSame($configuration, $found);
    }

    public function testRequireRefusesAnotherAccountsRow(): void
    {
        $owner = $this->user('resolver-stranger-owner@example.test');
        $stranger = $this->user('resolver-stranger@example.test');
        $configuration = $this->persistConfiguration($owner);
        $strangerId = $configuration->getId();
        self::assertNotNull($strangerId);

        $this->expectException(ConfigurationNotFoundException::class);
        $this->resolver()->require($stranger, $strangerId);
    }

    public function testRequireRefusesAnIdThatDoesNotExist(): void
    {
        $owner = $this->user('resolver-missing@example.test');

        $this->expectException(ConfigurationNotFoundException::class);
        $this->resolver()->require($owner, 999999);
    }
}
