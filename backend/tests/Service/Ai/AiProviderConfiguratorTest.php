<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ProviderCredentials;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repository and entity manager, not mocks: the account id is
 * bound into the sealed key, so a User that was never flushed has no id to
 * seal for and the interesting cases could not run at all.
 *
 * Only the catalog is replaced — nothing here calls a provider.
 */
final class AiProviderConfiguratorTest extends DbTestCase
{
    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    /** @param list<string>|\Throwable $models */
    private function configurator(array|\Throwable $models): AiProviderConfigurator
    {
        self::getContainer()->set(ModelCatalog::class, new class ($models) implements ModelCatalog {
            /** @param list<string>|\Throwable $models */
            public function __construct(private readonly array|\Throwable $models)
            {
            }

            public function listModels(ProviderCredentials $credentials): array
            {
                if ($this->models instanceof \Throwable) {
                    throw $this->models;
                }

                return $this->models;
            }
        });

        /** @var AiProviderConfigurator $configurator */
        $configurator = self::getContainer()->get(AiProviderConfigurator::class);

        return $configurator;
    }

    public function testSavingAConnectionReturnsTheOfferedModels(): void
    {
        $configurator = $this->configurator(['gpt-4o', 'gpt-4o-mini']);
        $user = $this->user('cfg-save@example.test');

        $models = $configurator->saveConnection($user, 'https://api.example.test/v1/', 'sk-abcdef1234');

        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $models);
        $stored = $configurator->settingsFor($user);
        self::assertNotNull($stored);
        self::assertSame('https://api.example.test/v1', $stored->getBaseUrl());
        self::assertSame('1234', $stored->getApiKeyHint());
    }

    public function testTheStoredRowDoesNotContainThePlainKey(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-secret@example.test');

        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $stored = $configurator->settingsFor($user);
        self::assertNotNull($stored);
        $ciphertext = base64_decode($stored->getSealedApiKey()->ciphertext, true);
        self::assertIsString($ciphertext);
        self::assertStringNotContainsString('sk-abcdef1234', $ciphertext);
    }

    public function testARejectedKeyWritesNothing(): void
    {
        $configurator = $this->configurator(new CredentialsRejectedException('refused'));
        $user = $this->user('cfg-refused@example.test');

        try {
            $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
            self::fail('Expected CredentialsRejectedException.');
        } catch (CredentialsRejectedException) {
            $this->em->clear();
            $reloaded = $this->users()->findOneByEmail('cfg-refused@example.test');
            self::assertInstanceOf(User::class, $reloaded);
            self::assertNull($configurator->settingsFor($reloaded));
        }
    }

    public function testChoosingAnOfferedModelStoresIt(): void
    {
        $configurator = $this->configurator(['gpt-4o', 'gpt-4o-mini']);
        $user = $this->user('cfg-model@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $configurator->chooseModel($user, 'gpt-4o-mini');

        self::assertSame('gpt-4o-mini', $configurator->settingsFor($user)?->getModel());
    }

    public function testChoosingAModelTheProviderDoesNotOfferIsRefused(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-badmodel@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $this->expectException(ModelNotOfferedException::class);
        $configurator->chooseModel($user, 'gpt-4o-mini');
    }

    public function testASecondConnectionSaveDropsTheChosenModel(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-replace@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
        $configurator->chooseModel($user, 'gpt-4o');

        $configurator->saveConnection($user, 'https://other.example.test/v1', 'sk-zyxwvu9876');

        $stored = $configurator->settingsFor($user);
        self::assertNotNull($stored);
        self::assertNull($stored->getModel());
        self::assertSame('9876', $stored->getApiKeyHint());
    }

    public function testForgettingRemovesTheRow(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-forget@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $configurator->forget($user);

        // clear() first: after a remove, find() would otherwise serve the stale
        // identity map and the assertion would pass whatever the database did.
        $this->em->clear();
        $reloaded = $this->users()->findOneByEmail('cfg-forget@example.test');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertNull($configurator->settingsFor($reloaded));
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }
}
