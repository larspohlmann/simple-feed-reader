<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;

final class UserRepositoryTest extends DbTestCase
{
    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = $this->em->getRepository(User::class);

        return $repository;
    }

    private function persist(string $email, UserStatus $status, string ...$roles): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setStatus($status);
        $user->setRoles(array_values($roles));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
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
