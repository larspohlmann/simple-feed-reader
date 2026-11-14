<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

use App\Enum\TrendingWindow;

final readonly class TrendingPageModel
{
    /** @param list<TrendingStoryCardModel> $stories */
    public function __construct(
        public TrendingWindow $window,
        public ?\DateTimeImmutable $builtAt,
        public array $stories,
    ) {
    }
}
