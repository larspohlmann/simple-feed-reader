<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\AccountStatusException;
use App\Security\TrialExpiryGuard;
use App\Tests\Support\NewUserStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TrialExpiryGuardTest extends TestCase
{
    private function user(?\DateTimeImmutable $trialEndsAt, UserStatus $status = UserStatus::Active): User
    {
        $user = new User('trial@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        NewUserStatus::apply($user, $status, new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setTrialEndsAt($trialEndsAt);

        return $user;
    }

    private function guard(EntityManagerInterface $entityManager): TrialExpiryGuard
    {
        return new TrialExpiryGuard($entityManager, new MockClock('2026-07-15T00:00:00Z'));
    }

    public function testNoTrialIsANoOp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->guard($entityManager)->enforce($this->user(null));
    }

    public function testActiveTrialInTheFutureIsANoOp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->guard($entityManager)->enforce($this->user(new \DateTimeImmutable('2026-07-20T00:00:00Z')));
    }

    public function testExpiredTrialFlipsActiveUserToSuspendedThenThrows(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $user = $this->user(new \DateTimeImmutable('2026-07-10T00:00:00Z'));

        try {
            $this->guard($entityManager)->enforce($user);
            self::fail('Expected AccountStatusException');
        } catch (AccountStatusException $exception) {
            self::assertSame('suspended', $exception->accountStatus);
        }

        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }

    public function testExpiredTrialOnAlreadySuspendedUserThrowsWithoutFlushing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $user = $this->user(new \DateTimeImmutable('2026-07-10T00:00:00Z'), UserStatus::Suspended);

        $this->expectException(AccountStatusException::class);
        $this->guard($entityManager)->enforce($user);
    }
}
