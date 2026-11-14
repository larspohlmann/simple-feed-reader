<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory\Model;

/** The oldest day whose entries the age pass has not touched yet: older days may be partly pruned. */
final readonly class RetentionHorizonModel
{
    public function __construct(private string $oldestFeedDay, private string $oldestSocialDay)
    {
    }

    public function isCountable(TrackedSubscriptionModel $subscription, string $day): bool
    {
        return $day >= ($subscription->isSocial ? $this->oldestSocialDay : $this->oldestFeedDay);
    }
}
