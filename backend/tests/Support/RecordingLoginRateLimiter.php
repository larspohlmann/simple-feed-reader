<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class RecordingLoginRateLimiter implements PeekableRequestRateLimiterInterface
{
    /** @var array<string, mixed> */
    public array $lastUsernameSeenBy = [];

    public function peek(Request $request): RateLimit
    {
        $this->record('peek', $request);

        return new RateLimit(1, new \DateTimeImmutable(), true, 1);
    }

    public function consume(Request $request): RateLimit
    {
        $this->record('consume', $request);

        return new RateLimit(1, new \DateTimeImmutable(), true, 1);
    }

    public function reset(Request $request): void
    {
        $this->record('reset', $request);
    }

    private function record(string $operation, Request $request): void
    {
        $this->lastUsernameSeenBy[$operation] = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);
    }
}
