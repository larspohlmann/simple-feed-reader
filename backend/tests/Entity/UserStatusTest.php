<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\UserStatus;
use PHPUnit\Framework\TestCase;

final class UserStatusTest extends TestCase
{
    public function testApprovingActivatesTheAccountAndStampsTheGrant(): void
    {
        $user = $this->user();

        $user->approve(new \DateTimeImmutable('2026-07-15 08:30:00'));

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-07-15 08:30:00'), $user->getApprovedAt());
    }

    public function testAReinstatementMovesTheStampToTheLatestGrant(): void
    {
        $user = $this->user();
        $user->approve(new \DateTimeImmutable('2026-07-15 08:30:00'));
        $user->suspend();

        $user->approve(new \DateTimeImmutable('2026-08-02 17:45:00'));

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-08-02 17:45:00'), $user->getApprovedAt());
    }

    public function testSuspendingKeepsTheLastGrant(): void
    {
        $user = $this->user();
        $user->approve(new \DateTimeImmutable('2026-07-15 08:30:00'));

        $user->suspend();

        self::assertSame(UserStatus::Suspended, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-07-15 08:30:00'), $user->getApprovedAt());
    }

    public function testQueueingForApprovalGrantsNothing(): void
    {
        $user = $this->user();

        $user->queueForApproval();

        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getApprovedAt());
    }

    public function testRejectingGrantsNothing(): void
    {
        $user = $this->user();
        $user->queueForApproval();

        $user->reject();

        self::assertSame(UserStatus::Rejected, $user->getStatus());
        self::assertNull($user->getApprovedAt());
    }

    private function user(): User
    {
        return new User('status@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
    }
}
