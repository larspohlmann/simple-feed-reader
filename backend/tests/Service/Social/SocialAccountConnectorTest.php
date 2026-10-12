<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Exception\SocialAccountAlreadyConnectedException;
use App\Service\Social\Exception\SocialAccountMismatchException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\TooManySocialAccountsException;
use App\Service\Social\Factory\SocialAccountFactory;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use App\Service\Social\SocialAccountConnector;
use App\Tests\DbTestCase;
use App\Tests\Support\StubAccountVerifier;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialAccountConnectorTest extends DbTestCase
{
    private const string APP_PASSWORD = 'abcd-efgh-ijkl-mnop';

    private User $user;
    private SocialCredentialCipher $cipher;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-connector@example.test');
        $this->cipher = new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('k', 32)));
        $this->clock = new MockClock('2026-10-12 10:00:00 UTC');
    }

    public function testTheFirstAccountOnANetworkBecomesItsDefault(): void
    {
        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());

        self::assertTrue($account->isDefault());
        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        self::assertSame('2026-10-12 10:00:00', $account->getConnectedAt()->format('Y-m-d H:i:s'));
    }

    public function testASecondAccountOnTheSameNetworkIsNotTheDefault(): void
    {
        $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());
        $second = $this->connector($this->bluesky('did:plc:bob'))->connect($this->user, $this->blueskyCredentials());

        self::assertFalse($second->isDefault());
    }

    public function testAnAccountOnTheOtherNetworkIsItsOwnNetworksDefault(): void
    {
        $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());
        $verified = new VerifiedAccountModel(
            'alice@mastodon.example',
            '@alice@mastodon.example',
            'https://mastodon.example',
        );
        $mastodon = $this->connector(new StubAccountVerifier($verified))->connect(
            $this->user,
            SocialCredentialsModel::mastodon('mastodon.example', 'token-1234'),
        );

        self::assertTrue($mastodon->isDefault());
    }

    public function testTheSecretIsStoredSealedAndOpensToWhatWasTyped(): void
    {
        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());

        self::assertSame(
            self::APP_PASSWORD,
            $this->cipher->open($this->user->requireId(), $account->getSealedCredential()),
        );
        self::assertNotSame(self::APP_PASSWORD, $account->getSealedCredential()->ciphertext);
        self::assertSame('mnop', $account->getCredentialHint());
    }

    public function testConnectingTheSameAccountTwiceIsRefused(): void
    {
        $connector = $this->connector($this->bluesky('did:plc:alice'));
        $connector->connect($this->user, $this->blueskyCredentials());

        try {
            $connector->connect($this->user, $this->blueskyCredentials());
            self::fail('Expected SocialAccountAlreadyConnectedException');
        } catch (SocialAccountAlreadyConnectedException) {
            self::assertSame(1, $this->repository()->countForUser($this->user));
        }
    }

    public function testTheTwentyFirstAccountIsRefusedBeforeAnyNetworkCall(): void
    {
        for ($index = 0; $index < 20; ++$index) {
            $this->entityManager->persist($this->storedAccount('did:plc:stored' . $index));
        }
        $this->entityManager->flush();
        $connector = $this->connector(new StubAccountVerifier(new \LogicException('The network must not be called.')));

        $this->expectException(TooManySocialAccountsException::class);

        $connector->connect($this->user, $this->blueskyCredentials());
    }

    public function testARejectedCredentialStoresNothing(): void
    {
        $connector = $this->connector(new StubAccountVerifier(new SocialCredentialsRejectedException('Rejected.')));

        try {
            $connector->connect($this->user, $this->blueskyCredentials());
            self::fail('Expected SocialCredentialsRejectedException');
        } catch (SocialCredentialsRejectedException) {
            self::assertSame(0, $this->repository()->countForUser($this->user));
        }
    }

    public function testReconnectReplacesTheSecretAndClearsNeedsReconnect(): void
    {
        $account = $this->storedAccount('did:plc:alice');
        $account->markNeedsReconnect();
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $verified = new VerifiedAccountModel('did:plc:alice', '@alice.renamed.social', 'https://bsky.social');

        $this->connector(new StubAccountVerifier($verified))->reconnect($account, 'wxyz-wxyz-wxyz-9999');

        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        self::assertSame('9999', $account->getCredentialHint());
        self::assertSame('@alice.renamed.social', $account->getHandle());
        self::assertSame(
            'wxyz-wxyz-wxyz-9999',
            $this->cipher->open($this->user->requireId(), $account->getSealedCredential()),
        );
    }

    public function testReconnectSendsTheStoredIdentityAsTheAddress(): void
    {
        $account = $this->storedAccount('did:plc:alice');
        $this->entityManager->persist($account);
        $stub = $this->bluesky('did:plc:alice');

        $this->connector($stub)->reconnect($account, 'wxyz-wxyz-wxyz-9999');

        self::assertSame('did:plc:alice', $stub->lastCredentials?->address);
    }

    public function testReconnectWithAnotherAccountsCredentialIsRefused(): void
    {
        $account = $this->storedAccount('did:plc:alice');
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        try {
            $this->connector($this->bluesky('did:plc:mallory'))->reconnect($account, 'wxyz-wxyz-wxyz-9999');
            self::fail('Expected SocialAccountMismatchException');
        } catch (SocialAccountMismatchException) {
            self::assertSame('mnop', $account->getCredentialHint());
        }
    }

    private function connector(StubAccountVerifier $verifier): SocialAccountConnector
    {
        return new SocialAccountConnector(
            $verifier,
            $this->repository(),
            new SocialAccountFactory($this->cipher, $this->clock),
            $this->cipher,
            $this->clock,
            $this->entityManager,
        );
    }

    private function bluesky(string $did): StubAccountVerifier
    {
        return new StubAccountVerifier(new VerifiedAccountModel($did, '@' . $did, 'https://bsky.social'));
    }

    private function blueskyCredentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::bluesky('alice.bsky.social', self::APP_PASSWORD);
    }

    private function storedAccount(string $identity): SocialAccount
    {
        return new SocialAccount(
            $this->user,
            SocialNetwork::Bluesky,
            $identity,
            '@' . $identity,
            'https://bsky.social',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable('2026-10-12 09:00:00'),
        );
    }

    private function repository(): SocialAccountRepository
    {
        /** @var SocialAccountRepository $repository */
        $repository = $this->entityManager->getRepository(SocialAccount::class);

        return $repository;
    }
}
