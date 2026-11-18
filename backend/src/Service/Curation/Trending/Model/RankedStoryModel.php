<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class RankedStoryModel
{
    /** @param int $outletCount the reader's sources: their article feeds and the accounts whose posts share a card */
    public function __construct(
        public int $storyId,
        public int $outletCount,
        public \DateTimeImmutable $lastSeen,
        public ?StoryTitleModel $title = null,
    ) {
    }

    #[\NoDiscard]
    public function titled(?StoryTitleModel $title): self
    {
        return clone($this, ['title' => $title]);
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
