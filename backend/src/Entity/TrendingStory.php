<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrendingWindow;
use App\Repository\TrendingStoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One story of a window's snapshot. A rebuild deletes and re-creates them: ids are not stable (#2017). */
#[ORM\Entity(repositoryClass: TrendingStoryRepository::class)]
#[ORM\Table(name: 'trending_story')]
#[ORM\Index(name: 'idx_trending_story_window', columns: ['time_window'])]
final class TrendingStory
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'time_window', length: 8, enumType: TrendingWindow::class)]
    private TrendingWindow $window;

    #[ORM\Column(name: 'first_seen', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeen;

    #[ORM\Column(name: 'last_seen', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeen;

    public function __construct(
        TrendingWindow $window,
        \DateTimeImmutable $firstSeen,
        \DateTimeImmutable $lastSeen,
    ) {
        $this->window = $window;
        $this->firstSeen = $firstSeen;
        $this->lastSeen = $lastSeen;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
