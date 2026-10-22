<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Service\Subscription\Model\InclusionChangeModel;

final readonly class SubscriptionInclusions
{
    public function apply(Subscription $subscription, InclusionChangeModel $change): void
    {
        if (null !== $change->includeInAllItems) {
            $subscription->setIncludeInAllItems($change->includeInAllItems);
        }
        if (null !== $change->includeInForYou) {
            $subscription->setIncludeInForYou($change->includeInForYou);
        }
        if (null !== $change->includeInSavedSearches) {
            $subscription->setIncludeInSavedSearches($change->includeInSavedSearches);
        }
    }
}
