<?php

declare(strict_types=1);

namespace App\Service\Subscription;

/**
 * Colour and icon applied to a tag ONLY at the moment it is created. A tag the
 * user already owns keeps whatever styling they gave it — the catalog never
 * overwrites a customised tag.
 */
final readonly class TagStyle
{
    public function __construct(
        public ?string $color = null,
        public ?string $icon = null,
    ) {
    }
}
