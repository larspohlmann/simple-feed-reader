<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SocialAccount;
use App\Entity\SocialNotificationPoll;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\SocialNotificationPollRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialNotificationPollRepositoryTest extends DbTestCase
{
    private const string NOW = '2026-11-17 12:00:00';

    private SocialNotificationPollRepository $polls;
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();
        $polls = $this->entityManager->getRepository(SocialNotificationPoll::class);
        self::assertInstanceOf(SocialNotificationPollRepository::class, $polls);
        $this->polls = $polls;
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->entityManager, $hasher);
    }

    public function testDueOrderAndExclusions(): void
    {
        $owner = $this->users->create('poll-due-owner@example.test');
        $never = $this->account($owner, 'did:plc:never');
        $sixMinutesAgo = $this->account($owner, 'did:plc:six');
        $fourMinutesAgo = $this->account($owner, 'did:plc:four');
        $broken = $this->account($owner, 'did:plc:broken');
        $broken->markNeedsReconnect();
        $deactivated = $this->account(
            $this->users->create('poll-due-suspended@example.test', status: UserStatus::Suspended),
            'did:plc:suspended',
        );
        $this->checked($sixMinutesAgo, '2026-11-17 11:54:00');
        $this->checked($fourMinutesAgo, '2026-11-17 11:56:00');
        $this->checked($broken, '2026-11-17 10:00:00');
        $this->checked($deactivated, '2026-11-17 10:00:00');
        $this->entityManager->flush();

        $due = $this->polls->findAccountIdsDue(new \DateTimeImmutable(self::NOW), 10);

        self::assertSame([$never->requireId(), $sixMinutesAgo->requireId()], $due);
    }

    public function testTheLimitCutsTheOldestFirstList(): void
    {
        $owner = $this->users->create('poll-limit-owner@example.test');
        $older = $this->account($owner, 'did:plc:older');
        $newer = $this->account($owner, 'did:plc:newer');
        $this->checked($newer, '2026-11-17 11:00:00');
        $this->checked($older, '2026-11-17 10:00:00');
        $this->entityManager->flush();

        self::assertSame(
            [$older->requireId()],
            $this->polls->findAccountIdsDue(new \DateTimeImmutable(self::NOW), 1),
        );
    }

    public function testStampOfReusesTheRowAndCreatesItOnce(): void
    {
        $account = $this->account($this->users->create('poll-stamp-owner@example.test'), 'did:plc:stamp');

        $created = $this->polls->ensureStamp($account);
        $this->entityManager->flush();

        self::assertSame($created, $this->polls->ensureStamp($account));
        self::assertCount(1, $this->polls->findAll());
    }

    private function account(User $owner, string $identity): SocialAccount
    {
        $account = SocialAccounts::blueskyAs($owner, $identity);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function checked(SocialAccount $account, string $at): void
    {
        $this->polls->ensureStamp($account)->recordChecked(new \DateTimeImmutable($at));
    }
}
