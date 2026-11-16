<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** A story the reader follows, as they see it: $headline is null once none of its members is in their feeds. */
final readonly class FollowedStoryRowModel
{
    public function __construct(
        public int $storyId,
        public ?string $headline,
        public int $newCount,
        public \DateTimeImmutable $lastJoinedAt,
    ) {
    }
}
