<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ReadingSettingsRepository;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\ShownRowModel;
use App\Service\Curation\Trending\Model\TrendingUnopenedModel;
use App\Service\Curation\Trending\Model\TrendingPageModel;
use App\Service\Curation\Trending\Pass\StoryCardSource;
use App\Service\Foundation\Url\UrlNormalizer;

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
        private ReadingSettingsRepository $readingSettings,
        private UrlNormalizer $urlNormalizer,
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

    /** Runs no query for a reader who switched Trending off (D39). */
    public function unopenedFor(User $user, TrendingWindow $window): TrendingUnopenedModel
    {
        if (!$this->readingSettings->forUser($user)->isTrendingEnabled()) {
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
        return $this->readerWindows->shownStories($userId, $window);
    }

    /** @param list<RankedStoryModel> $ranked */
    private function sourceFor(int $userId, array $ranked): StoryCardSource
    {
        $storyIds = RankedStoryModel::storyIdsOf($ranked);
        $entryIdsByStory = $this->stories->shownEntryIdsByStory($userId, $storyIds);

        return new StoryCardSource(
            $entryIdsByStory,
            $this->rowsByEntryId($userId, array_merge([], ...array_values($entryIdsByStory))),
            array_flip($this->stories->viewedStoryIds($userId, $storyIds)),
        );
    }

    /**
     * Every row is indexed, a copy the list folded into another row's duplicates too, with the article it is about.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, ShownRowModel>
     */
    private function rowsByEntryId(int $userId, array $entryIds): array
    {
        $rowsByEntryId = [];
        foreach ($this->enricher->enrich($this->entryList->rowsByIdsForUser($userId, $entryIds), $userId) as $row) {
            foreach ([$row, ...$row->duplicates] as $shown) {
                $rowsByEntryId[$shown->entry->requireId()] = new ShownRowModel(
                    $shown->withDuplicates([]),
                    $this->articleKeyOf($shown),
                );
            }
        }

        return $rowsByEntryId;
    }

    /** An outlet's row is about its own article, a post's about the article its first card links. */
    private function articleKeyOf(EntryListRow $row): string
    {
        $articleHash = $row->isFromAnOutlet()
            ? $row->entry->getUrlHash()
            : $this->urlNormalizer->hash($row->enrichment->linkCards[0]->url ?? null);

        return $articleHash ?? 'entry ' . $row->entry->requireId();
    }
}
