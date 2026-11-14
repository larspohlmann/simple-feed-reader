<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class RankedStoryModel
{
    /** @param int $outletCount distinct feeds of this reader in the story, an article URL once, social posts all */
    public function __construct(public int $storyId, public int $outletCount, public \DateTimeImmutable $lastSeen)
    {
    }

    /**
     * @param list<self> $stories
     *
     * @return list<int>
     */
    public static function storyIdsOf(array $stories): array
    {
        return array_map(static fn (self $story): int => $story->storyId, $stories);
    }
}
