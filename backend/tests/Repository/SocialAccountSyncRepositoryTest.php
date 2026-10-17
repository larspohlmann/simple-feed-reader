<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialNetwork;
use App\Enum\UserStatus;
use App\Repository\SocialAccountSyncRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialAccountSyncRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->user('social-sync-owner@example.test');
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
            $this->syncs()->findIdsDueForSync($now->modify('-1 hour'), 10),
        );
        self::assertSame([$never->requireId()], $this->syncs()->findIdsDueForSync($now->modify('-1 hour'), 1));
    }

    public function testAnAccountCheckedExactlyAtTheCutoffIsNotYetDue(): void
    {
        $now = new \DateTimeImmutable('2026-10-17 12:00:00');
        $cutoff = $now->modify('-1 hour');
        $atCutoff = SocialAccounts::blueskyAs($this->owner, 'did:plc:cutoff-1');
        $beforeCutoff = SocialAccounts::blueskyAs($this->owner, 'did:plc:cutoff-2');
        $atCutoff->recordSync($cutoff);
        $beforeCutoff->recordSync($cutoff->modify('-1 second'));
        $this->entityManager->persist($atCutoff);
        $this->entityManager->persist($beforeCutoff);
        $this->entityManager->flush();

        self::assertSame([$beforeCutoff->requireId()], $this->syncs()->findIdsDueForSync($cutoff, 10));
    }

    public function testTheAccountOfASuspendedUserIsNeverDue(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $suspended = (new UserFactory($this->entityManager, $hasher))
            ->create('due-suspended@example.test', status: UserStatus::Suspended);
        $this->entityManager->persist(SocialAccounts::blueskyAs($suspended, 'did:plc:suspended'));
        $this->entityManager->flush();

        self::assertSame([], $this->syncs()->findIdsDueForSync(new \DateTimeImmutable('2026-10-17 12:00:00'), 10));
    }

    public function testAFailedCheckIsStampedEvenAfterTheEntityManagerClosed(): void
    {
        $account = $this->persistAccount($this->owner, SocialNetwork::Bluesky, 'did:plc:failing');
        $this->entityManager->flush();
        $accountId = $account->requireId();
        $checkedAt = new \DateTimeImmutable('2026-10-17 12:00:00');

        $this->entityManager->close();
        $this->syncs()->recordFailedSync($accountId, $checkedAt);

        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT last_checked_at, last_sync_skip FROM social_account WHERE id = ?',
            [$accountId],
        );
        self::assertIsArray($row);
        self::assertIsString($row['last_checked_at']);
        self::assertSame($checkedAt->format('Y-m-d H:i:s'), substr($row['last_checked_at'], 0, 19));
        self::assertSame(PrivateFeedSyncSkip::Failed->value, $row['last_sync_skip']);
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

    private function syncs(): SocialAccountSyncRepository
    {
        $syncs = self::getContainer()->get(SocialAccountSyncRepository::class);
        self::assertInstanceOf(SocialAccountSyncRepository::class, $syncs);

        return $syncs;
    }
}
