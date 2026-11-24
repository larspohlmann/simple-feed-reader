<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Handedness;
use App\Enum\MagazineStyle;
use App\Enum\SidebarStyle;
use App\Enum\Theme;

/** Which reading settings to change; a null one stays as it is. */
final readonly class ReadingSettingsChange
{
    public function __construct(
        public ?bool $scrapeFallbackEnabled = null,
        public ?MagazineStyle $magazineStyle = null,
        public ?SidebarStyle $sidebarStyle = null,
        public ?Theme $theme = null,
        public ?Handedness $handedness = null,
        public ?bool $trendingEnabled = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->scrapeFallbackEnabled
            && null === $this->magazineStyle
            && null === $this->sidebarStyle
            && null === $this->theme
            && null === $this->handedness
            && null === $this->trendingEnabled;
    }
}
