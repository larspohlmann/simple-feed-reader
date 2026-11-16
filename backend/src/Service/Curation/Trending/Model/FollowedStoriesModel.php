<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class FollowedStoriesModel
{
    /** @param list<FollowedStoryModel> $stories newest joined first */
    public function __construct(public array $stories, public ?\DateTimeImmutable $nextBuildDueAt)
    {
    }
}
