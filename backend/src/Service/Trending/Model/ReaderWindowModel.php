<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

use App\Enum\TrendingWindow;

/** One reader's cut of a window: a story shows when at least $minimumOutlets of their feeds ran it (D38). */
final readonly class ReaderWindowModel
{
    public function __construct(
        public int $userId,
        public TrendingWindow $window,
        public int $minimumOutlets,
    ) {
    }
}
