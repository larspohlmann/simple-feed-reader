<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Entity\ReadingSettingsChange;
use App\Enum\Handedness;
use App\Enum\MagazineStyle;
use App\Enum\SidebarStyle;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdatePreferencesRequest
{
    public function __construct(
        public ?bool $scrapeFallbackEnabled = null,
        public ?MagazineStyle $magazineStyle = null,
        public ?SidebarStyle $sidebarStyle = null,
        public ?Handedness $handedness = null,
        public ?bool $trendingEnabled = null,
    ) {
    }

    #[Assert\IsTrue(message: 'Name at least one setting to change.')]
    public function hasSettingToChange(): bool
    {
        return !$this->toChange()->isEmpty();
    }

    public function toChange(): ReadingSettingsChange
    {
        return new ReadingSettingsChange(
            scrapeFallbackEnabled: $this->scrapeFallbackEnabled,
            magazineStyle: $this->magazineStyle,
            sidebarStyle: $this->sidebarStyle,
            handedness: $this->handedness,
            trendingEnabled: $this->trendingEnabled,
        );
    }
}
