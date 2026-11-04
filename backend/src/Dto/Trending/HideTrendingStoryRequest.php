<?php

declare(strict_types=1);

namespace App\Dto\Trending;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class HideTrendingStoryRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $window,
        #[Assert\Positive]
        public int $entryId,
    ) {
    }
}
