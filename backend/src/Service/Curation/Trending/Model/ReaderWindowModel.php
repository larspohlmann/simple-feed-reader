<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\TrendingWindow;

/** One reader's cut of a window: a story shows when at least $minimumOutlets of their feeds ran it (D38). */
final readonly class ReaderWindowModel
{
    public function __construct(
        public int $userId,
        public TrendingWindow $window,
        public int $minimumOutlets,
        /** @var list<int> a story sharing an entry with one of these is left out (D41) */
        public array $shorterWindowStoryIds,
    ) {
    }
}
