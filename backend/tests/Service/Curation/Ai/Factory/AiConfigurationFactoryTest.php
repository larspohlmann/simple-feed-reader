<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Factory;

use App\Service\Ai\Factory\AiConfigurationFactory;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class AiConfigurationFactoryTest extends DbTestCase
{
    use SeedsUsers;

    private const string KEY = 'sk-abcdef1234';

    public function testANewConfigurationShowsTheKeysLastFourCharacters(): void
    {
        $configuration = $this->factory()->create($this->user('ai-hint@example.test'), 'Mine', $this->credentials());

        self::assertSame('1234', $configuration->getApiKeyHint());
        self::assertSame('Mine', $configuration->getName());
    }

    public function testACopyIsNamedAfterItsSourceWithinTheColumn(): void
    {
        $user = $this->user('ai-copy@example.test');
        $source = $this->factory()->create($user, str_repeat('n', 120), $this->credentials());

        $copy = $this->factory()->duplicate($source, $this->credentials());

        self::assertSame('Copy of ' . str_repeat('n', 112), $copy->getName());
    }

    public function testACopyOfAnUnnamedConfigurationIsNamedCopy(): void
    {
        $user = $this->user('ai-unnamed@example.test');
        $source = $this->factory()->create($user, null, $this->credentials());

        self::assertSame('Copy', $this->factory()->duplicate($source, $this->credentials())->getName());
    }

    public function testACopyKeepsTheSourcesVerifiedAtRatherThanTheCurrentTime(): void
    {
        $user = $this->user('ai-verified@example.test');
        $source = $this->factory()->create($user, 'Mine', $this->credentials());
        $verifiedAt = $source->getVerifiedAt();

        $copy = $this->factory()->duplicate($source, $this->credentials());

        self::assertSame($verifiedAt, $copy->getVerifiedAt());
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromAccountInput('https://api.example.test/v1', self::KEY);
    }

    private function factory(): AiConfigurationFactory
    {
        /** @var AiConfigurationFactory $factory */
        $factory = self::getContainer()->get(AiConfigurationFactory::class);

        return $factory;
    }
}
