<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\User;
use App\Service\Subscription\SubscriptionLimitResolver;
use App\Service\Subscription\SubscriptionService;
use PHPUnit\Framework\TestCase;

final class SubscriptionLimitResolverTest extends TestCase
{
    private function user(?int $maxSubscriptions): User
    {
        $user = new User('cap@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $user->setMaxSubscriptions($maxSubscriptions);

        return $user;
    }

    public function testFallsBackToGlobalDefaultWhenUnset(): void
    {
        self::assertSame(
            SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER,
            (new SubscriptionLimitResolver())->resolve($this->user(null)),
        );
    }

    public function testUsesThePerUserOverrideWhenSet(): void
    {
        self::assertSame(25, (new SubscriptionLimitResolver())->resolve($this->user(25)));
    }
}
