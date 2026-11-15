<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\Model;

use App\Entity\InclusionChange;

/** An edit of one subscription. An empty title clears the custom title; a null language change leaves it alone. */
final readonly class SubscriptionChangeModel
{
    /** @param list<int> $tagIds */
    public function __construct(
        public ?string $customTitle = null,
        public array $tagIds = [],
        public InclusionChange $inclusions = new InclusionChange(),
        public ?FeedLanguageChangeModel $language = null,
    ) {
    }
}
