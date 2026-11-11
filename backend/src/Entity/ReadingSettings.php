<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MagazineStyle;
use App\Repository\ReadingSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** One row per account, created with it by UserSettingsRowsListener. */
#[ORM\Entity(repositoryClass: ReadingSettingsRepository::class)]
#[ORM\Table(name: 'user_reading_settings')]
final class ReadingSettings
{
    use KeyedByUser;

    /** Off by default and presented as experimental: scraped extraction breaks whenever the target page changes. */
    #[ORM\Column(options: ['default' => false])]
    private bool $scrapeFallbackEnabled = false;

    #[ORM\Column(length: 10, enumType: MagazineStyle::class, options: ['default' => 'boxed'])]
    private MagazineStyle $magazineStyle = MagazineStyle::Boxed;

    #[ORM\Column(options: ['default' => true])]
    private bool $trendingEnabled = true;

    public function isScrapeFallbackEnabled(): bool
    {
        return $this->scrapeFallbackEnabled;
    }

    public function setScrapeFallbackEnabled(bool $scrapeFallbackEnabled): void
    {
        $this->scrapeFallbackEnabled = $scrapeFallbackEnabled;
    }

    public function getMagazineStyle(): MagazineStyle
    {
        return $this->magazineStyle;
    }

    public function setMagazineStyle(MagazineStyle $magazineStyle): void
    {
        $this->magazineStyle = $magazineStyle;
    }

    public function isTrendingEnabled(): bool
    {
        return $this->trendingEnabled;
    }

    public function setTrendingEnabled(bool $trendingEnabled): void
    {
        $this->trendingEnabled = $trendingEnabled;
    }
}
