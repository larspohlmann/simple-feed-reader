<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrendingStoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One Trending event, shared by both windows; its members say which windows the latest builds placed it in. */
#[ORM\Entity(repositoryClass: TrendingStoryRepository::class)]
#[ORM\Table(name: 'trending_story')]
final class TrendingStory
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'first_seen', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeen;

    #[ORM\Column(name: 'last_seen', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeen;

    #[ORM\Column(name: 'first_joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstJoinedAt;

    #[ORM\Column(name: 'last_joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastJoinedAt;

    public function __construct(
        \DateTimeImmutable $firstSeen,
        \DateTimeImmutable $lastSeen,
        \DateTimeImmutable $joinedAt,
    ) {
        $this->firstSeen = $firstSeen;
        $this->lastSeen = $lastSeen;
        $this->firstJoinedAt = $joinedAt;
        $this->lastJoinedAt = $joinedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
