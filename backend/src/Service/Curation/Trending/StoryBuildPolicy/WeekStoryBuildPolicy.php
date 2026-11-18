<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryBuildPolicy;

use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\FallbackTitles;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use App\Service\Curation\Trending\StoryMatching;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'week')]
final readonly class WeekStoryBuildPolicy implements StoryBuildPolicyInterface
{
    public function __construct(
        private StoryMatching $matching,
        private TrendingStoryHistoryRepository $history,
        private FallbackTitles $fallbackTitles,
    ) {
    }

    public function match(TrendingRun $run, array $groups, array $stored): StoryMatchModel
    {
        return $this->fallbackTitles->titledAll($run, $this->matching->reshape($groups, $stored), $stored);
    }

    public function prune(StoryBuildModel $build): int
    {
        return $this->history->pruneUnfollowedOutside($build);
    }
}
