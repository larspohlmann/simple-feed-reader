<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Repository\EntryListRow;
use App\Repository\TrendingStoryEntryListRepository;
use App\Repository\TrendingStoryFollowRepository;
use App\Repository\TrendingStoryListQuery;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFollowedException;
use App\Service\Curation\Trending\Model\FollowedStoryEntriesModel;

/** A followed story's entries, marking on the first page what joined since the reader's last visit. */
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

        return new FollowedStoryEntriesModel($rows, $lastOpenedAt, $this->newEntryIds($query, $rows, $lastOpenedAt));
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int> in page order
     */
    private function newEntryIds(TrendingStoryListQuery $query, array $rows, \DateTimeImmutable $lastOpenedAt): array
    {
        if (!$query->isFirstPage()) {
            return [];
        }

        $rowIds = array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);
        $joined = $this->entries->joinedAfter($query, $rowIds, $lastOpenedAt);

        return array_values(array_intersect($rowIds, $joined));
    }
}
