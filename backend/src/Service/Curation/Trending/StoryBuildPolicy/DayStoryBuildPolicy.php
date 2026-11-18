<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryBuildPolicy;

use App\Service\Curation\Trending\FallbackTitles;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\StoryMatching;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'day')]
final readonly class DayStoryBuildPolicy implements StoryBuildPolicyInterface
{
    public function __construct(private StoryMatching $matching, private FallbackTitles $fallbackTitles)
    {
    }

    public function match(TrendingRun $run, array $groups, array $stored): StoryMatchModel
    {
        return $this->fallbackTitles->titledWhereMissing($run, $this->matching->attach($groups, $stored), $stored);
    }

    public function prune(StoryBuildModel $build): int
    {
        return 0;
    }
}
