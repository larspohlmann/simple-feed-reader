<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A per-user subscription cap, or null to clear the override and fall back to
 * the global default. Assert\Positive skips null, so null is accepted and any
 * present value must be a positive integer.
 */
final readonly class SetSubscriptionLimitRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $maxSubscriptions = null,
    ) {
    }
}
