<?php

declare(strict_types=1);

namespace App\Service\Subscription;

/** One tag and flag change across many feeds. A null flag stays as it is. */
final readonly class BulkSubscriptionChange
{
    /**
     * @param list<int> $subscriptionIds
     * @param list<int> $addTagIds
     * @param list<int> $removeTagIds
     */
    public function __construct(
        public array $subscriptionIds = [],
        public array $addTagIds = [],
        public array $removeTagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
    ) {
    }
}
