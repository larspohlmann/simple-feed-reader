<?php

declare(strict_types=1);

namespace App\Service\RateLimit;

use App\Entity\User;
use App\Service\RateLimit\Exception\RateLimitedException;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Consumes one token from a rate limiter and throws when the budget is spent.
 *
 * Two entry points, keyed differently on purpose. The difference is
 * security-relevant, so it is split into two methods rather than hidden behind a
 * key-string flag: {@see self::enforceForClient()} keys on the client IP and
 * carries the trusted-proxy caveat, {@see self::enforceForUser()} keys on the
 * authenticated user's id.
 */
final readonly class RateLimitGuard
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * Caps an authenticated endpoint per user. The user id is a trustworthy key:
     * it comes from the verified token, not from a header a caller can set.
     */
    public function enforceForUser(RateLimiterFactoryInterface $limiter, User $user): void
    {
        $this->enforce($limiter->create('user-' . $user->getId()));
    }

    /**
     * Caps an anonymous endpoint per Request::getClientIp(); without framework.trusted_proxies,
     * every caller behind a shared proxy spends the same bucket, and a null IP fails closed likewise.
     */
    public function enforceForClient(RateLimiterFactoryInterface $limiter, ?string $clientIp): void
    {
        $this->enforce($limiter->create($clientIp));
    }

    private function enforce(LimiterInterface $limiter): void
    {
        $limit = $limiter->consume();
        if ($limit->isAccepted()) {
            return;
        }

        // max(1, …): a retryAfter that has just elapsed would render as "Retry-After: 0",
        // which clients read as "now".
        throw new RateLimitedException(max(
            1,
            $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
        ));
    }
}
