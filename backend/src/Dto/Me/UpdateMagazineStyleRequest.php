<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Service\Reader\MagazineStyle;

/**
 * Its own request, not a field on UpdatePreferencesRequest: folding it in would
 * make every scrape-fallback write resend the style, the coupling #180 refused.
 * The promoted enum type answers a bad value with a 422, so no Assert is needed.
 */
final readonly class UpdateMagazineStyleRequest
{
    public function __construct(
        public MagazineStyle $magazineStyle,
    ) {
    }
}
