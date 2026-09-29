<?php

declare(strict_types=1);

namespace App\Dto\Me;

/** No default: an empty body must never switch the preference off quietly. */
final readonly class UpdatePreferencesRequest
{
    public function __construct(
        public bool $scrapeFallbackEnabled,
    ) {
    }
}
