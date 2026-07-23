<?php

declare(strict_types=1);

namespace App\Dto\Tag;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TagFeedOrderRequest
{
    /** @param list<int> $subscriptionIds the tag's feeds in their new order */
    public function __construct(
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $subscriptionIds = [],
    ) {
    }
}
