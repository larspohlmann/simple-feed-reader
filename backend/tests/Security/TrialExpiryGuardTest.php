<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Security\AccountStatusException;
use App\Security\TrialExpiryGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TrialExpiryGuardTest extends TestCase
{
    private function user(?\DateTimeImmutable $trialEndsAt, UserStatus $status = UserStatus::Active): User
    {
        $user = new User('trial@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setStatus($status);
        $user->setTrialEndsAt($trialEndsAt);

        return $user;
    }

    private function guard(EntityManagerInterface $em): TrialExpiryGuard
    {
        return new TrialExpiryGuard($em, new MockClock('2026-07-15T00:00:00Z'));
    }

    public function testNoTrialIsANoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->guard($em)->enforce($this->user(null));
        $this->expectNotToPerformAssertions();
    }

    public function testActiveTrialInTheFutureIsANoOp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->guard($em)->enforce($this->user(new \DateTimeImmutable('2026-07-20T00:00:00Z')));
        $this->expectNotToPerformAssertions();
    }

    public function testExpiredTrialFlipsActiveUserToSuspendedThenThrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $user = $this->user(new \DateTimeImmutable('2026-07-10T00:00:00Z'));

        try {
            $this->guard($em)->enforce($user);
            self::fail('Expected AccountStatusException');
        } catch (AccountStatusException $exception) {
            self::assertSame('suspended', $exception->accountStatus);
        }

        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }

    public function testExpiredTrialOnAlreadySuspendedUserThrowsWithoutFlushing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $user = $this->user(new \DateTimeImmutable('2026-07-10T00:00:00Z'), UserStatus::Suspended);

        $this->expectException(AccountStatusException::class);
        $this->guard($em)->enforce($user);
    }
}
