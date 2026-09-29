<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Keys the login throttle on User::normalizeEmail(), so padded spellings of one address share a bucket. It rewrites
 * the request attribute in place and must stay peekable: a non-peekable decorator shifts the limit by one attempt.
 * Why: docs/security.md#login-throttle-key
 */
final readonly class NormalizedLoginRateLimiter implements PeekableRequestRateLimiterInterface
{
    public function __construct(
        private PeekableRequestRateLimiterInterface $inner,
    ) {
    }

    public function consume(Request $request): RateLimit
    {
        $this->normalizeLastUsername($request);

        return $this->inner->consume($request);
    }

    public function peek(Request $request): RateLimit
    {
        $this->normalizeLastUsername($request);

        return $this->inner->peek($request);
    }

    public function reset(Request $request): void
    {
        $this->normalizeLastUsername($request);
        $this->inner->reset($request);
    }

    private function normalizeLastUsername(Request $request): void
    {
        $identifier = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);
        if (!\is_string($identifier)) {
            return;
        }

        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, User::normalizeEmail($identifier));
    }
}
