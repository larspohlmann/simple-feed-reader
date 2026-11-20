<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Entity\User;
use App\Enum\TrendingWindow;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\ReadingSettingsRepository;
use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryFollowRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\ShownEntriesModel;
use App\Service\Curation\Trending\Model\ShownRowModel;
use App\Service\Curation\Trending\Model\TrendingPageModel;
use App\Service\Curation\Trending\Model\TrendingUnopenedModel;
use App\Service\Curation\Trending\Pass\StoryCardSource;
use App\Service\Library\EntryList\EntryListRowEnricher;

/** One reader's Trending: their own snapshot cut to their threshold (#2017, #2328). */
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
        private TrendingStoryFollowRepository $follows,
    ) {
    }

    public function forReader(int $userId, TrendingWindow $window): TrendingPageModel
    {
        $ranked = $this->ranked($userId, $window);

        return new TrendingPageModel(
            $window,
            $this->snapshots->lastBuilds($userId)->builtAt($window),
            $this->sourceFor($userId, $window, $ranked)->buildCards($ranked),
        );
    }

    /** Runs no query for a reader who switched Trending off (D39). */
    public function unopenedFor(User $user, TrendingWindow $window): TrendingUnopenedModel
    {
        if (!$this->readingSettings->forUser($user)->isTrendingEnabled()) {
            return new TrendingUnopenedModel($window, 0);
        }

        $userId = $user->requireId();
        $storyIds = RankedStoryModel::collectStoryIds($this->ranked($userId, $window));

        return new TrendingUnopenedModel(
            $window,
            \count($storyIds) - \count($this->stories->viewedStoryIds($userId, $window, $storyIds)),
        );
    }

    /** @return list<RankedStoryModel> */
    private function ranked(int $userId, TrendingWindow $window): array
    {
        return $this->readerWindows->shownStories($userId, $window);
    }

    /** @param list<RankedStoryModel> $ranked */
    private function sourceFor(int $userId, TrendingWindow $window, array $ranked): StoryCardSource
    {
        $storyIds = RankedStoryModel::collectStoryIds($ranked);
        $shown = $this->stories->shownEntriesByStory($window, $storyIds);

        return new StoryCardSource(
            $shown->entryIdsByStory(),
            $this->rowsByEntryId($userId, $shown),
            array_flip($this->stories->viewedStoryIds($userId, $window, $storyIds)),
            array_flip($this->follows->followedStoryIds($userId, $storyIds)),
            $this->stories->findHeadings($storyIds),
        );
    }

    /**
     * Every row is indexed, a copy the list folded into another row's duplicates too, with the article it is about.
     *
     * @return array<int, ShownRowModel>
     */
    private function rowsByEntryId(int $userId, ShownEntriesModel $shownEntries): array
    {
        $rows = $this->entryList->rowsByIdsForUser($userId, $shownEntries->entryIds());
        $rowsByEntryId = [];
        foreach ($this->enricher->enrich($rows, $userId) as $row) {
            $rowId = $row->entry->requireId();
            foreach ([$row, ...$row->duplicates] as $shown) {
                $shownId = $shown->entry->requireId();
                $rowsByEntryId[$shownId] = new ShownRowModel(
                    $shown->withDuplicates([]),
                    self::buildArticleKey($shown, $shownEntries->resolveLinkHash($shownId, $rowId)),
                );
            }
        }

        return $rowsByEntryId;
    }

    /** An outlet's row is about its own article, a post's about the shared link its first card names. */
    private static function buildArticleKey(EntryListRow $row, ?string $linkHash): string
    {
        return $row->keyBy($row->isFromAnOutlet() ? $row->entry->getUrlHash() : $linkHash);
    }
}
