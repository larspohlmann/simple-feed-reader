<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Repository\EntryListRow;
use App\Repository\TrendingStoryEntryListRepository;
use App\Repository\TrendingStoryFollowRepository;
use App\Repository\TrendingStoryListQuery;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\Model\TrendingStoryEntriesModel;

/** Lists the entries of a story the reader owns, followed or not. */
final readonly class TrendingStoryEntries
{
    public function __construct(
        private TrendingStoryRepository $stories,
        private TrendingStoryFollowRepository $follows,
        private TrendingStoryEntryListRepository $entries,
    ) {
    }

    /** @throws TrendingStoryNotFoundException */
    public function list(TrendingStoryListQuery $query): TrendingStoryEntriesModel
    {
        $this->stories->requireOwnedBy($query->userId, $query->storyId);
        $title = $this->entries->titleOf($query);
        $lastOpenedAt = $this->follows->lastOpenedAtIfFollowed($query->userId, $query->storyId);
        $rows = $this->entries->listMembers($query);
        $rowIds = array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);

        return new TrendingStoryEntriesModel(
            $rows,
            $title,
            $this->entries->tallyOf($query),
            $lastOpenedAt,
            $this->entries->joinedAtByEntry($query, $rowIds),
        );
    }
}
