<?php

declare(strict_types=1);

namespace App\Service\RateLimit;

use App\Entity\User;
use App\Exception\RateLimitedException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
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
     * Caps an anonymous endpoint per client IP.
     *
     * getClientIp() returns REMOTE_ADDR unless the request came from a trusted
     * proxy, and nothing configures trusted_proxies yet — the safe default,
     * since a spoofed X-Forwarded-For cannot buy a fresh budget. But the day
     * this app sits behind a CDN or reverse proxy, every request wears the
     * proxy's address and all callers share one bucket, unless
     * framework.trusted_proxies is set at the same time.
     *
     * A null IP (possible for non-HTTP-ish transports) collapses every such
     * caller into one shared bucket — fails closed, the right direction.
     */
    public function enforceForClient(RateLimiterFactoryInterface $limiter, Request $request): void
    {
        $this->enforce($limiter->create($request->getClientIp()));
    }

    private function enforce(LimiterInterface $limiter): void
    {
        $limit = $limiter->consume();
        if ($limit->isAccepted()) {
            return;
        }

        // The ApiExceptionListener turns this into 429 problem+json with a
        // Retry-After header. max(1, ...) because a retryAfter that has just
        // elapsed would otherwise render as "Retry-After: 0", which clients read
        // as "now".
        throw new RateLimitedException(max(
            1,
            $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
        ));
    }
}
