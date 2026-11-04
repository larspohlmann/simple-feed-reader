<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One entry of a story; feed_id is copied so a reader's outlet count needs no join through entry. */
#[ORM\Entity]
#[ORM\Table(name: 'trending_story_entry')]
#[ORM\Index(name: 'idx_trending_story_entry_feed', columns: ['feed_id'])]
final class TrendingStoryEntry
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: TrendingStory::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public readonly TrendingStory $story;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public readonly Entry $entry;

    #[ORM\Column(name: 'feed_id')]
    public readonly int $feedId;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(TrendingStory $story, Entry $entry)
    {
        $this->story = $story;
        $this->entry = $entry;
        $this->feedId = $entry->getFeed()->requireId();
    }
}
