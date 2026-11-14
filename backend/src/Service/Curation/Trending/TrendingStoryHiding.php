<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingHiddenEntryRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\Model\ReaderWindowModel;

/** Hides a Trending story for one reader, by its entries, so a rebuild that regroups the event cannot bring it back. */
final readonly class TrendingStoryHiding
{
    public function __construct(
        private TrendingStoryRepository $stories,
        private ReaderWindows $readerWindows,
        private TrendingHiddenEntryRepository $hiddenEntries,
        private NaiveUtcClock $clock,
    ) {
    }

    /**
     * Nothing written means the story was hidden before or a rebuild replaced it in between: asked again, with the
     * reader's cut taken afresh, the entry names its new story, or none.
     *
     * @throws TrendingStoryNotFoundException
     */
    public function hide(int $userId, TrendingWindow $window, int $entryId): void
    {
        if ($this->hideStoryHolding($this->readerWindows->of($userId, $window), $entryId) === 0) {
            $this->hideStoryHolding($this->readerWindows->of($userId, $window), $entryId);
        }
    }

    /** @throws TrendingStoryNotFoundException */
    private function hideStoryHolding(ReaderWindowModel $reader, int $entryId): int
    {
        $storyId = $this->stories->storyIdContaining($reader, $entryId);

        return $this->hiddenEntries->hideStory($reader->userId, $storyId, $this->clock->now());
    }
}
