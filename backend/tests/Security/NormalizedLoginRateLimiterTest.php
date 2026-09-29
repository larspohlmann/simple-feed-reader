<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\NormalizedLoginRateLimiter;
use App\Tests\Support\RecordingLoginRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class NormalizedLoginRateLimiterTest extends TestCase
{
    private const string PADDED_USERNAME = '  Bob@Example.COM ';

    private RecordingLoginRateLimiter $inner;

    private NormalizedLoginRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->inner = new RecordingLoginRateLimiter();
        $this->limiter = new NormalizedLoginRateLimiter($this->inner);
    }

    public function testPeekHandsTheInnerLimiterTheNormalizedUsername(): void
    {
        $this->limiter->peek($this->paddedLogin());

        $this->assertInnerSawTheNormalizedUsername('peek');
    }

    public function testConsumeHandsTheInnerLimiterTheNormalizedUsername(): void
    {
        $this->limiter->consume($this->paddedLogin());

        $this->assertInnerSawTheNormalizedUsername('consume');
    }

    public function testResetHandsTheInnerLimiterTheNormalizedUsername(): void
    {
        $this->limiter->reset($this->paddedLogin());

        $this->assertInnerSawTheNormalizedUsername('reset');
    }

    public function testARequestWithoutAUsernameReachesTheInnerLimiterWithoutOne(): void
    {
        $this->limiter->consume(new Request());

        self::assertSame(['consume' => null], $this->inner->lastUsernameSeenBy);
    }

    private function paddedLogin(): Request
    {
        $request = new Request();
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, self::PADDED_USERNAME);

        return $request;
    }

    private function assertInnerSawTheNormalizedUsername(string $operation): void
    {
        self::assertSame(
            [$operation => User::normalizeEmail(self::PADDED_USERNAME)],
            $this->inner->lastUsernameSeenBy,
        );
    }
}
