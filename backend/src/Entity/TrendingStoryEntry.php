<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One entry of a story, kept once it leaves the windows; feed_id is copied so an outlet count needs no join. */
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

    /** The host of the article the entry is or shares; null for a post whose card names no story. */
    #[ORM\Column(name: 'article_site', length: 255, nullable: true)]
    public readonly ?string $articleSite;

    /** The shared link of the entry's first top-level card when the story was grouped: a post's article key. */
    #[ORM\ManyToOne(targetEntity: SharedLink::class)]
    #[ORM\JoinColumn(name: 'shared_link_id', nullable: true, onDelete: 'SET NULL')]
    public readonly ?SharedLink $link;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $joinedAt;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        TrendingStory $story,
        Entry $entry,
        ?string $articleSite,
        ?SharedLink $link,
        \DateTimeImmutable $joinedAt,
    ) {
        $this->story = $story;
        $this->entry = $entry;
        $this->feedId = $entry->getFeed()->requireId();
        $this->articleSite = $articleSite;
        $this->link = $link;
        $this->joinedAt = $joinedAt;
    }
}
