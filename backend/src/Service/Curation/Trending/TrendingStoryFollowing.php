<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Repository\TrendingSnapshotRepository;
use App\Repository\TrendingStoryFollowRepository;
use App\Repository\TrendingStoryRepository;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFollowedException;
use App\Service\Curation\Trending\Exception\TrendingStoryNotFoundException;
use App\Service\Curation\Trending\Model\FollowedStoriesModel;
use App\Service\Curation\Trending\Model\FollowedStoryModel;
use App\Service\Curation\Trending\Model\FollowedStoryRowModel;
use App\Service\Foundation\Clock\NaiveUtcClock;

/** A reader's followed Trending stories and what joined each since they last opened it. */
final readonly class TrendingStoryFollowing
{
    private const int QUIET_AFTER_HOURS = 48;

    public function __construct(
        private TrendingStoryRepository $stories,
        private TrendingStoryFollowRepository $follows,
        private TrendingSnapshotRepository $snapshots,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @throws TrendingStoryNotFoundException */
    public function follow(int $userId, int $storyId): void
    {
        $this->stories->requireOwnedBy($userId, $storyId);
        $this->follows->follow($userId, $storyId, $this->clock->now());
    }

    public function unfollow(int $userId, int $storyId): void
    {
        $this->follows->unfollow($userId, $storyId);
    }

    /** @throws TrendingStoryNotFollowedException */
    public function markOpened(int $userId, int $storyId): void
    {
        if ($this->follows->markOpened($userId, $storyId, $this->clock->now()) === 0) {
            $this->follows->requireFollowed($userId, $storyId);
        }
    }

    /** Stories newest joined first, with when the next Trending build is due. */
    public function followedBy(int $userId): FollowedStoriesModel
    {
        $quietSince = $this->clock->now()->modify(\sprintf('-%d hours', self::QUIET_AFTER_HOURS));

        return new FollowedStoriesModel(
            array_map(
                static fn (FollowedStoryRowModel $row): FollowedStoryModel
                    => new FollowedStoryModel($row, $row->lastJoinedAt <= $quietSince),
                $this->follows->followedBy($userId),
            ),
            $this->snapshots->lastBuilds($userId)->nextBuildDueAt(),
        );
    }
}
