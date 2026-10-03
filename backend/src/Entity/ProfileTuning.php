<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** The stored schedule and history caps of profile generation, embedded in the settings row. */
#[ORM\Embeddable]
final class ProfileTuning
{
    public const int DEFAULT_KEPT_CAP = 40;
    public const int DEFAULT_VIEWED_CAP = 80;

    /** How often a profile run starts on its own; null means only by hand. */
    #[ORM\Column(name: 'profile_interval_hours', nullable: true)]
    private ?int $intervalHours;

    #[ORM\Column(name: 'kept_cap', options: ['default' => self::DEFAULT_KEPT_CAP])]
    private int $keptCap;

    #[ORM\Column(name: 'viewed_cap', options: ['default' => self::DEFAULT_VIEWED_CAP])]
    private int $viewedCap;

    public function __construct(?int $intervalHours, int $keptCap, int $viewedCap)
    {
        $this->intervalHours = $intervalHours;
        $this->keptCap = $keptCap;
        $this->viewedCap = $viewedCap;
    }

    public static function defaults(): self
    {
        return new self(null, self::DEFAULT_KEPT_CAP, self::DEFAULT_VIEWED_CAP);
    }

    public function getIntervalHours(): ?int
    {
        return $this->intervalHours;
    }

    public function getKeptCap(): int
    {
        return $this->keptCap;
    }

    public function getViewedCap(): int
    {
        return $this->viewedCap;
    }
}
