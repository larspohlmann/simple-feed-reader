<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Curation\Trending\Model\FollowedStoryModel;

final class FollowedStoryJson
{
    /**
     * @param list<FollowedStoryModel> $stories
     *
     * @return array{stories: list<array<string, mixed>>}
     */
    public static function list(array $stories): array
    {
        return ['stories' => array_map(self::story(...), $stories)];
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
