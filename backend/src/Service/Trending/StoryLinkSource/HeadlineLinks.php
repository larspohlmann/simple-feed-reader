<?php

declare(strict_types=1);

namespace App\Service\Trending\StoryLinkSource;

use App\Service\Trending\Model\HeadlineOverlapModel;
use App\Service\Trending\Model\StoryLinkModel;
use App\Service\Trending\Pass\TrendingRun;

/** Two entries whose headlines overlap enough: about three ordinary stems or two rare ones (#2017). */
final readonly class HeadlineLinks implements StoryLinkSourceInterface
{
    private const float MIN_SHARED_WEIGHT = 14.0;
    private const float MIN_SHARE_OF_LIGHTER = 0.4;

    public static function key(): string
    {
        return 'headline';
    }

    public function links(TrendingRun $run): array
    {
        $links = [];
        foreach ($run->entryIds() as $entryId) {
            foreach ($this->overlapsWithEarlier($run, $entryId) as $overlap) {
                if ($run->mayLink($overlap->earlierEntryId, $overlap->entryId) && $this->admits($overlap)) {
                    $links[] = new StoryLinkModel($overlap->earlierEntryId, $overlap->entryId, $overlap->sharedWeight);
                }
            }
        }

        return $links;
    }

    public function admits(HeadlineOverlapModel $overlap): bool
    {
        return $this->weighsEnough($overlap) && $this->sharesEnough($overlap);
    }

    /** @return list<HeadlineOverlapModel> one per earlier entry sharing a headline stem with this one */
    private function overlapsWithEarlier(TrendingRun $run, int $entryId): array
    {
        $overlaps = [];
        foreach ($run->headlines->sharedWeightsWithEarlier($entryId) as $earlierEntryId => $sharedWeight) {
            $overlaps[] = new HeadlineOverlapModel(
                $earlierEntryId,
                $entryId,
                $sharedWeight,
                self::lighterTotal($run, $earlierEntryId, $entryId),
            );
        }

        return $overlaps;
    }

    private static function lighterTotal(TrendingRun $run, int $oneEntryId, int $otherEntryId): float
    {
        return min($run->headlines->totalWeight($oneEntryId), $run->headlines->totalWeight($otherEntryId));
    }

    private function weighsEnough(HeadlineOverlapModel $overlap): bool
    {
        return $overlap->sharedWeight >= self::MIN_SHARED_WEIGHT;
    }

    private function sharesEnough(HeadlineOverlapModel $overlap): bool
    {
        return $overlap->shareOfLighter() >= self::MIN_SHARE_OF_LIGHTER;
    }
}
