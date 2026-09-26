<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Http\AdminUserLimitsJson;
use PHPUnit\Framework\TestCase;

final class AdminUserLimitsJsonTest extends TestCase
{
    public function testATrialReportsTheStatusAndItsEnd(): void
    {
        $user = $this->user();
        $user->approve(new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        $user->setTrialEndsAt(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'));

        self::assertSame(
            ['status' => 'active', 'trialEndsAt' => '2026-10-01T00:00:00+00:00'],
            AdminUserLimitsJson::trial($user),
        );
    }

    public function testNoTrialReportsANullEnd(): void
    {
        self::assertSame(
            ['status' => 'pending_verification', 'trialEndsAt' => null],
            AdminUserLimitsJson::trial($this->user()),
        );
    }

    public function testTheSubscriptionLimitIsReported(): void
    {
        $user = $this->user();
        $user->setMaxSubscriptions(25);

        self::assertSame(['maxSubscriptions' => 25], AdminUserLimitsJson::subscriptionLimit($user));
    }

    private function user(): User
    {
        return new User('limits@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
