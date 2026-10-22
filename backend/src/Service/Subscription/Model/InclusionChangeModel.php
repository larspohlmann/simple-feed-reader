<?php

declare(strict_types=1);

namespace App\Service\Subscription\Model;

/** Which surfaces a subscription counts in; a null flag stays as it is. */
final readonly class InclusionChangeModel
{
    public function __construct(
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
        public ?bool $includeInSavedSearches = null,
    ) {
    }
}
