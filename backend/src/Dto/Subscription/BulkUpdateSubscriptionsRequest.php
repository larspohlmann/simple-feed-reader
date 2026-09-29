<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Service\Subscription\Model\BulkSubscriptionChangeModel;
use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One bulk change across many feeds: at most one tag added, one removed, and either inclusion flag, where null
 * leaves the stored value unchanged.
 */
final readonly class BulkUpdateSubscriptionsRequest
{
    /**
     * @param list<int> $subscriptionIds the feeds to change
     * @param list<int> $addTagIds       tags to add to every listed feed
     * @param list<int> $removeTagIds    tags to remove from every listed feed
     */
    public function __construct(
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_BULK_REQUEST_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $subscriptionIds = [],
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $addTagIds = [],
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $removeTagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
    ) {
    }

    public function toChange(): BulkSubscriptionChangeModel
    {
        return new BulkSubscriptionChangeModel(
            subscriptionIds: $this->subscriptionIds,
            addTagIds: $this->addTagIds,
            removeTagIds: $this->removeTagIds,
            includeInAllItems: $this->includeInAllItems,
            includeInForYou: $this->includeInForYou,
        );
    }
}
