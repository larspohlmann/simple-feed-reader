<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** A followed story as the reader sees it: $headline is null only when it is untitled and no member is in their feeds. */
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
