<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\ShownRowModel;
use App\Service\Curation\Trending\Model\StoryTitleModel;
use App\Service\Curation\Trending\Model\TrendingStoryCardModel;

/** The five lookups one reader's cards are cut from. */
final readonly class StoryCardSource
{
    /**
     * @param array<int, list<int>> $entryIdsByStory
     * @param array<int, ShownRowModel> $rowsByEntryId
     * @param array<int, int> $viewedStories
     * @param array<int, int> $followedStories
     * @param array<int, StoryTitleModel> $titlesByStory
     */
    public function __construct(
        private array $entryIdsByStory,
        private array $rowsByEntryId,
        private array $viewedStories,
        private array $followedStories,
        private array $titlesByStory,
    ) {
    }

    /**
     * @param list<RankedStoryModel> $ranked
     *
     * @return list<TrendingStoryCardModel> a story with no row the reader can see is left out
     */
    public function cardsOf(array $ranked): array
    {
        $cards = [];
        foreach ($ranked as $story) {
            $rows = array_values(array_intersect_key(
                $this->rowsByEntryId,
                array_flip($this->entryIdsByStory[$story->storyId] ?? []),
            ));
            if ($rows === []) {
                continue;
            }
            $cards[] = TrendingStoryCardModel::fromRows(
                $rows,
                $story->titled($this->titlesByStory[$story->storyId] ?? null),
                isset($this->viewedStories[$story->storyId]),
                isset($this->followedStories[$story->storyId]),
            );
        }

        return $cards;
    }
}
