<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Trending\Model\ReaderWindowModel;

/** Sets a reader's Trending threshold from how many of their feeds posted within the window (D38). */
final readonly class ReaderWindows
{
    public function __construct(
        private TrendingEntryRepository $windowEntries,
        private NaiveUtcClock $clock,
    ) {
    }

    public function of(int $userId, TrendingWindow $window): ReaderWindowModel
    {
        $since = $window->startBefore($this->clock->now());

        return new ReaderWindowModel(
            $userId,
            $window,
            $window->minimumOutletsFor($this->windowEntries->activeFeedCount($userId, $since)),
        );
    }
}
