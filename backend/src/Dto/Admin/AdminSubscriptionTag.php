<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * A tag as it appears embedded in one subscription row — deliberately a
 * narrower shape than {@see AdminUserTag}: no position, because that
 * describes the tag's place in the owner's own tag list, not this particular
 * attachment. `icon` travels with `color` so a subscription's tag chips can
 * render the same glyph the account's own tag list shows for it, rather than
 * falling back to a plain colour dot.
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
