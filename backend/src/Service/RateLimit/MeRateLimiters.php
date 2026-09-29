<?php

declare(strict_types=1);

namespace App\Service\RateLimit;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** The account's own outbound-mail budgets, wired by service id in services.yaml, not by parameter name. */
final readonly class MeRateLimiters
{
    public function __construct(
        public RateLimiterFactoryInterface $digestTest,
        public RateLimiterFactoryInterface $resendVerification,
    ) {
    }
}
