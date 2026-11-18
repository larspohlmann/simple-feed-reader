<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryBuildPolicy;

use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingStoryModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.trending_story_build_policy')]
interface StoryBuildPolicyInterface
{
    /**
     * @param list<TrendingStoryModel> $groups
     * @param list<StoredStoryModel>   $stored
     */
    public function match(TrendingRun $run, array $groups, array $stored): StoryMatchModel;

    /** @return int the stories pruned */
    public function prune(StoryBuildModel $build): int;
}
