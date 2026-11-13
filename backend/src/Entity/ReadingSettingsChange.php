<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Handedness;
use App\Enum\MagazineStyle;

/** Which reading settings to change; a null one stays as it is. */
final readonly class ReadingSettingsChange
{
    public function __construct(
        public ?bool $scrapeFallbackEnabled = null,
        public ?MagazineStyle $magazineStyle = null,
        public ?Handedness $handedness = null,
        public ?bool $trendingEnabled = null,
    ) {
    }
}
