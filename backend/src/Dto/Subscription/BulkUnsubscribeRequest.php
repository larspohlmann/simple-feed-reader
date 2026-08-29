<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The feeds to unsubscribe from in one request. Same hard technical payload
 * ceiling as a bulk update — see BulkUpdateSubscriptionsRequest for why it is
 * not the per-account subscription limit.
 */
final readonly class BulkUnsubscribeRequest
{
    /** @param list<int> $subscriptionIds */
    public function __construct(
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_BULK_REQUEST_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $subscriptionIds = [],
    ) {
    }
}
