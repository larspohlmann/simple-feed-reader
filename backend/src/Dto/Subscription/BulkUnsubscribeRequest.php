<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

/** The feeds to unsubscribe from in one request. Same id cap as a bulk update. */
final readonly class BulkUnsubscribeRequest
{
    /** @param list<int> $subscriptionIds */
    public function __construct(
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $subscriptionIds = [],
    ) {
    }
}
