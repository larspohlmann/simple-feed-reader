<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryBuildPolicy;

use App\Service\Curation\Trending\Model\StoryBuildModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\StoryMatching;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'day')]
final readonly class DayStoryBuildPolicy implements StoryBuildPolicyInterface
{
    public function __construct(private StoryMatching $matching)
    {
    }

    public function match(array $groups, array $stored): StoryMatchModel
    {
        return $this->matching->attach($groups, $stored);
    }

    public function prune(StoryBuildModel $build): int
    {
        return 0;
    }
}
