<?php

declare(strict_types=1);

namespace App\Tests\Service\RateLimit;

use App\Entity\User;
use App\Exception\RateLimitedException;
use App\Service\RateLimit\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;

final class RateLimitGuardTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-31 12:00:00');
    }

    public function testAnAcceptedLimitDoesNotThrow(): void
    {
        $factory = $this->factoryReturning($this->accepted());

        $this->guard()->enforceForClient($factory, $this->requestFrom('203.0.113.7'));

        $this->assertSame('203.0.113.7', $factory->capturedKey);
    }

    public function testARejectedLimitThrowsWithTheSecondsUntilRetry(): void
    {
        $factory = $this->factoryReturning($this->rejectedRetryingIn(30));

        try {
            $this->guard()->enforceForClient($factory, $this->requestFrom('203.0.113.7'));
            $this->fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $exception) {
            $this->assertSame(30, $exception->retryAfterSeconds);
        }
    }

    public function testAJustElapsedRetryAfterYieldsOneNeverZero(): void
    {
        // A retryAfter that has just elapsed would subtract to 0 (or below).
        // Retry-After: 0 reads as "now" to clients, so the guard clamps to 1.
        $factory = $this->factoryReturning($this->rejectedRetryingIn(0));

        try {
            $this->guard()->enforceForClient($factory, $this->requestFrom('203.0.113.7'));
            $this->fail('Expected a RateLimitedException.');
        } catch (RateLimitedException $exception) {
            $this->assertSame(1, $exception->retryAfterSeconds);
        }
    }

    public function testEnforceForUserKeysOnTheUserId(): void
    {
        $factory = $this->factoryReturning($this->accepted());
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);

        $this->guard()->enforceForUser($factory, $user);

        $this->assertSame('user-42', $factory->capturedKey);
    }

    private function guard(): RateLimitGuard
    {
        return new RateLimitGuard($this->clock);
    }

    private function requestFrom(string $clientIp): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $clientIp]);
    }

    private function accepted(): RateLimit
    {
        return new RateLimit(10, $this->clock->now(), true, 10);
    }

    private function rejectedRetryingIn(int $seconds): RateLimit
    {
        return new RateLimit(0, $this->clock->now()->modify(sprintf('+%d seconds', $seconds)), false, 10);
    }

    private function factoryReturning(RateLimit $limit): CapturingRateLimiterFactory
    {
        return new CapturingRateLimiterFactory(new StubLimiter($limit));
    }
}
