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
    private TrendingStory $story;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(name: 'feed_id')]
    private int $feedId;

    public function __construct(TrendingStory $story, Entry $entry)
    {
        $this->story = $story;
        $this->entry = $entry;
        $this->feedId = $entry->getFeed()->requireId();
    }

    public function getStory(): TrendingStory
    {
        return $this->story;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getFeedId(): int
    {
        return $this->feedId;
    }
}
