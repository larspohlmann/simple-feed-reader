<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * A tag as it appears embedded in one subscription row — deliberately a
 * narrower shape than {@see AdminUserTag}: no icon or position, because those
 * describe the tag itself, not this particular attachment.
 */
final readonly class AdminSubscriptionTag
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $color,
    ) {
    }
}
