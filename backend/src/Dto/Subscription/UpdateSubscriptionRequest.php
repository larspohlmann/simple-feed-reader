<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Entity\InclusionChange;
use App\Service\Subscription\Model\SubscriptionChangeModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateSubscriptionRequest
{
    /** @param list<int> $tagIds */
    public function __construct(
        #[Assert\Length(max: 512)]
        public ?string $customTitle = null,
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $tagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
        public ?bool $includeInSavedSearches = null,
        public ?bool $includeInTrending = null,
    ) {
    }

    public function toChange(): SubscriptionChangeModel
    {
        return new SubscriptionChangeModel(
            customTitle: $this->customTitle,
            tagIds: $this->tagIds,
            inclusions: new InclusionChange(
                allItems: $this->includeInAllItems,
                forYou: $this->includeInForYou,
                savedSearches: $this->includeInSavedSearches,
                trending: $this->includeInTrending,
            ),
        );
    }
}
