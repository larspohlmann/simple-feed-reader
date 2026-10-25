<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

final readonly class TrackedSubscriptionModel
{
    public function __construct(
        public int $subscriptionId,
        public string $createdDay,
        public bool $isSocial,
    ) {
    }

    /**
     * @param list<self> $subscriptions
     * @return list<int>
     */
    public static function idsOf(array $subscriptions): array
    {
        return array_map(static fn (self $subscription): int => $subscription->subscriptionId, $subscriptions);
    }
}
