<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

use App\Entity\Subscription;

final readonly class SubscribeOutcomeModel
{
    private function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public Subscription $subscription,
        public int $unreadCount,
    ) {
    }

    public static function subscribed(Subscription $subscription, int $unreadCount = 0): self
    {
        return new self($subscription, $unreadCount);
    }
}
