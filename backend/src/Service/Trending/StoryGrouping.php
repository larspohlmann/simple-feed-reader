<?php

declare(strict_types=1);

namespace App\Service\Trending;

use App\Service\Trending\Model\StoryLinkModel;
use App\Service\Trending\Pass\StoryGroups;
use App\Service\Trending\Pass\TrendingRun;

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
        $groups = new StoryGroups($run, $links);
        foreach ($links as $link) {
            $groups->consider($link);
        }

        return $groups->groups();
    }
}
