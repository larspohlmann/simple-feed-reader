<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialAccountRepositoryTest extends DbTestCase
{
    private User $owner;
    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->entityManager, $hasher);
        $this->owner = $factory->create('social-repo-owner@example.test');
        $this->stranger = $factory->create('social-repo-stranger@example.test');
    }

    public function testFindAllForUserListsOnlyThatUsersAccountsByNetworkThenAge(): void
    {
        $this->persistAccount($this->owner, SocialNetwork::Mastodon, 'alice@mastodon.example');
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:first');
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:second');
        $this->persistAccount($this->stranger, SocialNetwork::Bluesky, 'did:plc:stranger');
        $this->entityManager->flush();

        $accounts = $this->repository()->findAllForUser($this->owner);

        self::assertSame(
            ['did:plc:first', 'did:plc:second', 'alice@mastodon.example'],
            array_map(static fn (SocialAccount $account): string => $account->getIdentity(), $accounts),
        );
    }

    public function testFindOneForUserHidesAnotherUsersAccount(): void
    {
        $account = $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:a');
        $this->entityManager->flush();
        $accountId = $account->requireId();

        self::assertNull($this->repository()->findOneForUser($this->stranger, $accountId));
        self::assertSame($account, $this->repository()->findOneForUser($this->owner, $accountId));
    }

    public function testFindOneByIdentityMatchesNetworkAndIdentity(): void
    {
        $account = $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:a');
        $this->entityManager->flush();

        self::assertSame(
            $account,
            $this->repository()->findOneByIdentity($this->owner, SocialNetwork::Bluesky, 'did:plc:a'),
        );
        self::assertNull($this->repository()->findOneByIdentity($this->owner, SocialNetwork::Mastodon, 'did:plc:a'));
    }

    public function testFindAllOnNetworkAndCountForUser(): void
    {
        $older = $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:older');
        $newer = $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:newer');
        $this->persistAccount($this->owner, SocialNetwork::Mastodon, 'alice@mastodon.example');
        $this->entityManager->flush();

        self::assertSame(
            [$older, $newer],
            $this->repository()->findAllOnNetwork($this->owner, SocialNetwork::Bluesky),
        );
        self::assertSame(3, $this->repository()->countForUser($this->owner));
    }

    public function testFindAllForUserIdListsOnlyThatUsersAccountsByNetworkThenAge(): void
    {
        $this->persistAccount($this->owner, SocialNetwork::Mastodon, 'alice@mastodon.example');
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:one');
        $this->persistAccount($this->stranger, SocialNetwork::Bluesky, 'did:plc:stranger');
        $this->entityManager->flush();

        self::assertSame(
            ['did:plc:one', 'alice@mastodon.example'],
            array_map(
                static fn (SocialAccount $account): string => $account->getIdentity(),
                $this->repository()->findAllForUserId($this->owner->requireId()),
            ),
        );
        self::assertSame([], $this->repository()->findAllForUserId($this->stranger->requireId() + 1000));
    }

    public function testTheDefaultAccountActsBeforeAnOlderOne(): void
    {
        $this->accountConnectedAt('did:plc:older', '2026-10-01 10:00:00');
        $default = $this->accountConnectedAt('did:plc:default', '2026-10-05 10:00:00');
        $default->becomeDefault();
        $this->entityManager->flush();

        self::assertSame($default, $this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Bluesky));
    }

    public function testTheOldestOfSeveralDefaultsActs(): void
    {
        $newer = $this->accountConnectedAt('did:plc:newer', '2026-10-05 10:00:00');
        $oldest = $this->accountConnectedAt('did:plc:oldest', '2026-10-01 10:00:00');
        $newer->becomeDefault();
        $oldest->becomeDefault();
        $this->entityManager->flush();

        self::assertSame($oldest, $this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Bluesky));
    }

    public function testWithoutADefaultTheOldestAccountActsAndAnotherNetworkNever(): void
    {
        $this->accountConnectedAt('did:plc:newer', '2026-10-05 10:00:00');
        $oldest = $this->accountConnectedAt('did:plc:oldest', '2026-10-01 10:00:00');

        self::assertSame($oldest, $this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Bluesky));
        self::assertNull($this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Mastodon));
        self::assertNull($this->repository()->findActingOnNetwork($this->stranger, SocialNetwork::Bluesky));
    }

    public function testEqualConnectionTimesResolveToTheLowestId(): void
    {
        $first = $this->accountConnectedAt('did:plc:first', '2026-10-01 10:00:00');
        $this->accountConnectedAt('did:plc:second', '2026-10-01 10:00:00');

        self::assertSame($first, $this->repository()->findActingOnNetwork($this->owner, SocialNetwork::Bluesky));
    }

    public function testTheConnectedAccountsOnANetworkListTheDefaultFirstAndSkipOnesToReconnect(): void
    {
        $older = $this->accountConnectedAt('did:plc:older', '2026-10-01 10:00:00');
        $broken = $this->accountConnectedAt('did:plc:broken', '2026-10-02 10:00:00');
        $default = $this->accountConnectedAt('did:plc:default', '2026-10-05 10:00:00');
        $default->becomeDefault();
        $broken->markNeedsReconnect();
        $this->persistAccount($this->stranger, SocialNetwork::Bluesky, 'did:plc:stranger');
        $this->entityManager->flush();

        self::assertSame(
            [$default, $older],
            $this->repository()->findConnectedOnNetwork($this->owner, SocialNetwork::Bluesky),
        );
        self::assertSame([], $this->repository()->findConnectedOnNetwork($this->owner, SocialNetwork::Mastodon));
    }

    public function testTheReadyActingAccountIsTheDefaultAmongTheConnectedOnes(): void
    {
        $older = $this->accountConnectedAt('did:plc:older', '2026-10-01 10:00:00');
        $default = $this->accountConnectedAt('did:plc:default', '2026-10-05 10:00:00');
        $default->becomeDefault();
        $this->entityManager->flush();

        self::assertSame($default, $this->repository()->findReadyActingOnNetwork($this->owner, SocialNetwork::Bluesky));

        $default->markNeedsReconnect();
        $this->entityManager->flush();

        self::assertSame($older, $this->repository()->findReadyActingOnNetwork($this->owner, SocialNetwork::Bluesky));
        self::assertNull($this->repository()->findReadyActingOnNetwork($this->owner, SocialNetwork::Mastodon));
    }

    private function accountConnectedAt(string $identity, string $connectedAt): SocialAccount
    {
        $account = $this->persistAccount($this->owner, SocialNetwork::Bluesky, $identity);
        $account->replaceCredential(
            '@' . $identity,
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable($connectedAt),
        );
        $this->entityManager->flush();

        return $account;
    }

    private function persistAccount(User $user, SocialNetwork $network, string $identity): SocialAccount
    {
        $account = new SocialAccount(
            $user,
            $network,
            $identity,
            '@' . $identity,
            'https://service.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager->persist($account);

        return $account;
    }

    private function repository(): SocialAccountRepository
    {
        /** @var SocialAccountRepository $repository */
        $repository = $this->entityManager->getRepository(SocialAccount::class);

        return $repository;
    }
}
