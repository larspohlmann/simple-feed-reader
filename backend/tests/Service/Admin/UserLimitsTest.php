<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Enum\UserStatus;
use App\Service\Admin\UserLimits;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserLimitsTest extends DbTestCase
{
    private function factory(): UserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($this->em, $hasher);
    }

    private function service(): UserLimits
    {
        return new UserLimits($this->em, new MockClock('2026-07-15T00:00:00Z'));
    }

    public function testStartTrialSetsEndDateFromToday(): void
    {
        $user = $this->factory()->create('t1@example.com');
        $this->service()->startTrial($user, 14);

        self::assertEquals(new \DateTimeImmutable('2026-07-29T00:00:00Z'), $user->getTrialEndsAt());
        self::assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testStartTrialReactivatesASuspendedAccount(): void
    {
        $user = $this->factory()->create(
            't2@example.com',
            status: UserStatus::Suspended,
            trialEndsAt: new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        $this->service()->startTrial($user, 30);

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-08-14T00:00:00Z'), $user->getTrialEndsAt());
        self::assertNotNull($user->getApprovedAt());
    }

    public function testClearTrialMakesTheAccountPermanent(): void
    {
        $user = $this->factory()->create(
            't3@example.com',
            trialEndsAt: new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );

        $this->service()->clearTrial($user);

        self::assertNull($user->getTrialEndsAt());
        self::assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testClearTrialReactivatesAnExpiredTrialSuspension(): void
    {
        $user = $this->factory()->create(
            't4@example.com',
            status: UserStatus::Suspended,
            trialEndsAt: new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        $this->service()->clearTrial($user);

        self::assertNull($user->getTrialEndsAt());
        self::assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testClearTrialDoesNotReactivateASuspendedUserWhoseTrialHasNotExpired(): void
    {
        $user = $this->factory()->create(
            't6@example.com',
            status: UserStatus::Suspended,
            trialEndsAt: new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );

        $this->service()->clearTrial($user);

        self::assertNull($user->getTrialEndsAt());
        self::assertSame(UserStatus::Suspended, $user->getStatus());
    }

    public function testSetSubscriptionLimitSetsAndClears(): void
    {
        $user = $this->factory()->create('t5@example.com');

        $this->service()->setSubscriptionLimit($user, 50);
        self::assertSame(50, $user->getMaxSubscriptions());

        $this->service()->setSubscriptionLimit($user, null);
        self::assertNull($user->getMaxSubscriptions());
    }
}
