<?php

declare(strict_types=1);

namespace App\Repository;

/** Everything one read of a story's entries needs: the reader, the story, and the page. */
final readonly class TrendingStoryListQuery
{
    public function __construct(
        public int $userId,
        public int $storyId,
        public EntryListPage $page = new EntryListPage(),
    ) {
    }
}
