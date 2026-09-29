<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

/** An edit of one subscription. An empty title clears the custom title; a null flag stays as it is. */
final readonly class SubscriptionChangeModel
{
    /** @param list<int> $tagIds */
    public function __construct(
        public ?string $customTitle = null,
        public array $tagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
    ) {
    }
}
