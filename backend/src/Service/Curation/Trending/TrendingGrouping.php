<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Repository\TrendingEntryRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Curation\Trending\Model\StoryMemberModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Pass\HeadlineWindow;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Foundation\Url\UrlNormalizer;

/** Reads one window's entries and groups them into stories, in memory; writing them is the builder's (#2017). */
final readonly class TrendingGrouping
{
    public function __construct(
        private TrendingEntryRepository $windowEntries,
        private UrlNormalizer $urlNormalizer,
        private HeadlineFiller $filler,
        private HeadlineEquivalents $equivalents,
        private StoryLinks $storyLinks,
        private StoryGrouping $storyGrouping,
        private NaiveUtcClock $clock,
    ) {
    }

    public function collect(TrendingWindow $window): TrendingRun
    {
        $run = new TrendingRun($window, new HeadlineWindow($this->filler, $this->equivalents));
        $since = $window->startBefore($this->clock->now());
        foreach ($this->windowEntries->windowEntries($since) as $entry) {
            $run->add($entry, $this->urlHashesOf($entry));
        }

        return $run;
    }

    /** @return list<TrendingStoryModel> every group, however few feeds it spans */
    public function groups(TrendingRun $run): array
    {
        return array_map(
            static fn (array $entryIds): TrendingStoryModel => self::storyOf($run, $entryIds),
            $this->storyGrouping->group($run, $this->storyLinks->between($run)),
        );
    }

    /** @return list<TrendingStoryModel> the groups spanning enough feeds that some reader could see them trend */
    public function stories(TrendingRun $run): array
    {
        return array_values(array_filter(
            $this->groups($run),
            static fn (TrendingStoryModel $story): bool => $story->outletCount() >= $run->window->minSubscribedFeeds(),
        ));
    }

    /** @return list<string> */
    private function urlHashesOf(WindowEntryModel $entry): array
    {
        return array_values(array_unique(array_filter(
            [$entry->urlHash, $this->urlNormalizer->hash($entry->linkCardUrl)],
            static fn (?string $urlHash): bool => $urlHash !== null,
        )));
    }

    /** @param non-empty-list<int> $entryIds */
    private static function storyOf(TrendingRun $run, array $entryIds): TrendingStoryModel
    {
        $members = array_map(
            static fn (int $entryId): StoryMemberModel => new StoryMemberModel($entryId, $run->feedOf($entryId)),
            $entryIds,
        );
        $times = array_map($run->effectiveTimeOf(...), $entryIds);

        return new TrendingStoryModel($members, self::instant(min($times)), self::instant(max($times)));
    }

    private static function instant(int $unixSeconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $unixSeconds);
    }
}
