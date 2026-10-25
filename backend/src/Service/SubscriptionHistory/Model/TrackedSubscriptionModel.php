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
}
