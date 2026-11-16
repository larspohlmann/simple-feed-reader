<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Repository\EntryListRow;
use App\Repository\TrendingStoryEntryListRepository;
use App\Repository\TrendingStoryFollowRepository;
use App\Repository\TrendingStoryListQuery;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFollowedException;
use App\Service\Curation\Trending\Model\FollowedStoryEntriesModel;

/** Lists a followed story's entries. */
final readonly class FollowedStoryEntries
{
    public function __construct(
        private TrendingStoryFollowRepository $follows,
        private TrendingStoryEntryListRepository $entries,
    ) {
    }

    /** @throws TrendingStoryNotFollowedException */
    public function list(TrendingStoryListQuery $query): FollowedStoryEntriesModel
    {
        $lastOpenedAt = $this->follows->lastOpenedAt($query->userId, $query->storyId);
        $rows = $this->entries->listMembers($query);
        $rowIds = array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);

        return new FollowedStoryEntriesModel($rows, $lastOpenedAt, $this->entries->joinedAtByEntry($query, $rowIds));
    }
}
