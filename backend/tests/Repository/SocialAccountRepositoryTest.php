<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialNetwork;
use App\Enum\UserStatus;
use App\Repository\SocialAccountRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
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

    public function testNetworksOfNamesEachNetworkOfTheUserOnce(): void
    {
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:one');
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:two');
        $this->persistAccount($this->stranger, SocialNetwork::Mastodon, 'bob@mastodon.example');
        $this->entityManager->flush();

        self::assertSame([SocialNetwork::Bluesky], $this->repository()->networksOf($this->owner->requireId()));
        self::assertSame([], $this->repository()->networksOf($this->stranger->requireId() + 1000));
    }

    public function testNetworksOfListsEveryNetworkInOrder(): void
    {
        $this->persistAccount($this->owner, SocialNetwork::Mastodon, 'alice@mastodon.example');
        $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:one');
        $this->entityManager->flush();

        self::assertSame(
            [SocialNetwork::Bluesky, SocialNetwork::Mastodon],
            $this->repository()->networksOf($this->owner->requireId()),
        );
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

    public function testDueAccountsAreTheNeverCheckedThenTheLongestUnchecked(): void
    {
        $now = new \DateTimeImmutable('2026-10-17 12:00:00');
        $never = SocialAccounts::blueskyAs($this->owner, 'did:plc:due-1');
        $old = SocialAccounts::blueskyAs($this->owner, 'did:plc:due-2');
        $recent = SocialAccounts::blueskyAs($this->owner, 'did:plc:due-3');
        $old->recordSync($now->modify('-2 hours'));
        $recent->recordSync($now->modify('-10 minutes'));
        foreach ([$old, $recent, $never] as $account) {
            $this->entityManager->persist($account);
        }
        $this->entityManager->flush();

        self::assertSame(
            [$never->requireId(), $old->requireId()],
            $this->repository()->findIdsDueForSync($now->modify('-1 hour'), 10),
        );
        self::assertSame([$never->requireId()], $this->repository()->findIdsDueForSync($now->modify('-1 hour'), 1));
    }

    public function testTheAccountOfASuspendedUserIsNeverDue(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $suspended = (new UserFactory($this->entityManager, $hasher))
            ->create('due-suspended@example.test', status: UserStatus::Suspended);
        $this->entityManager->persist(SocialAccounts::blueskyAs($suspended, 'did:plc:suspended'));
        $this->entityManager->flush();

        self::assertSame([], $this->repository()->findIdsDueForSync(new \DateTimeImmutable('2026-10-17 12:00:00'), 10));
    }

    public function testAFailedCheckIsStampedEvenAfterTheEntityManagerClosed(): void
    {
        $account = $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:failing');
        $this->entityManager->flush();
        $accountId = $account->requireId();
        $checkedAt = new \DateTimeImmutable('2026-10-17 12:00:00');

        $this->entityManager->close();
        $this->repository()->recordFailedSync($accountId, $checkedAt);

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT last_checked_at, last_sync_skip FROM social_account WHERE id = ?',
            [$accountId],
        );
        self::assertIsArray($row);
        self::assertIsString($row['last_checked_at']);
        self::assertSame($checkedAt->format('Y-m-d H:i:s'), substr($row['last_checked_at'], 0, 19));
        self::assertSame(PrivateFeedSyncSkip::Failed->value, $row['last_sync_skip']);
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
