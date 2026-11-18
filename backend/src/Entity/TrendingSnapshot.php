<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrendingWindow;
use App\Repository\TrendingSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** When a window was last grouped for one reader, and what it cost (#2017). The rebuild writes it through DBAL. */
#[ORM\Entity(repositoryClass: TrendingSnapshotRepository::class)]
#[ORM\Table(name: 'trending_snapshot')]
final class TrendingSnapshot
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Id]
    #[ORM\Column(name: 'time_window', length: 8)]
    private string $window;

    #[ORM\Column(name: 'built_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $builtAt;

    #[ORM\Column(name: 'entry_count')]
    private int $entryCount;

    #[ORM\Column(name: 'active_feed_count')]
    private int $activeFeedCount;

    #[ORM\Column(name: 'story_count')]
    private int $storyCount;

    #[ORM\Column(name: 'duration_ms')]
    private int $durationMilliseconds;

    #[ORM\Column(name: 'peak_memory_bytes', type: Types::BIGINT)]
    private int $peakMemoryBytes;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        User $user,
        TrendingWindow $window,
        \DateTimeImmutable $builtAt,
        int $entryCount,
        int $activeFeedCount,
        int $storyCount,
        int $durationMilliseconds,
        int $peakMemoryBytes,
    ) {
        $this->user = $user;
        $this->window = $window->value;
        $this->builtAt = $builtAt;
        $this->entryCount = $entryCount;
        $this->activeFeedCount = $activeFeedCount;
        $this->storyCount = $storyCount;
        $this->durationMilliseconds = $durationMilliseconds;
        $this->peakMemoryBytes = $peakMemoryBytes;
    }

    public function getWindow(): TrendingWindow
    {
        return TrendingWindow::from($this->window);
    }

    public function getBuiltAt(): \DateTimeImmutable
    {
        return $this->builtAt;
    }

    public function getEntryCount(): int
    {
        return $this->entryCount;
    }

    public function getActiveFeedCount(): int
    {
        return $this->activeFeedCount;
    }

    public function getStoryCount(): int
    {
        return $this->storyCount;
    }

    public function getDurationMilliseconds(): int
    {
        return $this->durationMilliseconds;
    }

    public function getPeakMemoryBytes(): int
    {
        return $this->peakMemoryBytes;
    }
}
