<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Curation\Trending\Model\FollowedStoriesModel;
use App\Service\Curation\Trending\Model\FollowedStoryModel;

final class FollowedStoryJson
{
    /** @return array{stories: list<array<string, mixed>>, nextBuildDueAt: string|null} */
    public static function list(FollowedStoriesModel $followed): array
    {
        return [
            'stories' => array_map(self::story(...), $followed->stories),
            'nextBuildDueAt' => $followed->nextBuildDueAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function story(FollowedStoryModel $story): array
    {
        return [
            'id' => $story->row->storyId,
            'headline' => $story->row->headline,
            'newCount' => $story->row->newCount,
            'quiet' => $story->quiet,
            'lastJoinedAt' => $story->row->lastJoinedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
