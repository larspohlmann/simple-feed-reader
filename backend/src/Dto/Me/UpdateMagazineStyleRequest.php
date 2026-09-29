<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Enum\MagazineStyle;

/** Not a field on UpdatePreferencesRequest, so a scrape-fallback write never resends the style. */
final readonly class UpdateMagazineStyleRequest
{
    public function __construct(
        public MagazineStyle $magazineStyle,
    ) {
    }
}
