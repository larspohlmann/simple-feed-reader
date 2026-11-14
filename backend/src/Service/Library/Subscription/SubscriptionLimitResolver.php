<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\User;

/**
 * The single place the effective per-user subscription cap is decided: a
 * per-user override when set, else the global default. Every enforcement and
 * display site routes through here so the fallback rule cannot drift.
 */
final readonly class SubscriptionLimitResolver
{
    public function resolve(User $user): int
    {
        return $user->getMaxSubscriptions() ?? SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER;
    }
}
