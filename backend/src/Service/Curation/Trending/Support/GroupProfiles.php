<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Model\GroupProfileModel;
use App\Service\Curation\Trending\Pass\TrendingRun;

final class GroupProfiles
{
    /**
     * @param non-empty-list<int> $entryIds
     */
    public static function build(TrendingRun $run, array $entryIds): GroupProfileModel
    {
        $memberCount = \count($entryIds);
        $membersPerStem = [];
        $times = [];
        foreach ($entryIds as $entryId) {
            $times[] = $run->readEffectiveTime($entryId);
            foreach ($run->headlines->collectHeadlineStemIds($entryId) as $stemId) {
                $membersPerStem[$stemId] = ($membersPerStem[$stemId] ?? 0) + 1;
            }
        }

        $stemWeights = [];
        foreach ($membersPerStem as $stemId => $members) {
            if ($members * 3 > $memberCount) {
                $stemWeights[$stemId] = $members / $memberCount * $run->headlines->computeRarity($stemId);
            }
        }

        return new GroupProfileModel($stemWeights, min($times), max($times));
    }

    private function __construct()
    {
    }
}
