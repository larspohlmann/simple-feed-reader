<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ReadingSettings;
use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NewUserStatus;

final class UserRepositoryTest extends DbTestCase
{
    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = $this->entityManager->getRepository(User::class);

        return $repository;
    }

    private function persist(string $email, UserStatus $status, string ...$roles): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01 10:00:00'));
        NewUserStatus::apply($user, $status, new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setRoles(array_values($roles));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function testTrendingReaderIdsLoggedInSince(): void
    {
        $now = new \DateTimeImmutable('2031-05-14 12:00:00');
        $recent = $this->persist('recent@example.com', UserStatus::Active);
        $recent->setLastLoginAt($now->modify('-13 days'));
        $old = $this->persist('old@example.com', UserStatus::Active);
        $old->setLastLoginAt($now->modify('-15 days'));
        $this->persist('never@example.com', UserStatus::Active);
        $off = $this->persist('off@example.com', UserStatus::Active);
        $off->setLastLoginAt($now->modify('-1 day'));
        $this->entityManager->getRepository(ReadingSettings::class)->find($off)?->setTrendingEnabled(false);
        $this->entityManager->flush();

        $readerIds = $this->users()->trendingReaderIdsLoggedInSince($now->modify('-14 days'));

        self::assertSame([$recent->requireId()], $readerIds);
    }

    public function testFindActiveAdminsReturnsOnlyActiveRoleAdminUsers(): void
    {
        $activeAdmin = $this->persist('admin@example.com', UserStatus::Active, 'ROLE_ADMIN');
        $this->persist('suspended-admin@example.com', UserStatus::Suspended, 'ROLE_ADMIN');
        $this->persist('active-user@example.com', UserStatus::Active);
        // A role whose name merely contains the admin string must not match.
        $this->persist('lookalike@example.com', UserStatus::Active, 'ROLE_ADMINISTRATOR');

        $admins = $this->users()->findActiveAdmins();

        self::assertCount(1, $admins);
        self::assertSame($activeAdmin->getId(), $admins[0]->getId());
    }

    public function testActiveAdminsComeBackAsAList(): void
    {
        $this->persist('lookalike@example.com', UserStatus::Active, 'ROLE_ADMINISTRATOR');
        $activeAdmin = $this->persist('admin@example.com', UserStatus::Active, 'ROLE_ADMIN');

        self::assertSame([$activeAdmin], $this->users()->findActiveAdmins());
    }

    public function testCountActiveAdminsIgnoresALookalikeRole(): void
    {
        $this->persist('admin@example.com', UserStatus::Active, 'ROLE_ADMIN');
        $this->persist('lookalike@example.com', UserStatus::Active, 'ROLE_ADMINISTRATOR');

        self::assertSame(1, $this->users()->countActiveAdmins());
    }

    public function testFindActiveAdminsIsEmptyWhenNoActiveAdminExists(): void
    {
        $this->persist('pending-admin@example.com', UserStatus::PendingApproval, 'ROLE_ADMIN');

        self::assertSame([], $this->users()->findActiveAdmins());
    }

    public function testCountByStatusCountsOnlyTheGivenStatus(): void
    {
        $this->persist('a@example.com', UserStatus::PendingApproval);
        $this->persist('b@example.com', UserStatus::PendingApproval);
        $this->persist('c@example.com', UserStatus::Active);

        self::assertSame(2, $this->users()->countByStatus(UserStatus::PendingApproval));
        self::assertSame(1, $this->users()->countByStatus(UserStatus::Active));
        self::assertSame(0, $this->users()->countByStatus(UserStatus::Rejected));
    }

    public function testEmptyInstanceHasNoAdmin(): void
    {
        self::assertFalse($this->users()->hasAnyAdmin());
    }

    public function testAPlainActiveUserIsNotAnAdmin(): void
    {
        $this->persist('plain@example.com', UserStatus::Active);

        self::assertFalse($this->users()->hasAnyAdmin());
    }

    public function testASuspendedAdminStillCounts(): void
    {
        $this->persist('boss@example.com', UserStatus::Suspended, 'ROLE_ADMIN');

        self::assertTrue($this->users()->hasAnyAdmin());
    }

    public function testARoleAdministratorSubstringDoesNotCount(): void
    {
        $this->persist('fake@example.com', UserStatus::Active, 'ROLE_ADMINISTRATOR');

        self::assertFalse($this->users()->hasAnyAdmin());
    }
}
