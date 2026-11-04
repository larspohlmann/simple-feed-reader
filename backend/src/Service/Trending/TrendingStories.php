<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Model\TrendingCountsModel;
use App\Service\Trending\Model\TrendingPageModel;
use App\Service\Trending\Pass\StoryCardSource;

/** One reader's Trending: the shared snapshot cut to their subscriptions and their threshold (#2017). */
final readonly class TrendingStories
{
    public const int MAX_STORIES = 15;

    public function __construct(
        private TrendingStoryRepository $stories,
        private ReaderWindows $readerWindows,
        private TrendingSnapshotRepository $snapshots,
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
    ) {
    }

    public function forReader(int $userId, TrendingWindow $window): TrendingPageModel
    {
        $ranked = $this->ranked($userId, $window);

        return new TrendingPageModel(
            $window,
            $this->snapshots->lastBuilds()->builtAt($window),
            $this->sourceFor($userId, $ranked)->cardsOf($ranked),
        );
    }

    public function unopenedCounts(int $userId): TrendingCountsModel
    {
        return new TrendingCountsModel(
            $this->unopenedIn($userId, TrendingWindow::Day),
            $this->unopenedIn($userId, TrendingWindow::Week),
        );
    }

    private function unopenedIn(int $userId, TrendingWindow $window): int
    {
        $storyIds = self::storyIdsOf($this->ranked($userId, $window));

        return \count($storyIds) - \count($this->stories->viewedStoryIds($userId, $storyIds));
    }

    /** @return list<RankedStoryModel> */
    private function ranked(int $userId, TrendingWindow $window): array
    {
        return $this->stories->rankedForUser($this->readerWindows->of($userId, $window), self::MAX_STORIES);
    }

    /**
     * @param list<RankedStoryModel> $ranked
     *
     * @return list<int>
     */
    private static function storyIdsOf(array $ranked): array
    {
        return array_map(static fn (RankedStoryModel $story): int => $story->storyId, $ranked);
    }

    /** @param list<RankedStoryModel> $ranked */
    private function sourceFor(int $userId, array $ranked): StoryCardSource
    {
        $storyIds = self::storyIdsOf($ranked);
        $entryIdsByStory = $this->stories->articleEntryIdsByStory($userId, $storyIds);

        return new StoryCardSource(
            $entryIdsByStory,
            $this->rowsByEntryId($userId, array_merge([], ...array_values($entryIdsByStory))),
            array_flip($this->stories->viewedStoryIds($userId, $storyIds)),
        );
    }

    /**
     * Every outlet is a row of its own: a copy the list folded into another row's duplicates is indexed too.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, EntryListRow> newest first
     */
    private function rowsByEntryId(int $userId, array $entryIds): array
    {
        $rows = [];
        foreach ($this->enricher->enrich($this->entryList->rowsByIdsForUser($userId, $entryIds), $userId) as $row) {
            $rows[] = $row->withDuplicates([]);
            foreach ($row->duplicates as $duplicate) {
                $rows[] = $duplicate->withDuplicates([]);
            }
        }
        usort($rows, static fn (EntryListRow $left, EntryListRow $right): int => [
            $right->entry->getEffectiveDate(),
            $right->entry->requireId(),
        ] <=> [$left->entry->getEffectiveDate(), $left->entry->requireId()]);

        $rowsByEntryId = [];
        foreach ($rows as $row) {
            $rowsByEntryId[$row->entry->requireId()] = $row;
        }

        return $rowsByEntryId;
    }
}
