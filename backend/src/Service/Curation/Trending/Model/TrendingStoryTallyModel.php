<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** How many rows a story's list holds for its reader, and how many of them are unread. */
final readonly class TrendingStoryTallyModel
{
    public function __construct(
        public int $memberCount,
        public int $unreadCount,
    ) {
    }
}
