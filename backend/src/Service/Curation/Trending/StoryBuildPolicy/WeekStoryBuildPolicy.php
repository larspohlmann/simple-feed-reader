<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryBuildPolicy;

use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\StoryMatching;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'week')]
final readonly class WeekStoryBuildPolicy implements StoryBuildPolicyInterface
{
    public function __construct(
        private StoryMatching $matching,
        private TrendingStoryHistoryRepository $history,
    ) {
    }

    public function match(array $groups, array $stored): StoryMatchModel
    {
        return $this->matching->reshape($groups, $stored);
    }

    public function prune(StoryBuildModel $build): int
    {
        return $this->history->pruneUnfollowedOutside($build);
    }
}
