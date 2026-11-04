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
        $rowsByEntryId = $this->rowsByEntryId($userId, array_merge([], ...array_values($entryIdsByStory)));

        return new StoryCardSource(
            $entryIdsByStory,
            $rowsByEntryId,
            array_flip($this->stories->viewedStoryIds($userId, $storyIds)),
            $this->copiesByUrlHash($userId, $rowsByEntryId),
        );
    }

    /**
     * Every outlet is a row of its own: a copy the list folded into another row's duplicates is indexed too.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, EntryListRow>
     */
    private function rowsByEntryId(int $userId, array $entryIds): array
    {
        $rowsByEntryId = [];
        foreach ($this->enricher->enrich($this->entryList->rowsByIdsForUser($userId, $entryIds), $userId) as $row) {
            foreach ([$row, ...$row->duplicates] as $outlet) {
                $rowsByEntryId[$outlet->entry->requireId()] = $outlet->withDuplicates([]);
            }
        }

        return $rowsByEntryId;
    }

    /**
     * @param array<int, EntryListRow> $rowsByEntryId
     *
     * @return array<string, list<EntryListRow>>
     */
    private function copiesByUrlHash(int $userId, array $rowsByEntryId): array
    {
        $urlHashes = array_values(array_unique(array_filter(array_map(
            static fn (EntryListRow $row): ?string => $row->entry->getUrlHash(),
            array_values($rowsByEntryId),
        ))));

        $copiesByUrlHash = [];
        $copies = $this->entryList->rowsByUrlHashesForUser($userId, $urlHashes);
        foreach ($this->enricher->enrich($copies, $userId) as $copy) {
            $copiesByUrlHash[(string) $copy->entry->getUrlHash()][] = $copy;
        }

        return $copiesByUrlHash;
    }
}
