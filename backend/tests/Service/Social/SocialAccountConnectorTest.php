<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
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
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\StubAccountVerifier;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
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
            self::assertTrue($this->entityManager->isOpen());
            self::assertSame(1, $this->repository()->countForUser($this->user));
        }
    }

    public function testAConnectRacedByAnIdenticalOneIsRefusedAsAlreadyConnected(): void
    {
        $eventManager = $this->entityManager->getEventManager();
        $eventManager->addEventListener(Events::onFlush, $this->racingConnect('did:plc:alice'));

        $this->expectException(SocialAccountAlreadyConnectedException::class);

        $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());
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

        $accountId = $account->requireId();

        $this->reconnect(new StubAccountVerifier($verified), $account);
        $this->entityManager->clear();

        $reloaded = $this->repository()->find($accountId);
        self::assertNotNull($reloaded);
        self::assertSame(SocialAccountStatus::Connected, $reloaded->getStatus());
        self::assertSame('9999', $reloaded->getCredentialHint());
        self::assertSame('@alice.renamed.social', $reloaded->getHandle());
        self::assertSame(
            'wxyz-wxyz-wxyz-9999',
            $this->cipher->open($this->user->requireId(), $reloaded->getSealedCredential()),
        );
    }

    public function testTheConnectionTimeIsStoredInUtcWhateverTheClockZone(): void
    {
        $this->clock = new MockClock('2026-10-12 12:00:00 Europe/Berlin');

        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());

        self::assertSame('2026-10-12 10:00:00', $account->getConnectedAt()->format('Y-m-d H:i:s'));
    }

    public function testReconnectStoresTheNewTimeInUtcAndPersistsIt(): void
    {
        $account = $this->storedAccount('did:plc:alice');
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $accountId = $account->requireId();
        $this->clock = new MockClock('2026-10-12 12:00:00 Europe/Berlin');

        $this->reconnect($this->bluesky('did:plc:alice'), $account);
        $this->entityManager->clear();

        $reloaded = $this->repository()->find($accountId);
        self::assertNotNull($reloaded);
        self::assertSame('9999', $reloaded->getCredentialHint());
        self::assertSame('2026-10-12 10:00:00', $reloaded->getConnectedAt()->format('Y-m-d H:i:s'));
    }

    public function testReconnectStoresAPdsTheAccountMovedTo(): void
    {
        $account = $this->storedAccount('did:plc:alice');
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $accountId = $account->requireId();
        $verified = new VerifiedAccountModel('did:plc:alice', '@did:plc:alice', 'https://pds.moved.example.test');

        $this->reconnect(new StubAccountVerifier($verified), $account);
        $this->entityManager->clear();

        self::assertSame('https://pds.moved.example.test', $this->repository()->find($accountId)?->getServiceUrl());
    }

    public function testReconnectSendsTheStoredIdentityAsTheAddress(): void
    {
        $account = $this->storedAccount('did:plc:alice');
        $this->entityManager->persist($account);
        $stub = $this->bluesky('did:plc:alice');

        $this->reconnect($stub, $account);

        self::assertSame('did:plc:alice', $stub->lastCredentials?->address);
    }

    public function testReconnectWithAnotherAccountsCredentialIsRefused(): void
    {
        $account = $this->storedAccount('did:plc:alice');
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        try {
            $this->reconnect($this->bluesky('did:plc:mallory'), $account);
            self::fail('Expected SocialAccountMismatchException');
        } catch (SocialAccountMismatchException) {
            self::assertSame('mnop', $account->getCredentialHint());
        }
    }

    public function testConnectingCreatesTheAccountsHomeTimelineFeed(): void
    {
        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());

        $home = SocialFeeds::repository($this->entityManager)->findOneFor($account, SocialFeedKind::Home, null);
        self::assertNotNull($home);
        self::assertSame(sprintf('social:bluesky:%d:home', $account->requireId()), $home->getFeed()->getUrl());
    }

    public function testAnAccountAtItsSubscriptionCapStillConnectsWithoutAHomeFeed(): void
    {
        $this->user->setMaxSubscriptions(0);
        $this->entityManager->flush();

        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());

        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        $socialFeeds = SocialFeeds::repository($this->entityManager);
        self::assertNull($socialFeeds->findOneFor($account, SocialFeedKind::Home, null));
    }

    public function testReconnectingMakesTheAccountsPrivateFeedsDueAgain(): void
    {
        $account = $this->connector($this->bluesky('did:plc:alice'))->connect($this->user, $this->blueskyCredentials());
        $socialFeeds = SocialFeeds::repository($this->entityManager);
        $feed = $socialFeeds->findOneFor($account, SocialFeedKind::Home, null)?->getFeed();
        self::assertNotNull($feed);
        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2026-10-13 10:00:00'));
        $account->markNeedsReconnect();
        $this->entityManager->flush();

        $this->reconnect($this->bluesky('did:plc:alice'), $account);
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Feed::class, $feed->requireId());
        self::assertSame('2026-10-12 10:00:00', $reloaded?->getNextFetchAt()?->format('Y-m-d H:i:s'));
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
            PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock),
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

    private function racingConnect(string $identity): object
    {
        $connection = $this->entityManager->getConnection();
        $user = $this->user;

        return new class ($connection, $user, $identity) {
            public function __construct(
                private readonly Connection $connection,
                private readonly User $user,
                private readonly string $identity,
            ) {
            }

            public function onFlush(): void
            {
                $this->connection->insert('social_account', [
                    'user_id' => $this->user->requireId(),
                    'network' => SocialNetwork::Bluesky->value,
                    'identity' => $this->identity,
                    'handle' => '@' . $this->identity,
                    'service_url' => 'https://bsky.social',
                    'credential_ciphertext' => 'c',
                    'credential_nonce' => 'n',
                    'credential_salt' => 's',
                    'credential_hint' => 'mnop',
                    'credential_version' => 1,
                    'status' => SocialAccountStatus::Connected->value,
                    'is_default' => 0,
                    'connected_at' => '2026-10-12 09:00:00',
                ]);
            }
        };
    }

    private function reconnect(StubAccountVerifier $verifier, SocialAccount $account): SocialAccount
    {
        return $this->connector($verifier)->reconnect(
            $account,
            SocialCredentialsModel::forReconnect($account, 'wxyz-wxyz-wxyz-9999'),
        );
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
