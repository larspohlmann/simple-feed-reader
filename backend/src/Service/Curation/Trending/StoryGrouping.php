<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\Pass\LinkedPairs;
use App\Service\Curation\Trending\Pass\ProfileMerges;
use App\Service\Curation\Trending\Pass\StoryGroups;
use App\Service\Curation\Trending\Pass\TrendingRun;

final readonly class StoryGrouping
{
    /**
     * @param list<StoryLinkModel> $links
     *
     * @return list<non-empty-list<int>> every group of two or more entries
     */
    public function group(TrendingRun $run, array $links): array
    {
        usort($links, StoryLinkModel::compare(...));
        $groups = new StoryGroups($run, LinkedPairs::fromLinks($links));
        foreach ($links as $link) {
            $groups->consider($link);
        }

        return new ProfileMerges($run, $groups->groups())->groups();
    }
}
