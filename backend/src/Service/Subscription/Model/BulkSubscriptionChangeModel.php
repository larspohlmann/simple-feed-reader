<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

use App\Entity\InclusionChange;

/** One tag and flag change across many feeds. */
final readonly class BulkSubscriptionChangeModel
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
        public InclusionChange $inclusions = new InclusionChange(),
    ) {
    }
}
