<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrendingStoryHideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A Trending story the reader hid (#2128): it stays out of their list for as long as it exists. */
#[ORM\Entity(repositoryClass: TrendingStoryHideRepository::class)]
#[ORM\Table(name: 'trending_story_hide')]
final class TrendingStoryHide
{
    // No `nullable: false` on these identifier join columns: Doctrine forces them NOT NULL and deprecates saying so.
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public readonly User $user;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: TrendingStory::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public readonly TrendingStory $story;

    #[ORM\Column(name: 'hidden_at', type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $hiddenAt;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, TrendingStory $story, \DateTimeImmutable $hiddenAt)
    {
        $this->user = $user;
        $this->story = $story;
        $this->hiddenAt = $hiddenAt;
    }
}
