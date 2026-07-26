<?php

declare(strict_types=1);

namespace App\Dto\Onboarding;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OnboardingSubscribeRequest
{
    /**
     * @param list<int> $catalogFeedIds
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 500)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $catalogFeedIds = [],
    ) {
    }
}
