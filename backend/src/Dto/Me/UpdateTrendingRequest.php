<?php

declare(strict_types=1);

namespace App\Dto\Me;

/** No default, as UpdatePreferencesRequest: an empty body must never switch Trending off quietly. */
final readonly class UpdateTrendingRequest
{
    public function __construct(
        public bool $trendingEnabled,
    ) {
    }
}
