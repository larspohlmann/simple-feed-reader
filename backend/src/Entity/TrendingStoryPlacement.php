<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrendingWindow;
use Doctrine\ORM\Mapping as ORM;

/** The story a window shows an entry in (#2272): in one window an entry sits in one story. Written through DBAL. */
#[ORM\Entity]
#[ORM\Table(name: 'trending_story_placement')]
final class TrendingStoryPlacement
{
    #[ORM\Id]
    #[ORM\Column(name: 'time_window', length: 8)]
    public readonly string $window;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public readonly Entry $entry;

    #[ORM\ManyToOne(targetEntity: TrendingStory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public readonly TrendingStory $story;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(TrendingWindow $window, Entry $entry, TrendingStory $story)
    {
        $this->window = $window->value;
        $this->entry = $entry;
        $this->story = $story;
    }
}
