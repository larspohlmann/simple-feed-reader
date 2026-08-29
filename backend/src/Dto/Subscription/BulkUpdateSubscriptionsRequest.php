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
 * The id cap is a hard technical ceiling on one request's payload size, NOT
 * the per-account subscription limit — an admin can raise a single account's
 * real cap above the global default (SubscriptionLimitResolver), and this
 * attribute cannot read the current user to match it. See
 * SubscriptionService::MAX_BULK_REQUEST_IDS for why it is generous instead.
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
}
