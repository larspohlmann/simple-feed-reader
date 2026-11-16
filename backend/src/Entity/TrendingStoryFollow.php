<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrendingStoryFollowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A Trending story the reader follows (#2128); what joined after $lastOpenedAt is new to them. */
#[ORM\Entity(repositoryClass: TrendingStoryFollowRepository::class)]
#[ORM\Table(name: 'trending_story_follow')]
final class TrendingStoryFollow
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

    #[ORM\Column(name: 'followed_at', type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $followedAt;

    #[ORM\Column(name: 'last_opened_at', type: Types::DATETIME_IMMUTABLE)]
    public readonly \DateTimeImmutable $lastOpenedAt;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(User $user, TrendingStory $story, \DateTimeImmutable $followedAt)
    {
        $this->user = $user;
        $this->story = $story;
        $this->followedAt = $followedAt;
        $this->lastOpenedAt = $followedAt;
    }
}
