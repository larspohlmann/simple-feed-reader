<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Factory;

use App\Enum\UserStatus;
use App\Service\OAuth\Factory\OAuthUserFactory;
use App\Service\OAuth\OAuthIdentity;
use App\Tests\DbTestCase;
use App\Tests\Support\RegistrationPolicies;
use Symfony\Component\Clock\MockClock;

final class OAuthUserFactoryTest extends DbTestCase
{
    use RegistrationPolicies;

    private const string NOW = '2026-07-21 12:00:00';

    public function testALinkableAddressBecomesTheVerifiedLoginIdentifier(): void
    {
        $user = $this->factory(approve: true)->create(new OAuthIdentity('google', 'sub-1', 'Ann@Example.test', true));

        self::assertSame('ann@example.test', $user->getEmail());
        self::assertTrue($user->isEmailVerified());
    }

    public function testAnUnverifiedAddressLeavesAStablePlaceholder(): void
    {
        $user = $this->factory(approve: true)->create(new OAuthIdentity('google', 'sub-1', 'ann@example.test', false));

        self::assertSame(
            sprintf('google-%s@oauth.invalid', substr(hash('sha256', 'sub-1'), 0, 32)),
            $user->getEmail(),
        );
        self::assertFalse($user->isEmailVerified());
    }

    public function testWithApprovalOnTheAccountWaitsForAnAdmin(): void
    {
        $user = $this->factory(approve: true)->create(new OAuthIdentity('google', 'sub-1', 'ann@example.test', true));

        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getApprovedAt());
    }

    public function testWithApprovalOffTheAccountIsActiveAtOnce(): void
    {
        $user = $this->factory(approve: false)->create(new OAuthIdentity('google', 'sub-1', 'ann@example.test', true));

        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertSame(
            (new \DateTimeImmutable(self::NOW))->format(\DATE_ATOM),
            $user->getApprovedAt()?->format(\DATE_ATOM),
        );
    }

    private function factory(bool $approve): OAuthUserFactory
    {
        return new OAuthUserFactory(
            new MockClock(self::NOW),
            $this->registrationPolicy(confirm: true, approve: $approve),
        );
    }
}
