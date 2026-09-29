<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * A tag inside one subscription row. No `position`: that orders the owner's tag list ({@see AdminUserTag}), not this
 * attachment. `icon` travels with `color` so the chip draws the glyph the tag list shows.
 */
final readonly class AdminSubscriptionTag
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $color,
        public ?string $icon,
    ) {
    }
}
