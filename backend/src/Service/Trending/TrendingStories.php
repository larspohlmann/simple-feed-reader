<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Model\TrendingUnopenedModel;
use App\Service\Trending\Model\TrendingPageModel;
use App\Service\Trending\Model\TrendingStoryCardModel;
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
            $this->withLeadCopies($userId, $this->sourceFor($userId, $ranked)->cardsOf($ranked)),
        );
    }

    /** Runs no query for a reader who switched Trending off (D39). */
    public function unopenedFor(User $user, TrendingWindow $window): TrendingUnopenedModel
    {
        if (!$user->getPreferences()->isTrendingEnabled()) {
            return new TrendingUnopenedModel($window, 0);
        }

        $userId = $user->requireId();
        $storyIds = RankedStoryModel::storyIdsOf($this->ranked($userId, $window));

        return new TrendingUnopenedModel(
            $window,
            \count($storyIds) - \count($this->stories->viewedStoryIds($userId, $storyIds)),
        );
    }

    /** @return list<RankedStoryModel> */
    private function ranked(int $userId, TrendingWindow $window): array
    {
        return $this->stories->rankedForUser($this->readerWindows->of($userId, $window), self::MAX_STORIES);
    }

    /** @param list<RankedStoryModel> $ranked */
    private function sourceFor(int $userId, array $ranked): StoryCardSource
    {
        $storyIds = RankedStoryModel::storyIdsOf($ranked);
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
     * Every other subscribed copy of a lead's article is shown among its outlets, inside the story or not.
     *
     * @param list<TrendingStoryCardModel> $cards
     *
     * @return list<TrendingStoryCardModel>
     */
    private function withLeadCopies(int $userId, array $cards): array
    {
        $urlHashes = array_values(array_unique(array_filter(array_map(
            static fn (TrendingStoryCardModel $card): ?string => $card->lead->entry->getUrlHash(),
            $cards,
        ))));

        $copiesByUrlHash = [];
        $copies = $this->entryList->rowsByUrlHashesForUser($userId, $urlHashes);
        foreach ($this->enricher->enrich($copies, $userId) as $copy) {
            $copiesByUrlHash[(string) $copy->entry->getUrlHash()][] = $copy;
        }

        return array_map(
            static fn (TrendingStoryCardModel $card): TrendingStoryCardModel => $card->withCopies(
                $copiesByUrlHash[$card->lead->entry->getUrlHash() ?? ''] ?? [],
            ),
            $cards,
        );
    }
}
