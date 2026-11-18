<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryBuildPolicy;

use App\Repository\TrendingStoryHistoryRepository;
use App\Service\Curation\Trending\FallbackTitles;
use App\Service\Curation\Trending\Model\StoredStoryModel;
use App\Service\Curation\Trending\Model\StoryAssignmentModel;
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

    /** An unchanged story keeps its fallback unscored: scoring every story made the week build ~16 % slower. */
    public function match(TrendingRun $run, array $groups, array $stored): StoryMatchModel
    {
        return $this->fallbackTitles->titled(
            $run,
            $this->matching->reshape($groups, $stored),
            $stored,
            static fn (StoryAssignmentModel $assignment, StoredStoryModel $story): bool
                => $story->keepsItsFallbackAgainst($assignment->group),
        );
    }

    public function prune(StoryBuildModel $build): int
    {
        return $this->history->pruneUnfollowedOutside($build);
    }
}
