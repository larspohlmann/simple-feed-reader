<?php

declare(strict_types=1);

namespace App\Service\RateLimit;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The per-user rate limiters MeController enforces. Grouped into one value
 * object rather than one constructor parameter per limiter, because
 * `RateLimiterFactoryInterface` autowires by parameter name — a bare list of
 * them on the controller reads as unrelated scalars, not as one cohesive
 * "the account's own outbound-mail budgets" concept. Wired explicitly in
 * services.yaml against the underlying `limiter.<name>` service ids, since
 * that autowiring alias is keyed to the exact `$<name>Limiter` parameter name
 * this class deliberately does not repeat.
 */
final readonly class MeRateLimiters
{
    public function __construct(
        public RateLimiterFactoryInterface $digestTest,
        public RateLimiterFactoryInterface $resendVerification,
    ) {
    }
}
