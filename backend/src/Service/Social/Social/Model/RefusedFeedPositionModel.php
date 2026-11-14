<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\Subscription;

/** Where the hidden-profile switch stopped among the refused subscriptions, in feed, then subscription order. */
final readonly class RefusedFeedPositionModel
{
    public function __construct(
        public int $feedId,
        public int $subscriptionId,
    ) {
    }

    public static function of(Subscription $subscription): self
    {
        return new self($subscription->getFeed()->requireId(), $subscription->requireId());
    }
}
