<?php

declare(strict_types=1);

namespace App\Service\Trending\Pass;

use App\Repository\EntryListRow;
use App\Service\Trending\Model\RankedStoryModel;
use App\Service\Trending\Model\TrendingStoryCardModel;

/** The four lookups one reader's cards are cut from. */
final readonly class StoryCardSource
{
    /**
     * @param array<int, list<int>> $entryIdsByStory
     * @param array<int, EntryListRow> $rowsByEntryId
     * @param array<int, int> $viewedStories
     * @param array<string, list<EntryListRow>> $copiesByUrlHash every subscribed copy of an article, by its url hash
     */
    public function __construct(
        private array $entryIdsByStory,
        private array $rowsByEntryId,
        private array $viewedStories,
        private array $copiesByUrlHash,
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
            $card = TrendingStoryCardModel::fromRows(
                $rows,
                $story->outletCount,
                isset($this->viewedStories[$story->storyId]),
            );
            $cards[] = $card->withCopies($this->copiesOf($card->lead));
        }

        return $cards;
    }

    /** @return list<EntryListRow> */
    private function copiesOf(EntryListRow $row): array
    {
        return $this->copiesByUrlHash[$row->entry->getUrlHash() ?? ''] ?? [];
    }
}
