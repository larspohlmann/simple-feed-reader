<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One bulk change across many feeds: at most one tag added, at most one tag
 * removed, and either inclusion flag set.
 *
 * The flags are nullable and default to null, meaning "leave the stored value
 * unchanged" — the same convention UpdateSubscriptionRequest and
 * EntryController::updateState use (#695).
 *
 * The id cap is the per-account subscription limit: a bulk request may name at
 * most every feed the caller could possibly own. Reading the constant rather
 * than repeating 500 keeps the two from drifting.
 */
final readonly class BulkUpdateSubscriptionsRequest
{
    /**
     * @param list<int> $subscriptionIds the feeds to change
     * @param list<int> $addTagIds       tags to add to every listed feed
     * @param list<int> $removeTagIds    tags to remove from every listed feed
     */
    public function __construct(
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER)]
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
}
