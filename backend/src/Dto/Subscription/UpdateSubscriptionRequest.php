<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateSubscriptionRequest
{
    /** @param list<int> $tagIds */
    public function __construct(
        #[Assert\Length(max: 512)]
        public ?string $customTitle = null,
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $tagIds = [],
    ) {
    }
}
