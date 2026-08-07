<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ModelDescriptor;
use App\Tests\DbTestCase;
use App\Tests\Support\StubModelCatalog;
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

    /** @param list<string|ModelDescriptor>|\Throwable $models */
    private function configurator(array|\Throwable $models): AiProviderConfigurator
    {
        self::getContainer()->set(ModelCatalog::class, new StubModelCatalog($models));

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

    public function testAFirstSaveStampsTheVerificationTime(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-verified@example.test');

        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        // clear() first: the assertion has to read the stamped column back out
        // of the database, not off the entity this test already holds.
        $this->em->clear();
        $verifiedAt = $configurator->settingsFor($this->reload('cfg-verified@example.test'))?->getVerifiedAt();
        self::assertNotNull($verifiedAt);
        self::assertSame('UTC', $verifiedAt->getTimezone()->getName());
        self::assertLessThan(60, abs($verifiedAt->getTimestamp() - time()));
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

        $configurator->chooseModel($configurator->requireConfiguration($user), 'gpt-4o-mini');

        // clear() first: without it the identity map serves the entity this
        // test already holds, and the assertion would pass even if nothing was
        // ever written to the database.
        $this->em->clear();
        $stored = $configurator->settingsFor($this->reload('cfg-model@example.test'));
        self::assertSame('gpt-4o-mini', $stored?->getModel());
    }

    public function testChoosingAModelStoresItsReportedContextWindow(): void
    {
        $configurator = $this->configurator([new ModelDescriptor('big', 200000), new ModelDescriptor('small', null)]);
        $user = $this->user('cfg-context-window@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
        $settings = $configurator->requireConfiguration($user);

        $configurator->chooseModel($settings, 'big');

        self::assertSame(200000, $settings->getModelContextWindow());
    }

    public function testReplacingTheConnectionClearsTheContextWindow(): void
    {
        $configurator = $this->configurator([new ModelDescriptor('big', 200000), new ModelDescriptor('small', null)]);
        $user = $this->user('cfg-context-clear@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
        $settings = $configurator->requireConfiguration($user);
        $configurator->chooseModel($settings, 'big');

        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-other-key987');

        self::assertNull($settings->getModelContextWindow());
    }

    public function testChoosingAModelTheProviderDoesNotOfferIsRefused(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-badmodel@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $this->expectException(ModelNotOfferedException::class);
        $configurator->chooseModel($configurator->requireConfiguration($user), 'gpt-4o-mini');
    }

    /**
     * credentials() is public on purpose: a later caller that must talk to the
     * provider directly (a prompt runner) reuses it instead of duplicating the
     * cipher call. Called from here, outside the class, so a visibility
     * regression back to private/protected fails this test rather than only
     * a caller several tasks from now.
     */
    public function testCredentialsCanBeOpenedFromOutsideTheConfigurator(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-public-credentials@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        $credentials = $configurator->credentials($configurator->requireConfiguration($user));

        self::assertSame('https://api.example.test/v1', $credentials->baseUrl);
        self::assertSame('sk-abcdef1234', $credentials->apiKey);
    }

    public function testASecondConnectionSaveDropsTheChosenModel(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-replace@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
        $configurator->chooseModel($configurator->requireConfiguration($user), 'gpt-4o');

        $configurator->saveConnection($user, 'https://other.example.test/v1', 'sk-zyxwvu9876');

        // clear() first, for the same reason as above: the assertion has to
        // read the row back, not the entity this test is holding.
        $this->em->clear();
        $stored = $configurator->settingsFor($this->reload('cfg-replace@example.test'));
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

    public function testTheUserItWasGivenReportsTheNewConnectionAtOnce(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-inverse-save@example.test');

        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');

        // No clear() and no reload on purpose: within one request a caller
        // holds the User it passed in, and MeJson reads the association off it.
        self::assertSame($configurator->settingsFor($user), $user->getAiProviderSettings());
    }

    public function testTheUserItWasGivenReportsTheForgottenProviderAtOnce(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $user = $this->user('cfg-inverse-forget@example.test');
        $configurator->saveConnection($user, 'https://api.example.test/v1', 'sk-abcdef1234');
        self::assertNotNull($user->getAiProviderSettings());

        $configurator->forget($user);

        self::assertNull($user->getAiProviderSettings());
    }

    public function testAStoredKeyCannotBeOpenedUnderAnotherAccount(): void
    {
        $configurator = $this->configurator(['gpt-4o']);
        $owner = $this->user('cfg-owner@example.test');
        $thief = $this->user('cfg-thief@example.test');
        $configurator->saveConnection($owner, 'https://api.example.test/v1', 'sk-abcdef1234');

        $this->moveSettingsRow($owner, $thief);

        // The row is now the thief's, so the configurator seals/opens it under
        // the thief's id — which is not the id the key was bound to.
        $thiefSettings = $configurator->requireConfiguration($this->reload('cfg-thief@example.test'));

        $this->expectException(ApiKeyUnreadableException::class);
        $configurator->listModels($thiefSettings);
    }

    private function moveSettingsRow(User $from, User $to): void
    {
        $this->em->createQuery(
            sprintf('UPDATE %s s SET s.user = :to WHERE s.user = :from', AiProviderSettings::class),
        )->execute(['to' => $to, 'from' => $from]);
        $this->em->clear();
    }

    private function reload(string $email): User
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }
}
