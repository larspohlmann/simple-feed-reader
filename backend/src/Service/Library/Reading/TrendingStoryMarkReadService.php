<?php

declare(strict_types=1);

namespace App\Service\Library\Reading;

use App\Repository\TrendingStoryEntryListRepository;
use App\Repository\TrendingStoryListQuery;
use App\Repository\TrendingStoryRepository;

/** Marks read every unread member of a story the reader owns no newer than $until, the rows its unread list shows. */
final readonly class TrendingStoryMarkReadService
{
    public function __construct(
        private TrendingStoryRepository $stories,
        private TrendingStoryEntryListRepository $members,
        private EntryReadMarker $readMarker,
    ) {
    }

    public function mark(TrendingStoryListQuery $story, \DateTimeImmutable $until): void
    {
        $this->stories->requireOwnedBy($story->userId, $story->storyId);
        $this->readMarker->markEntriesRead($story->userId, $this->members->unreadMemberIdsUpTo($story, $until));
    }
}
